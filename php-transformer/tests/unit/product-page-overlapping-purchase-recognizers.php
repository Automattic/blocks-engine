<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = '<html><head><script type="application/ld+json">{"@type":"Product","name":"Ring","offers":{"@type":"Offer","price":1,"priceCurrency":"USD"}}</script></head><body><main><article><h1>Ring</h1><span>$1.00</span><div><button>Add to cart</button><input name="qty"><button>Subscribe</button></div></article></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'products/ring.html', 'files' => array('products/ring.html' => $html)))->toArray();
$errors = array_filter($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'error' === ($diagnostic['severity'] ?? ''));
$assert(array() === $errors, 'No site-plan errors: ' . json_encode($errors));
$assert('failed' !== $result['status'], 'Overlapping purchase recognizers must not fail compilation.');
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$bindings = array();
foreach ($plan['runtime_declarations'] ?? array() as $declaration) {
    if ('products' !== ($declaration['type'] ?? null)) continue;
    foreach ($declaration['payload']['entities'] ?? array() as $entity) {
        foreach ($entity['bindings'] ?? array() as $binding) {
            if ('commerce_add_to_cart_form' === ($binding['role'] ?? null)) $bindings[] = $binding;
        }
    }
}
$assert(1 === count($bindings), 'One purchase placement has exactly one binding across recognizers.');
$binding = $bindings[0];
$assert('products/ring.html' === $binding['source_path'], 'The binding names its source page.');
$assert('' !== trim($binding['search_block_markup']), 'The purchase anchor is nonempty.');
$pages = array_column($plan['pages'] ?? array(), null, 'source_path');
$assert(str_contains($pages['products/ring.html']['canonical_block_markup'] ?? '', $binding['search_block_markup']), 'The purchase anchor occurs in canonical markup.');

echo "product-page-overlapping-purchase-recognizers: ok\n";
