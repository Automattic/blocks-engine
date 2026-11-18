<?php
declare(strict_types=1);

/**
 * A role="button" anchor that opens a captured dialog stays an editable native
 * control after dialog projection rebinds it.
 *
 * The projector points the trigger's `aria-controls` at the native dialog it
 * builds and gives the trigger the id that dialog's
 * `data-blocks-engine-triggers` names, so the capture key no longer equals
 * `aria-controls`. The anchor then stopped counting as a captured dialog
 * trigger: when a page script addressed it through a data attribute
 * (`[data-hook="cart"]`) it was saved as one core/html island with no fallback
 * diagnostic, and without such a script it lost its button control.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = (string) file_get_contents(dirname(__DIR__) . '/fixtures/projected-dialog-trigger-anchor.html');
$failures = array();
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};
$find = static function (array $blocks, string $name) use (&$find): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            $found[] = $block;
        }
        array_push($found, ...$find($block['innerBlocks'] ?? array(), $name));
    }
    return $found;
};

foreach ( array(
    'script-addressed trigger' => $source,
    'unaddressed trigger' => (string) preg_replace('#<script>.*?</script>#s', '', $source),
) as $case => $html ) {
    $result = ( new ArtifactCompiler() )->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $check(1 === (int) ($result['source_reports']['captured_interactions']['projected_dialog_count'] ?? 0), $case . ': the captured panel is projected as a native dialog');
    $check(array() === $find($result['blocks'] ?? array(), 'core/html'), $case . ': no core/html block is emitted: ' . $markup);

    $buttons = array_values(array_filter(
        $find($result['blocks'] ?? array(), 'custom/authored-button'),
        static fn (array $block): bool => 'a' === ($block['attrs']['tagName'] ?? '')
    ));
    $check(1 === count($buttons), $case . ': the trigger anchor is one editable native button control: ' . $markup);
    $button = $buttons[0] ?? array();
    $attributes = array_column($button['attrs']['sourceAttributes'] ?? array(), 'value', 'name');
    $triggerId = (string) ($button['attrs']['id'] ?? '');
    $dialogId = (string) ($attributes['aria-controls'] ?? '');
    $check(str_starts_with($triggerId, 'blocks-engine-dialog-trigger-'), $case . ': the trigger keeps the id the projected dialog binds to');
    $check(str_starts_with($dialogId, 'blocks-engine-dialog-') && str_contains($markup, 'id="' . $dialogId . '"'), $case . ': the trigger controls the projected dialog');
    $check(str_contains($markup, '"data-blocks-engine-triggers":"' . $triggerId . '"'), $case . ': the projected dialog names the trigger');
    $check('cart-panel' === ($attributes['data-blocks-engine-capture-dialog-trigger'] ?? null) && 'cart' === ($attributes['data-hook'] ?? null) && '/cart/' === ($attributes['href'] ?? null), $case . ': the trigger keeps its capture binding, script hook and link');
    $check(str_contains(json_encode($button['attrs']['contentParts'] ?? array(), JSON_UNESCAPED_SLASHES) ?: '', '<text'), $case . ': the icon keeps its SVG artwork and count text');
    $check('Cart with 0 items' === ($button['attrs']['ariaLabel'] ?? null), $case . ': the trigger keeps its accessible name');
}

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "projected-dialog-trigger-anchor: ok\n";
