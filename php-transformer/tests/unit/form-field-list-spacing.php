<?php
declare(strict_types=1);

/**
 * Authored vertical spacing between form field groups must survive
 * materialization. A shared field-list wrapper (flex/grid gap, including
 * cascade-layered utilities) is flattened by a provider form, so the gap has
 * to ride on the form node as stack spacing rather than disappearing with
 * the wrapper.
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

$transformer = new HtmlTransformer();

$html = '<form method="post" class="card">'
    . '<div class="fields">'
    . '<div class="field"><label for="name">Name</label><input id="name" name="name"></div>'
    . '<div class="field"><label for="email">Email</label><input id="email" type="email" name="email"></div>'
    . '<div class="field"><label for="message">Message</label><textarea id="message" name="message"></textarea></div>'
    . '</div>'
    . '<button type="submit" class="submit">Send</button>'
    . '</form>';

$layoutNodes = static function (array $result): array {
    return array_column($result['fallbacks'][0]['layout_graph']['nodes'] ?? array(), null, 'id');
};
$formGap = static function (array $nodes): string {
    $layout = is_array($nodes['form']['layout'] ?? null) ? $nodes['form']['layout'] : array();
    return (string) ( $layout['row_gap'] ?? $layout['gap'] ?? '' );
};
$labels = static function (array $result): array {
    $names = array();
    foreach ( $result['fallbacks'][0]['controls'] ?? array() as $control ) {
        if ( is_array($control) && isset($control['label']) && 'submit' !== ( $control['type'] ?? '' ) ) {
            $names[] = $control['label'];
        }
    }
    return $names;
};

$layeredCss = '@layer properties, theme, base, components, utilities;'
    . '@layer utilities { .card { background-color: #fff; padding: 16px } .fields { display: grid; gap: 24px } .field { display: flex; flex-direction: column; gap: 8px } .submit { margin-top: 36px } }';
$layered = $transformer->transform($html, array( 'static_css' => $layeredCss ))->toArray();
$layeredNodes = $layoutNodes($layered);
$layeredFallback = $layered['fallbacks'][0] ?? array();

$assert('html_form_fallback' === ($layeredFallback['diagnostic_code'] ?? null), 'layered field-list forms stay provider-materializable');
$assert('materialize_form_provider' === ($layeredFallback['suggested_repair_class'] ?? null), 'layered field-list forms still target a form provider');
$assert(array( 'Name', 'Email', 'Message' ) === $labels($layered), 'every authored field remains, in order', json_encode($labels($layered)));
$assert('24px' === $formGap($layeredNodes), 'layered field-list gap becomes form stack spacing a provider can apply', $formGap($layeredNodes) . ' ' . json_encode($layeredNodes['form']['layout'] ?? null));
$assert(str_contains((string) ($layered['serialized_blocks'] ?? ''), 'Name') && str_contains((string) ($layered['serialized_blocks'] ?? ''), 'Email') && str_contains((string) ($layered['serialized_blocks'] ?? ''), 'Message'), 'readable form markup still carries every field label');

$unlayeredCss = '.fields { display: grid; gap: 24px } .field { display: flex; flex-direction: column; gap: 8px }';
$unlayered = $transformer->transform($html, array( 'static_css' => $unlayeredCss ))->toArray();
$unlayeredNodes = $layoutNodes($unlayered);
$assert('24px' === $formGap($unlayeredNodes), 'unlayered field-list gap also becomes form stack spacing', $formGap($unlayeredNodes) . ' ' . json_encode($unlayeredNodes['form']['layout'] ?? null));

$rowGapCss = '@layer utilities { .fields { display: flex; flex-direction: column; row-gap: 1.5rem } }';
$rowGap = $transformer->transform($html, array( 'static_css' => $rowGapCss ))->toArray();
$assert('1.5rem' === $formGap($layoutNodes($rowGap)), 'layered row-gap is the same vertical stack spacing', $formGap($layoutNodes($rowGap)));

$soloCss = '@layer utilities { .row { display: flex; flex-direction: column; gap: 8px } }';
$soloHtml = '<form method="post"><div class="row"><label for="name">Name</label><input id="name" name="name"></div><button type="submit">Send</button></form>';
$solo = $transformer->transform($soloHtml, array( 'static_css' => $soloCss ))->toArray();
$assert('' === $formGap($layoutNodes($solo)), 'a single-field wrapper gap is not hoisted as inter-field stack spacing', $formGap($layoutNodes($solo)) . ' ' . json_encode($layoutNodes($solo)['form']['layout'] ?? null));

$inline = $transformer->transform('<form method="post"><div style="display:grid;gap:32px"><div><label>Name</label><input name="name"></div><div><label>Email</label><input type="email" name="email"></div></div><button type="submit">Send</button></form>')->toArray();
$assert('32px' === $formGap($layoutNodes($inline)), 'inline field-list gap becomes form stack spacing', $formGap($layoutNodes($inline)));

if ( $failures > 0 ) {
    fwrite(STDERR, "Form field-list spacing tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Form field-list spacing tests: {$passes} passed\n");
