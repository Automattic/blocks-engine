<?php
declare(strict_types=1);

/**
 * `!important` changes cascade precedence, not the display a box uses. A
 * container laid out by `display:flex!important` (Bootstrap's `.d-flex`) is
 * author-owned flex layout exactly like `display:flex`, so its empty flex
 * items keep the main-axis space they reserve.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$groupClasses = static function (string $display): array {
    $html = '<html><head><style>'
        . '.row-host{display:' . $display . '}'
        . '.side{flex:0 0 auto;width:16.66666667%}'
        . '.middle{width:100%}'
        . '</style></head><body><main><div class="row-host">'
        . '<div class="side"></div><div class="middle"><h2>Quote</h2></div><div class="side"></div>'
        . '</div></main></body></html>';
    $blocks = (string) (( new HtmlTransformer() )->transform($html, array())->toArray()['serialized_blocks'] ?? '');
    preg_match_all('/<div class="(wp-block-group [^"]*)"/', $blocks, $matches);
    return $matches[1];
};

$plain = $groupClasses('flex');
$important = $groupClasses('flex!important');
$spaced = $groupClasses('flex !important');

$sideClasses = static fn (array $classes): array => array_values(array_filter($classes, static fn (string $class): bool => 1 === preg_match('/(?:^| )side(?: |$)/', $class)));

$assert(2 === count($sideClasses($plain)), 'display:flex keeps both empty flex spacers', implode(' | ', $plain));
$assert(2 === count($sideClasses($important)), 'display:flex!important keeps both empty flex spacers', implode(' | ', $important));
$assert(2 === count($sideClasses($spaced)), 'display:flex !important keeps both empty flex spacers', implode(' | ', $spaced));
$assert($plain === $important && $plain === $spaced, 'the !important flag does not change the lowered layout', implode(' | ', $important));
foreach ( $sideClasses($important) as $class ) {
    $assert(str_contains($class, 'blocks-engine-empty-visual-group'), 'a retained spacer is marked as an empty visual boundary', $class);
}

if ( 0 < $failures ) {
    fwrite(STDERR, "important display author layout FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "important display author layout passed: {$passes} assertions\n";
