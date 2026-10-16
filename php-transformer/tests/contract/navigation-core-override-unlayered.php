<?php
declare(strict_types=1);

/**
 * List-navigation display restatements override Core's unlayered navigation
 * CSS (`.wp-block-navigation .wp-block-navigation-item{display:flex}`), so they
 * must stay outside the engine reset layer even when the author CSS is layered
 * (#2915). The item font reset yields to author typography and stays layered.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$html = '<style>@layer base,utilities;@layer utilities{.flex{display:flex}.flex-wrap{flex-wrap:wrap}.gap-6{gap:1.5rem}.text-sm{font-size:.875rem;line-height:1.25rem}}</style>'
    . '<main>Home</main><footer><nav><ul class="flex flex-wrap gap-6"><li><a class="text-sm" href="/a">A</a></li><li><a class="text-sm" href="/b">B</a></li></ul></nav></footer>';
$result = ( new HtmlTransformer() )->transform($html)->toArray();
$css = implode("\n", array_map(static fn (array $a): string => 'css' === ($a['kind'] ?? '') ? (string) ($a['content'] ?? '') : '', $result['assets'] ?? array()));
$assert(str_contains($css, '@layer blocks-engine-reset{'), 'layered author CSS puts the engine resets in their layer');

// Split the stylesheet into the engine reset layer's contents and the rest.
$layered = '';
$unlayered = '';
$offset = 0;
while ( false !== ( $start = strpos($css, '@layer blocks-engine-reset{', $offset) ) ) {
    $unlayered .= substr($css, $offset, $start - $offset);
    $depth = 0;
    for ( $i = $start + strlen('@layer blocks-engine-reset'); $i < strlen($css); ++$i ) {
        if ( '{' === $css[$i] ) ++$depth;
        if ( '}' === $css[$i] && 0 === --$depth ) break;
    }
    $layered .= substr($css, $start, $i + 1 - $start);
    $offset = $i + 1;
}
$unlayered .= substr($css, $offset);

$item = '.wp-block-navigation:where(.blocks-engine-list-navigation) .wp-block-navigation-item{display:list-item}';
$anchor = '.wp-block-navigation:where(.blocks-engine-list-navigation) .wp-block-navigation-item__content{display:inline}';
$assert(str_contains($unlayered, $item) && ! str_contains($layered, $item), 'the list item display restatement stays unlayered, so it outranks Core\'s flex item');
$assert(str_contains($unlayered, $anchor) && ! str_contains($layered, $anchor), 'the anchor display restatement stays unlayered, so it outranks Core\'s block content');
$assert(str_contains($layered, '{font:inherit}'), 'the item font reset stays in the engine layer, so layered author typography wins');
echo "ok\n";
