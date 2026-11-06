<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = <<<'HTML'
<style>
@layer base{*{margin:0;padding:0}}
@layer utilities{.flex{display:flex}.flex-wrap{flex-wrap:wrap}.gap-6{gap:1.5rem}.max-w-xl{max-width:36rem}.mx-auto{margin-left:auto;margin-right:auto}.px-6{padding-left:1.5rem;padding-right:1.5rem}}
</style>
<header><nav class="flex gap-6" aria-label="Primary"><a href="/one">One</a><a href="/two">Two</a></nav></header>
<main><nav class="max-w-xl mx-auto px-6" aria-label="On this page"><ul class="flex flex-wrap gap-6"><li><a href="#first">First</a></li><li><a href="#second">Second</a></li></ul></nav><h2 id="first">First</h2><h2 id="second">Second</h2></main>
HTML;
$result = ( new HtmlTransformer() )->transform($html)->toArray();
$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$css = implode("\n", array_column($result['assets'], 'content'));
$reset = 'margin-top:0;margin-right:0;margin-bottom:0;margin-left:0;padding-top:0;padding-right:0;padding-bottom:0;padding-left:0';
$assert(! str_contains($css, '.wp-block-navigation.flex{' . $reset . '}'), 'a shared utility must not own the box reset');
$assert(1 === preg_match('/\\.wp-block-navigation\\.(blocks-engine-navigation-instance-[a-f0-9]{12}-[0-9]+)\\{' . preg_quote($reset, '/') . '\\}/', $css, $match), 'the complete reset belongs to an instance marker');
// Inspect all emitted navigation attributes, including shared template parts.
$navigations = array();
$collect = static function (array $value) use (&$collect, &$navigations): void {
    if ( 'core/navigation' === ($value['blockName'] ?? '') ) {
        $navigations[] = $value['attrs']['className'] ?? '';
    }
    foreach ( $value as $child ) {
        if ( is_array($child) ) {
            $collect($child);
        }
    }
};
$collect($result);
$assert(array() !== $navigations, 'the fixture lowers to native navigation blocks');
$resetHosts = array_filter($navigations, static fn (string $classes): bool => in_array($match[1], explode(' ', $classes), true));
$assert(array() !== $resetHosts, 'the other navigation retains its reset marker');
$listHosts = array_filter($navigations, static fn (string $classes): bool => str_contains($classes, 'blocks-engine-list-navigation'));
$assert(array() !== $listHosts, 'the in-page list lowers to native navigation');
foreach ( $listHosts as $classes ) {
    $assert(! in_array($match[1], explode(' ', $classes), true), 'the reset selector cannot match the in-page navigation');
    foreach ( array('mx-auto', 'px-6', 'max-w-xl', 'flex') as $class ) {
        $assert(in_array($class, explode(' ', $classes), true), 'the in-page navigation retains ' . $class);
    }
}
$assert(str_contains($css, 'margin-left:auto;margin-right:auto'), 'layered centring survives');
$assert(str_contains($css, 'padding-left:1.5rem;padding-right:1.5rem'), 'layered inline padding survives');
echo "ok\n";
