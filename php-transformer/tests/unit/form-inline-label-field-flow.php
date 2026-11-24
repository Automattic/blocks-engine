<?php
declare(strict_types=1);

/**
 * A shadcn field (`<div class="space-y-1.5"><label class="text-sm
 * leading-none">…</label><input class="flex h-9 w-full"></div>`) leaves the
 * label inline: it sits on the wrapper's line, whose strut (16px × 1.5) makes
 * it 24px tall, and Tailwind v3's `space-y` puts 6px of margin-top on the
 * control through a sibling combinator. A provider field shell renders the
 * label as a 14px block and drops sibling-stacked control margins, so every
 * field came out 16px shorter. The label restates the strut as its
 * line-height and the control's block-start margin as its margin-block-end.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$css = 'html{line-height:1.5}'
    . '.space-y-4>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1rem * calc(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1rem * var(--tw-space-y-reverse))}'
    . '.space-y-1\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.375rem * calc(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.375rem * var(--tw-space-y-reverse))}'
    . '.text-sm{font-size:.875rem;line-height:1.25rem}.leading-none{line-height:1}.leading-8{line-height:2rem}.font-medium{font-weight:500}'
    . '.flex{display:flex}.h-9{height:2.25rem}.w-full{width:100%}.grid{display:grid}.block{display:block}';
$field = static fn (string $wrapper, string $label, string $id, string $name): string => '<div class="' . $wrapper . '"><label class="' . $label . '" for="' . $id . '">' . $name . '</label><input class="flex h-9 w-full" id="' . $id . '" name="' . $id . '"></div>';
$rows = static function (string $wrapper, string $label) use ($css, $field): array {
    $html = '<form method="post" class="space-y-4">' . $field($wrapper, $label, 'name', 'Full Name') . $field($wrapper, $label, 'email', 'Email') . '<button type="submit">Send</button></form>';
    $result = (new HtmlTransformer())->transform($html, array( 'static_css' => $css ))->toArray();
    $controls = $result['fallbacks'][0]['presentation_graph']['controls'] ?? array();
    return array_values(array_filter($controls, static fn (array $row): bool => $row['index'] < 2));
};

$inline = $rows('space-y-1.5', 'text-sm font-medium leading-none');
$assert(2 === count($inline), 'both fields have presentation rows', json_encode($inline));
foreach ( $inline as $row ) {
    $label = $row['label']['styles'] ?? array();
    $control = $row['control']['styles'] ?? array();
    $assert('24px' === ($label['line_height'] ?? null), 'an inline label keeps the wrapper strut as its line box', json_encode($label));
    $assert('calc(0.375rem * calc(1 - 0))' === ($label['margin_block_end'] ?? null), 'the sibling-stacked control margin becomes label margin-block-end', json_encode($label));
    $assert(! isset($control['margin_top']), 'the control no longer carries the margin a provider resets', json_encode($control));
    $assert(array() === array_filter($row['control']['provenance'], static fn (array $source): bool => in_array('margin-top', $source['properties'], true)), 'control provenance drops the moved margin-top');
}

// A grid wrapper blockifies the label: its own line-height is its box.
foreach ( $rows('grid', 'text-sm font-medium leading-none') as $row ) {
    $assert('1' === ($row['label']['styles']['line_height'] ?? null), 'a blockified label keeps its own line-height', json_encode($row['label']['styles'] ?? null));
    $assert(! isset($row['label']['styles']['margin_block_end']), 'no sibling margin moves without one', json_encode($row['label']['styles'] ?? null));
}

// A block label already is its own box.
foreach ( $rows('space-y-1.5', 'block text-sm font-medium leading-none') as $row ) {
    $assert('1' === ($row['label']['styles']['line_height'] ?? null), 'a block label keeps its own line-height', json_encode($row['label']['styles'] ?? null));
}

// A 20px label still sits on the 24px line; a 32px label sets its own line.
foreach ( $rows('space-y-1.5', 'text-sm font-medium') as $row ) {
    $assert('24px' === ($row['label']['styles']['line_height'] ?? null), 'a label shorter than the strut takes the strut', json_encode($row['label']['styles'] ?? null));
}
foreach ( $rows('space-y-1.5', 'text-sm font-medium leading-8') as $row ) {
    $assert('2rem' === ($row['label']['styles']['line_height'] ?? null), 'a label taller than the strut keeps its own line-height', json_encode($row['label']['styles'] ?? null));
}

if ( $failures > 0 ) {
    fwrite(STDERR, "Form inline label field flow tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Form inline label field flow tests: {$passes} passed\n");
