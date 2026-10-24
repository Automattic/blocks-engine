<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// The four-block header shifts raw block indexes by exactly one card's
// repeat period. Index pairing therefore mistakes occurrence N for N+1.
$header = '<header class="site-header"><div class="brand"><p>Brand</p></div><p class="tag">Tagline</p></header>';
$card = '<article class="card"><h3>Headphones</h3><p>$299</p><button class="btn" data-action="add-to-cart">Add to Cart</button></article>';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $header . '<main><h1>Home</h1><p>Welcome</p></main>',
        'shop/index.html' => $header . '<main><h1>Shop</h1><div class="grid">' . str_repeat($card, 3) . '</div></main>',
    ),
))->toArray();
$assert(isset($result['source_reports']['wordpress_site_plan']), 'The shared-shell shop plan composes: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array()));
$plan = $result['source_reports']['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers) && 'shared_shell' === ($headers[0]['placement']['kind'] ?? null), 'The shared header is extracted into one template part.');
$assert(4 === count(WordPressSitePlan::blockRanges($headers[0]['canonical_block_markup'])), 'The extracted header has the four-block repeat period of a card.');
$shop = array_column($plan['pages'], null, 'source_path')['shop/index.html'];
$markup = $shop['canonical_block_markup'];
$assert(!str_contains($markup, 'Tagline'), 'The shop canonical page no longer contains the header.');
$bindings = array();
foreach ($plan['runtime_declarations'] as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach ($entity['bindings'] ?? array() as $binding) {
    if ('shop/index.html' === $binding['source_path'] && 'commerce_controls' === $binding['role']) $bindings[] = $binding;
}
$assert(3 === count($bindings), 'All three identical add-to-cart controls retain their bindings.');
$search = $bindings[0]['search_block_markup'];
$matches = array_values(array_filter(WordPressSitePlan::blockRanges($markup), static fn (array $range): bool => $search === substr($markup, $range['offset'], $range['length'])));
$assert(3 === count($matches), 'The canonical page emits three identical bound blocks.');
foreach ($bindings as $index => $binding) {
    $assert($index + 1 === $binding['projected_anchor']['source_occurrence'] && $index + 1 === $binding['occurrence'], 'Bindings retain their source occurrence order.');
    $assert($matches[$index]['offset'] === $binding['position']['offset'] && $matches[$index]['length'] === $binding['position']['length'], 'Each source occurrence lands on its corresponding distinct canonical block.');
    $assert($search === $binding['search_block_markup'] && $search === substr($markup, $binding['position']['offset'], $binding['position']['length']), 'Every binding identifies exact emitted canonical markup.');
}

echo "Binding anchor shell shift contract passed.\n";
