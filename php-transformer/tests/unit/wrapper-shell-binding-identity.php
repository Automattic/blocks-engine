<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;

// Neutral header from #6173. The sized logo box requires a retained link shell.
$html = file_get_contents(__DIR__ . '/wrapper-shell-binding-identity.html');
$compilation = new HtmlCompilation();
$result = $compilation->transform($html)->toArray();
$markup = $result['serialized_blocks'];
// Resolve explicit anchor records against the same neutral tree, including
// the drawer checkout, so disjoint claims are tested independently of reporting.
$finalizer = (new ReflectionProperty($compilation, 'fallbackBindings'))->getValue($compilation);
$session = (new ReflectionProperty($compilation, 'session'))->getValue($compilation);
$blocks = $result['blocks'];
// Use source-anchor identities on leaf blocks, without dependence on private
// provenance IDs stripped from the public result.
$anchor = static function (array &$siblings) use (&$anchor): void {
    foreach ($siblings as &$block) {
        foreach (array('cart-link', 'checkout') as $id) {
            if (str_contains($block['innerHTML'] ?? '', 'id="' . $id . '"')) $block['_source_anchor_selector'] = '#' . $id;
        }
        $anchor($block['innerBlocks']);
    }
};
$anchor($blocks);
$bindings = array_map(static fn (string $id): array => array('binding' => array('role' => 'commerce_cart_link', '_binding_anchor_selector' => '#' . $id)), array('cart-link', 'checkout'));
$finalizer->finalize($bindings, $blocks, $markup);
foreach ($bindings as $i => $finding) {
    if (!str_contains($finding['binding']['search_block_markup'] ?? '', 'id="' . array('cart-link', 'checkout')[$i] . '"')) throw new RuntimeException('Binding must retain its own anchor.');
}
$a = $bindings[0]['binding']['position']; $b = $bindings[1]['binding']['position'];
if ($a['offset'] + $a['length'] > $b['offset']) throw new RuntimeException('Cart claims must not overlap.');

// Lost child: all later ordinals shift. Decline even if an ordinal still exists.
$broken = $blocks;
$dropChildSlot = static function (array &$siblings) use (&$dropChildSlot): void {
    foreach ($siblings as &$block) {
        if (str_ends_with($block['blockName'] ?? '', '/layout-shell')) {
            $block['innerContent'] = array('');
            $block['innerHTML'] = '';
            return;
        }
        $dropChildSlot($block['innerBlocks']);
    }
};
$dropChildSlot($broken);
$runtime = new \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime();
$brokenMarkup = $runtime->serializeBlocks($broken);
$missingIdentity = $blocks;
$removeIdentity = static function (array &$siblings) use (&$removeIdentity): void {
    foreach ($siblings as &$block) {
        $block['innerHTML'] = str_replace('id="cart-link"', 'id="other-link"', $block['innerHTML']);
        foreach ($block['innerContent'] as &$part) {
            if (is_string($part)) $part = str_replace('id="cart-link"', 'id="other-link"', $part);
        }
        unset($part);
        $removeIdentity($block['innerBlocks']);
    }
};
$removeIdentity($missingIdentity);
foreach (array('block_count_mismatch', 'anchor_identity_missing') as $reason) {
    $test = array(array('binding' => array('role' => 'commerce_cart_link', '_binding_anchor_selector' => '#cart-link')));
    $testBlocks = 'block_count_mismatch' === $reason ? $broken : $missingIdentity;
    $testMarkup = 'block_count_mismatch' === $reason ? $brokenMarkup : $runtime->serializeBlocks($missingIdentity);
    $finalizer->finalize($test, $testBlocks, $testMarkup);
    if (array() !== $test[0]['binding']) throw new RuntimeException('Unsafe binding must be declined: ' . $reason);
    $diagnostics = $session->transformationEvidenceState()->conversionDiagnostics();
    if ($reason !== ($diagnostics[array_key_last($diagnostics)]['context']['reason'] ?? null)) throw new RuntimeException('Decline requires a diagnostic: ' . $reason);
}
echo "wrapper-shell-binding-identity: ok\n";
