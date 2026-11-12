<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) throw new RuntimeException($message);
};
$compile = static fn (string $html): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$html = file_get_contents(dirname(__DIR__) . '/fixtures/cart-opener.html');
$result = $compile($html);
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$links = array();
foreach ( $plan['runtime_declarations'] ?? array() as $declaration ) {
    if ( 'cart_links' === ($declaration['type'] ?? null) ) $links = $declaration['payload']['entities'];
}
$assert(2 === count($links), 'Exactly two cart links reach the public site plan: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? $plan['runtime_declarations'] ?? array()));
foreach ( $links as $link ) {
    $assert(! empty($link['bindings']), 'Every declared opener has a resolved binding.');
    $assert(! str_contains($link['selector'], 'dialog'), 'Controls inside the cart dialog are not openers.');
    $binding = $link['bindings'][0];
    $assert('generic/block-binding/v1' === $binding['schema'] && 'commerce_cart_link' === $binding['role'], 'Each opener has a generic cart link binding.');
    $assert(! str_contains($binding['search_block_markup'], 'btn-add'), 'Purchase buttons are not cart links.');
    $assert(! str_contains($binding['search_block_markup'], 'carrito-cerrar'), 'The Cerrar carrito close button is not declared.');
    if ( str_contains($binding['search_block_markup'], 'cart-btn') ) {
        $assert('[data-carrito-count]' === ($link['count_badge']['selector'] ?? ''), 'The inline count badge is referenced.');
        $assert(str_contains($binding['search_block_markup'], 'data-carrito-count'), 'The badge markup is retained.');
    }
}
// A second route causes the captured header to become a shared template part.
$header = substr($html, strpos($html, '<header>'), strpos($html, '</header>') + 9 - strpos($html, '<header>'));
$shared = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'about.html' => $header . '<main><h1>About</h1></main>')))->toArray();
$sharedPlan = $shared['source_reports']['wordpress_site_plan'] ?? array();
$headers = array_values(array_filter($sharedPlan['template_parts'] ?? array(), static fn (array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'The captured header becomes one shared part.');
$sharedLinks = array();
foreach ( $sharedPlan['runtime_declarations'] ?? array() as $declaration ) {
    if ( 'cart_links' === ($declaration['type'] ?? null) ) $sharedLinks = $declaration['payload']['entities'];
}
$assert(2 === count($sharedLinks), 'Shared chrome does not duplicate cart link declarations.');
foreach ( $sharedLinks as $link ) {
    $assert(! empty($link['bindings']), 'Shared openers have resolved bindings.');
    $binding = $link['bindings'][0];
    $owners = array_merge($sharedPlan['pages'], $sharedPlan['template_parts']);
    $ownerMarkup = array_column($owners, 'canonical_block_markup', 'source_path');
    $assert($link['source_path'] === $binding['source_path'], 'Entity and binding agree on the owning artifact.');
    $assert(str_contains($ownerMarkup[$binding['source_path']] ?? '', $binding['search_block_markup']), 'Every binding matches exact markup in its owner after style rewriting.');
    if ( ! isset($link['count_badge']) ) continue;
    $binding = $link['bindings'][0];
    $assert(str_contains($headers[0]['canonical_block_markup'], $binding['search_block_markup']), 'The header binding matches exact markup in the captured part.');
    $assert($headers[0]['source_path'] === $binding['source_path'], 'The binding identifies its template part.');
}
// Close/dismiss evidence wins over cart vocabulary in every recognized language.
$recognizer = new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer();
foreach (array('close', 'cerrar', 'fechar', 'fermer', 'schließen', 'chiudi', 'sluiten', 'dismiss', '×', '✕') as $close) {
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8"><button class="cart-' . $close . '" aria-label="' . $close . ' cart">Cart</button>');
    $assert(! $recognizer->isCartOpener($dom->getElementsByTagName('button')->item(0)), 'Close action is never a cart opener: ' . $close);
}
$only = $compile('<!doctype html><html><head><title>Cart</title></head><body><main><button aria-label="View cart">Cart</button></main></body></html>');
$assert(array() === $only['fallbacks'], 'A cart opener alone adds no unsupported fallback.');
foreach ( $plan['runtime_declarations'] ?? array() as $declaration ) {
    $assert(! in_array('entity_collection:cart_links', $declaration['required_for'] ?? array(), true), 'Cart links stay optional so consumers without an adapter still import.');
}
