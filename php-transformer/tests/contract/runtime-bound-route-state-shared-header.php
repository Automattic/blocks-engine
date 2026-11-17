<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// One authored header on every route: each route paints its own nav link
// current, and the header holds a runtime-bound cart opener. Equal source
// proves one shell; route state and the binding must not keep it inline.
$routes = array('index' => '/', 'about' => '/about/', 'contact' => '/contact/');
$files = array();
foreach ($routes as $route => $href) {
    $nav = '';
    foreach ($routes as $other => $otherHref) {
        $nav .= $other === $route
            ? '<a class="link text-primary" href="' . $otherHref . '" aria-current="page" data-status="active">' . ucfirst($other) . '</a>'
            : '<a class="link" href="' . $otherHref . '">' . ucfirst($other) . '</a>';
    }
    $files['index' === $route ? 'index.html' : $route . '/index.html'] = '<!doctype html><html><head><title>Shop</title><style>.text-primary{color:#c00}.link{font-size:12px}</style></head><body>'
        . '<header class="site"><nav>' . $nav . '</nav><button class="cart-btn" data-carrito-abrir aria-label="Open cart">Cart <span class="cart-count" data-carrito-count>0</span></button></header>'
        . '<main><h1>' . $route . '</h1><p>Route content.</p></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

foreach ($plan['pages'] as $page) {
    $assert(!str_contains($page['canonical_block_markup'], '<header'), 'The shared header must not remain in page content: ' . $page['source_path']);
    $assert(str_contains($page['canonical_block_markup'], 'Route content.'), 'Page content remains owned by the page.');
}
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers) && 'shared_shell' === ($headers[0]['placement']['kind'] ?? null), 'One shared header part serves every route.');
$assert(str_contains($headers[0]['canonical_block_markup'], 'cart-btn'), 'The bound cart opener moves with its shell.');
$bindings = array();
foreach ($plan['runtime_declarations'] as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach ($entity['bindings'] ?? array() as $binding) {
    if ('commerce_cart_link' === ($binding['role'] ?? null)) $bindings[] = $binding;
}
$assert(1 === count($bindings) && $headers[0]['source_path'] === $bindings[0]['source_path'], 'The cart binding is hoisted once onto the shared part.');

echo "runtime-bound route-state shared header passed\n";
