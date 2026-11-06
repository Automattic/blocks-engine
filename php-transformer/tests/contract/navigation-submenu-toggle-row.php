<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// A "More" overflow toggle in an inline-block list item: the link (inside
// two wrapper boxes) lays out its label and a trailing SVG chevron in one
// flex row. Core replaces the chevron with its submenu toggle, a sibling of
// the content inside the item, so the item must stay Core's flex row or the
// chevron stacks below the label.
$source = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Nav</title><style>'
    . '.x .ib{display:inline-block}.x .pad{padding-left:32px}.x .navbox{display:block}'
    . '.x .menu{margin:0;padding:0;list-style:none;white-space:nowrap}'
    . '.x .toggle{display:flex;align-items:center}.x .drop{display:none}'
    . '</style></head><body><div class="x"><header><p class="ib">Site Title</p>'
    . '<nav class="navbox"><ul class="menu">'
    . '<li class="ib pad"><a href="/">Home</a></li><li class="ib pad"><a href="/team/">Team</a></li>'
    . '<li class="ib pad"><div><span><a class="toggle" href="#" role="button" aria-haspopup="menu">'
    . '<div style="display:flex;align-items:center"><span style="margin-right:4px">More</span>'
    . '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M18.6 7l-6.8 7-6.4-7L4 8.5 11.8 17 20 8.5z"/></svg></div></a></span></div>'
    . '<ul class="drop"><li><a href="/">Home</a></li><li><a href="/team/">Team</a></li></ul></li>'
    . '</ul></nav></header><main><p>Body text.</p></main></div></body></html>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $result['assets'] ?? array()));

$assert(1 === preg_match('/<!-- wp:navigation-submenu \{[^\n]*blocks-engine-submenu-icon-[a-f0-9]+/', $markup), 'Core\'s toggle stands in for the source chevron.');
$item = '\.wp-block-navigation__container>\.wp-block-navigation-item:nth-child\(3\)';
$assert(1 === preg_match('/' . $item . '[^{},]*\{display:inline-flex\}/', $css, $row, PREG_OFFSET_CAPTURE), 'The toggle item keeps its inline box as Core\'s row, so the chevron stays beside the label.');
$assert(1 === preg_match('/nth-child\(-n\+3\)[^{}]*\{display:inline-block\}/', $css, $authored, PREG_OFFSET_CAPTURE) && $authored[0][1] < $row[0][1], 'The row follows the item\'s authored display rule at its cascade position.');
$assert(0 === preg_match('/:nth-child\([12]\)[^{},]*\{display:inline-flex\}/', $css), 'Items without a toggle keep their authored display.');

echo "navigation submenu toggle row contract passed\n";
