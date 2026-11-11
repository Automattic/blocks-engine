<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// Squarespace hover menus clip each padded submenu list inside a zero-height
// wrapper, so a closed menu takes no space. Core's single submenu list absorbs
// both boxes; while closed it must keep that zero footprint, or the hidden
// list's padding and width overflow the page. A wrapper that does not clip
// leaves core's closed state alone.
$page = static fn (string $wrapper): string => '<!doctype html><html><head><style>'
    . '#top nav ul{width:auto}#top nav ul li.folder{position:relative}'
    . '#top nav ul li.folder .subnav{position:absolute;top:100%;left:-22px;min-width:100%;' . $wrapper . '}'
    . '#top nav ul li.folder ul{padding:.5em 0}'
    . '#top nav ul li.folder:hover .subnav,#top nav ul li.folder:focus .subnav{height:auto !important;display:block}'
    . '</style></head><body><header><div id="top"><nav class="main-nav"><ul><li><a href="/">Home</a></li>'
    . '<li class="folder"><a aria-haspopup="true" href="#">Get support</a><div class="subnav"><ul><li><a href="/ticket/">Submit a ticket</a></li><li><a href="/contact/">Contact us</a></li></ul></div></li>'
    . '</ul></nav></div></header><main><h1>Home</h1></main></body></html>';
$closedRule = '.blocks-engine-clipped-submenu:not(:hover,:focus-within,.is-menu-open *)>.wp-block-navigation__submenu-container';
foreach ( array( 'clipped wrapper' => array( 'height:0;overflow:hidden', true ), 'unclipped wrapper' => array( 'display:none', false ) ) as $case => [$wrapper, $clipped] ) {
    $result = (new HtmlTransformer())->transform($page($wrapper))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($result['assets'] ?? array(), static fn (mixed $asset): bool => is_array($asset) && 'css' === ($asset['kind'] ?? ''))));
    $assert(1 === preg_match('/<!-- wp:navigation-submenu \{"className":"([^"]*)"/', $markup, $match), "{$case}: lowers to a native submenu.");
    $assert($clipped === str_contains($match[1], 'blocks-engine-clipped-submenu'), "{$case}: the submenu is " . ($clipped ? '' : 'not ') . 'marked as clipped at rest.');
    $assert($clipped === str_contains($css, $closedRule), "{$case}: the closed-state footprint rule is " . ($clipped ? 'emitted' : 'absent') . '.');
}

echo "navigation clipped submenu closed footprint passed\n";
