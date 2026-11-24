<?php
declare(strict_types=1);

/**
 * A shadcn/Tailwind field: `<div class="space-y-1.5"><label>…</label><input class="flex …"></div>`.
 * The label is inline, so it sits on a line box as tall as the wrapper's
 * line height, and the sibling rule `.space-y-1.5>*~*` spaces the input below
 * it. A provider field flattens the wrapper into one shell with a block label,
 * where neither survives: the sibling rule no longer reaches the input and the
 * label's lines shrink to its own `leading-none`. The presentation graph must
 * state both on the label (the strut's extra leading as block padding, leaving
 * the label's own line height alone), and keep the gap off the control.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$fail = static function (string $message) use (&$failures): void {
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$css = 'html{line-height:1.5}body{margin:0;line-height:inherit}'
    . '@layer utilities{'
    . '.space-y-4>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1rem * calc(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1rem * var(--tw-space-y-reverse))}'
    . '.space-y-1\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.375rem * calc(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.375rem * var(--tw-space-y-reverse))}'
    . '.text-sm{font-size:.875rem;line-height:1.25rem}.leading-none{line-height:1}.h-9{height:2.25rem}.flex{display:flex}.w-full{width:100%}}';
$field = static fn (string $id, string $label, string $type): string => '<div class="space-y-1.5"><label class="text-sm leading-none" for="' . $id . '">' . $label . '</label>'
    . '<input type="' . $type . '" class="flex h-9 w-full" id="' . $id . '"></div>';
$html = '<html><head><link rel="stylesheet" href="styles.css"></head><body><main><form class="space-y-4">'
    . $field('name', 'Full Name', 'text') . $field('email', 'Email', 'email')
    . '<button type="submit">Send</button></form></main></body></html>';
$result = (new ArtifactCompiler())->compile(array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html, 'styles.css' => $css ) ))->toArray();

$graph = null;
foreach ( $result['fallbacks'] ?? array() as $fallback ) {
    if ( is_array($fallback['presentation_graph']['controls'] ?? null) ) {
        $graph = $fallback['presentation_graph'];
        break;
    }
}
if ( null === $graph ) {
    fwrite(STDERR, "FAIL: the form carries no presentation graph\n");
    exit(1);
}
$rows = array_column($graph['controls'], null, 'index');
foreach ( array( 0 => 'Full Name', 1 => 'Email' ) as $index => $name ) {
    $label = $rows[$index]['label']['styles'] ?? array();
    $control = $rows[$index]['control']['styles'] ?? array();
    if ( 'calc(.375rem * calc(1 - 0))' !== ( $label['margin_block_end'] ?? null ) ) {
        $fail($name . ' label does not keep the sibling gap above its control: ' . json_encode($label));
    }
    // The 24px strut around a 14px label line: 5px of leading on each side.
    if ( '5px' !== ( $label['padding_block_start'] ?? null ) || '5px' !== ( $label['padding_block_end'] ?? null ) ) {
        $fail($name . ' label does not keep its inline line box (the wrapper strut): ' . json_encode($label));
    }
    // Its own typography stays the source's: the text line is still 1.
    if ( '1' !== ( $label['line_height'] ?? null ) ) {
        $fail($name . ' label had its own line height replaced: ' . json_encode($label));
    }
    if ( '.875rem' !== ( $label['font_size'] ?? null ) ) {
        $fail($name . ' label lost its own type: ' . json_encode($label));
    }
    foreach ( array( 'margin_top', 'margin_bottom', 'margin_block_start' ) as $property ) {
        if ( isset($control[$property]) ) {
            $fail($name . ' control still carries the label gap as ' . $property . ': ' . json_encode($control));
        }
    }
    if ( '2.25rem' !== ( $control['height'] ?? null ) ) {
        $fail($name . ' control lost its own presentation: ' . json_encode($control));
    }
    $selectors = array_column($rows[$index]['label']['provenance'] ?? array(), 'properties', 'selector');
    if ( array( 'margin-block-end' ) !== ( $selectors['.space-y-1\\.5>:not([hidden])~:not([hidden])'] ?? null ) || array( 'padding-block-end', 'padding-block-start' ) !== ( $selectors['html'] ?? null ) ) {
        $fail($name . ' label provenance does not prove the carried facts: ' . json_encode($rows[$index]['label']['provenance'] ?? null));
    }
}

// The submit is a direct child of the stacked form: its sibling margin is the
// inter-field stack, not a label gap, and stays on the control.
$submit = $rows[2]['control']['styles'] ?? array();
if ( 'calc(1rem * calc(1 - 0))' !== ( $submit['margin_top'] ?? null ) ) {
    $fail('the submit lost its stacking margin: ' . json_encode($submit));
}

// A block label already owns its own line box; nothing is restated.
$blockCss = $css . '@layer utilities{.block{display:block}}';
$blockHtml = str_replace('class="text-sm leading-none"', 'class="block text-sm leading-none"', $html);
$block = (new ArtifactCompiler())->compile(array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $blockHtml, 'styles.css' => $blockCss ) ))->toArray();
foreach ( $block['fallbacks'] ?? array() as $fallback ) {
    foreach ( $fallback['presentation_graph']['controls'] ?? array() as $row ) {
        if ( isset($row['label']) && ( '1' !== ( $row['label']['styles']['line_height'] ?? null ) || isset($row['label']['styles']['padding_block_start']) ) ) {
            $fail('a block label had its line box restated: ' . json_encode($row['label']['styles']));
        }
    }
}

if ( $failures > 0 ) {
    exit(1);
}
echo 'PASS: inline field labels keep their line box and sibling gap' . PHP_EOL;
