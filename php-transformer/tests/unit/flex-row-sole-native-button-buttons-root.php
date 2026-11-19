<?php
declare(strict_types=1);

/**
 * A flex row whose only content is one native `<button>` is the native
 * core/buttons flex container itself: the row's box (margin, gap, alignment)
 * becomes the core/buttons root instead of a Group around a synthesized,
 * display:contents core/buttons. That removes one block level per row
 * without changing the box tree. Rows that are not unconditional static
 * flex boxes, or that carry identity of their own, keep their Group.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};

$page = static fn (string $rowCss, string $rowAttributes = ''): string => '<!DOCTYPE html><html><head><style>'
    . '.item{display:block}.row{' . $rowCss . '}'
    . '.btn{display:inline-flex;align-items:center;padding:12px 18px;border:1px solid #0f3d2e;background:#0f3d2e;color:#f6efe0;cursor:pointer}'
    . '</style></head><body><div class="item"><p class="price">RM 20.90</p>'
    . '<div class="row"' . $rowAttributes . '><button type="button" class="btn">Add to Order</button></div></div></body></html>';

/** @return list<array<string, mixed>> */
$find = static function (array $blocks, string $name) use (&$find): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? null) ) $found[] = $block;
        $found = array_merge($found, $find($block['innerBlocks'] ?? array(), $name));
    }
    return $found;
};
$classes = static fn (array $block): array => preg_split('/\s+/', trim((string) ($block['attrs']['className'] ?? ''))) ?: array();

$flex = 'display:flex;align-items:center;gap:12px;margin-top:14px';
$result = ( new HtmlTransformer() )->transform($page($flex))->toArray();
$buttons = $find($result['blocks'] ?? array(), 'core/buttons');
$rowGroups = array_filter($find($result['blocks'] ?? array(), 'core/group'), static fn (array $block): bool => in_array('row', $classes($block), true));
$assert(array() === $rowGroups, 'the flex row is not kept as a Group around the button');
$assert(1 === count($buttons) && in_array('row', $classes($buttons[0]), true), 'the core/buttons root carries the row');
$assert(1 === count($buttons) && ! in_array('blocks-engine-layout-neutral-buttons', $classes($buttons[0]), true), 'the core/buttons root generates the row box instead of display:contents');
$button = $buttons[0]['innerBlocks'][0] ?? array();
$assert('core/button' === ($button['blockName'] ?? null) && 'button' === ($button['attrs']['tagName'] ?? null) && str_contains((string) ($button['attrs']['text'] ?? ''), 'Add to Order'), 'the native button stays one editable core/button');
$assert(str_contains((string) ($result['serialized_blocks'] ?? ''), 'class="wp-block-buttons row'), 'the saved root keeps the row class for its author rules');

// The row only stands in for core/buttons where it is flex at every breakpoint,
// static, and has no identity of its own.
foreach ( array(
    'a block row' => $page('display:block;margin-top:14px'),
    'a row that is flex only above a breakpoint' => $page('display:block') . '<style>@media (min-width:640px){.row{display:flex}}</style>',
    'a positioned row' => $page($flex . ';position:relative;top:4px'),
    'a row with an id' => $page($flex, ' id="order"'),
) as $label => $html ) {
    $blocks = ( new HtmlTransformer() )->transform($html)->toArray()['blocks'] ?? array();
    $kept = array_filter($find($blocks, 'core/group'), static fn (array $block): bool => in_array('row', $classes($block), true));
    $assert(1 === count($kept), $label . ' keeps its Group');
}

fwrite(STDOUT, sprintf('flex-row-sole-native-button-buttons-root: %d failed%s', $failures, PHP_EOL));
exit($failures > 0 ? 1 : 0);
