<?php
declare(strict_types=1);

/**
 * A runtime entity binding whose block is not emitted (its markup is absent
 * from the page, or its page is not in the plan) has nothing to bind. The
 * binding is dropped, the block stays static, and the plan records a
 * `runtime_entity_binding_unanchored` finding instead of failing. Bindings
 * that still anchor are kept, an ambiguous anchor still fails closed, and the
 * strict validator still rejects a plan that declares an unanchored binding.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$html = '<!doctype html><html><body><main><h1>Shop</h1><p>Kept paragraph</p><p>Price 10</p></main></body></html>';
$present = '<!-- wp:paragraph --><p>Price 10</p><!-- /wp:paragraph -->';
$binding = static fn (string $markup, string $sourcePath = 'index.html', string $role = 'price'): array => array('schema' => 'generic/block-binding/v1', 'role' => $role, 'source_path' => $sourcePath, 'search_block_markup' => $markup, 'occurrence' => 1);
$artifact = static fn (array $bindings): array => array('entrypoint' => 'index.html', 'files' => array('index.html' => $html), 'runtime_declarations' => array(array(
    'kind' => 'entity_collection', 'type' => 'products', 'source_path' => 'data/products.json',
    'payload' => array('schema' => 'generic/products/v1', 'entities' => array(array('id' => 'product-1', 'bindings' => $bindings))),
)));
$bindingsOf = static function (array $plan): array {
    foreach (RuntimeDeclarations::materialize($plan['runtime_declarations'], $plan['runtime_records'] ?? array()) as $declaration) if ('products' === ($declaration['type'] ?? null)) return $declaration['payload']['entities'][0]['bindings'];
    return array();
};
$findings = static fn (array $plan): array => array_values(array_filter($plan['diagnostics'], static fn (array $row): bool => 'runtime_entity_binding_unanchored' === ($row['code'] ?? null)));

foreach (array(
    'a block the page does not emit' => array($binding('<!-- wp:paragraph --><p>Price 99</p><!-- /wp:paragraph -->', 'index.html', 'sale'), 'index.html', 'anchor_not_emitted'),
    'a page the plan does not emit' => array($binding($present, 'about.html', 'sale'), 'about.html', 'document_not_emitted'),
) as $label => list($unanchored, $sourcePath, $reason)) {
    $result = ( new ArtifactCompiler() )->compile($artifact(array($binding($present), $unanchored)))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? null;
    $assert(is_array($plan) && 'failed' !== $result['status'] && !isset($result['source_reports']['wordpress_site_plan_diagnostics']), "A binding to {$label} no longer fails the site plan.");
    WordPressSitePlan::assertValid($plan);
    $kept = $bindingsOf($plan);
    $assert(1 === count($kept) && 'price' === $kept[0]['role'] && $present === $kept[0]['search_block_markup'] && WordPressSitePlan::bindingPosition($kept[0]['position'] ?? null, $plan['pages'][0]['canonical_block_markup'], $present), "The anchored sibling binding is kept beside a binding to {$label}.");
    $assert(str_contains($plan['pages'][0]['canonical_block_markup'], 'Kept paragraph'), "The page stays static and complete beside a binding to {$label}.");
    $found = $findings($plan);
    $assert(1 === count($found) && 'warning' === $found[0]['severity'] && 'binding' === $found[0]['unit'] && $reason === $found[0]['reason_code'] && $sourcePath === $found[0]['source_path'] && 'product-1' === $found[0]['entity_id'] && 'sale' === $found[0]['role'] && 'data/products.json' === $found[0]['declaration_source_path'], "The dropped binding to {$label} is reported once: " . json_encode($found));
    $assert(in_array('runtime_entity_binding_unanchored', $plan['reporting']['diagnostic_codes'], true), "The {$label} finding is linked to plan reporting.");
    $assert('success_with_warnings' === $plan['quality']['status'], "The {$label} finding raises the plan status to success_with_warnings.");
}

// The validator is still the backstop: a plan that declares an unanchored
// binding is rejected.
$plan = ( new ArtifactCompiler() )->compile($artifact(array($binding($present))))->toArray()['source_reports']['wordpress_site_plan'];
$declarations = RuntimeDeclarations::materialize($plan['runtime_declarations'], $plan['runtime_records'] ?? array());
foreach ($declarations as &$declaration) if ('products' === ($declaration['type'] ?? null)) {
    $declaration['payload']['entities'][0]['bindings'][0]['search_block_markup'] = '<!-- wp:paragraph --><p>Price 99</p><!-- /wp:paragraph -->';
    unset($declaration['payload_hash'], $declaration['content_hash']);
}
unset($declaration);
$plan['runtime_declarations'] = RuntimeDeclarations::normalizeList($declarations);
$plan['runtime_records'] = array();
$rejected = false;
try {
    WordPressSitePlan::assertValid($plan);
} catch ( InvalidArgumentException $error ) {
    $rejected = str_contains($error->getMessage(), 'runtime entity binding');
}
$assert($rejected, 'The strict plan validator still rejects a declared unanchored binding.');

echo "unanchored-runtime-entity-binding contract passed\n";
