<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
// A captured page carries one copy of its purchase controls per device
// document. When the capture pairs the cart controls as responsive
// counterparts, the copy that alone lacks product evidence is still a purchase
// placement and must get its own runtime binding, not a static button. An
// empty option form beside the controls does not stop the binding from
// owning the copy's quantity input.
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'store/stand-model.html',
    'files' => array('store/stand-model.html' => file_get_contents(dirname(__DIR__) . '/fixtures/product-page-device-counterpart-purchase.html')),
))->toArray();
$findings = array_values(array_filter(
    $result['source_reports']['conversion_report']['fallback_diagnostics'] ?? array(),
    static fn (array $finding): bool => 'html_commerce_controls_fallback' === ($finding['diagnostic_code'] ?? null)
));
$controls = array_map(static fn (array $finding): string => json_encode($finding['controls'] ?? array()), $findings);
$assert(2 === count($findings), 'Each device copy needs a purchase finding, got ' . count($findings) . ': ' . json_encode(array_column($findings, 'selector')));
$assert(1 === count(array_filter($controls, static fn (string $c): bool => str_contains($c, 'qty-desktop'))) && 1 === count(array_filter($controls, static fn (string $c): bool => str_contains($c, 'qty-mobile'))), 'One finding per device copy: ' . json_encode($controls));
$anchors = array();
foreach ($result['fallbacks'] as $fallback) {
    if ('html_commerce_controls_fallback' === ($fallback['diagnostic_code'] ?? null) && 'commerce_add_to_cart_form' === ($fallback['binding']['role'] ?? null)) {
        $anchors[] = (string) ($fallback['binding']['search_block_markup'] ?? '');
    }
}
$assert(2 === count($anchors), 'Each device copy carries a purchase binding, got ' . count($anchors) . '.');
foreach (array('qty-desktop', 'qty-mobile') as $quantity) {
    $owned = array_values(array_filter($anchors, static fn (string $anchor): bool => str_contains($anchor, $quantity)));
    $assert(1 === count($owned) && str_contains($owned[0], 'Add to Cart') && !str_contains($owned[0], 'Stand Model'), $quantity . ': the binding owns its quantity and cart control, not the product details.');
}
echo "product-page-device-counterpart-purchase: ok\n";
