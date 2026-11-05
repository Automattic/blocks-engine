<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// A "More" overflow toggle: a link declared as a button that opens the
// submenu, painting its own chevron as an SVG trailing its label. Its flex
// rule is the link's, not the inline-block list item's.
$source = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Nav</title><style>'
    . '.x .ib{display:inline-block}.x .pad{padding-left:32px}.x .navbox{display:block}'
    . '.x .menu{margin:0;padding:0;list-style:none;white-space:nowrap}'
    . '.x .toggle{display:flex;align-items:center}.x .drop{display:none}'
    . '</style></head><body><div class="x"><header><p class="ib">Site Title</p>'
    . '<nav class="navbox"><ul class="menu">'
    . '<li class="ib pad"><a href="/">Home</a></li><li class="ib pad"><a href="/team/">Team</a></li>'
    . '<li class="ib pad"><a class="toggle" href="#" role="button" aria-haspopup="menu"><span>More</span>'
    . '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M18.6 7l-6.8 7-6.4-7L4 8.5 11.8 17 20 8.5z"/></svg></a>'
    . '<ul class="drop"><li><a href="/">Home</a></li><li><a href="/team/">Team</a></li></ul></li>'
    . '</ul></nav></header><main><p>Body text.</p></main></div></body></html>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $result['assets'] ?? array()));

preg_match('/<!-- wp:navigation-submenu (\{.*?\}) -->/s', $markup, $submenuMatch);
$submenu = json_decode((string) ($submenuMatch[1] ?? ''), true) ?: array();
$assert('More' === ($submenu['label'] ?? null), 'The toggle lowers to a native submenu.');
$classes = preg_split('/\s+/', (string) ($submenu['className'] ?? '')) ?: array();
$assert(! in_array('toggle', $classes, true), 'The toggle\'s classes do not style the submenu item.');
$assert(in_array('ib', $classes, true) && in_array('pad', $classes, true), 'The item keeps its own classes.');
$assert('toggle' === ($submenu['metadata']['blocksEngineNavigationAnchor']['className'] ?? null), 'The toggle\'s classes belong to core\'s content.');
$assert(1 === preg_match('/:where\(\.wp-block-navigation-item__content\)[^{}]*\{display:flex;align-items:center\}/', $css), 'The toggle\'s flex rule reaches core\'s content.');
$assert(0 === preg_match('/\.wp-block-navigation-item:nth-child\(\d+\)(?::not\([^)]*\))*\{display:flex/', $css), 'The toggle\'s flex rule does not reach the item.');

preg_match('/<!-- wp:navigation (\{.*?\}) -->/s', $markup, $navigationMatch);
$navigation = json_decode((string) ($navigationMatch[1] ?? ''), true) ?: array();
$assert(false !== ($navigation['showSubmenuIcon'] ?? true), 'The toggle\'s own chevron keeps core\'s submenu indicator.');
$assert(1 === preg_match('/blocks-engine-submenu-icon-[a-f0-9]+/', (string) ($submenu['className'] ?? ''), $marker), 'The chevron is recognized as the source submenu indicator.');
$assert(1 === preg_match('/' . $marker[0] . '[^{}]*>\.wp-block-navigation__submenu-icon>svg\{([^}]+)\}/', $css, $glyph)
    && str_contains($glyph[1], 'width:16px;') && str_contains($glyph[1], 'height:16px;'), 'Core\'s icon is sized from the source chevron.');

echo "navigation anchor submenu toggle contract passed\n";
