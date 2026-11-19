<?php
declare(strict_types=1);

/**
 * A shared header and footer nested in a site wrapper beside `main` render
 * through the theme templates, not through page content.
 *
 * Squarespace places a floating cart, the header, `main` and the footer inside
 * `#siteWrapper`, and the cart link carries a runtime binding. The header
 * differs per route only by its class-marked current item, so every route
 * shares one header. The bound cart leads the header inside the wrapper; it
 * travels with the header part, so the wrapper frame moves to the templates
 * and page content keeps only what `main` holds.
 */

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$routes = array(
    'website/index.html' => array('/', 'Home'),
    'website/services/index.html' => array('/services', 'Services'),
    'website/team/index.html' => array('/team', 'Team'),
    'website/press/index.html' => array('/press', 'Press'),
);
$files = array();
$order = 0;
foreach ($routes as $path => [$url, $title]) {
    // Capture node ids are route-local box-tree observations.
    $node = static fn(int $id): string => ' data-blocks-engine-node-id="n' . (10 * $order + $id) . '"';
    ++$order;
    $item = static fn(string $href, string $label): string => '<div class="header-nav-item' . ($href === $url ? ' header-nav-item--active' : '') . '"><a href="' . $href . '"' . ($href === $url ? ' aria-current="page"' : '') . '>' . $label . '</a></div>';
    $cart = '<div id="floatingCart" class="floating-cart hidden"' . $node(1) . '><a href="/cart" class="icon--cart sqs-custom-cart"' . $node(2) . '><span class="Cart-inner"' . $node(3) . '><svg class="icon icon--cart" viewBox="0 0 24 24" width="24" height="24"' . $node(4) . '><path d="M4 4h16l-2 10H6z"' . $node(5) . '></path></svg><span class="sqs-cart-quantity"' . $node(6) . '>0</span></span></a></div>';
    $header = '<header id="header" class="header"><div class="header-inner"><div class="header-title"><a href="/">Salon</a></div><nav class="header-nav">'
        . $item('/', 'Home') . $item('/services', 'Services') . $item('/team', 'Team') . $item('/press', 'Press')
        . '</nav></div></header>';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title></head><body>'
        . '<div id="siteWrapper" class="site-wrapper">' . $cart . $header
        . '<main id="page"><article><section><h1>' . $title . '</h1><p>Body text for ' . $title . '.</p></section></article></main>'
        . '<footer id="footer-sections" class="sections"><p>30 Main Street | Open Tuesday to Saturday</p></footer></div></body></html>';
    $files[] = array('path' => $path, 'content' => $html);
    // Each route's box tree observes the cart icon under its own node id.
    $tree = array('schema' => $fixture['schema'], 'source_path' => $path, 'source_hash' => hash('sha256', $html), 'viewports' => $fixture['viewports'], 'nodes' => array('n' . (10 * ($order - 1) + 4) => array('children' => array(), 'viewports' => $fixture['nodes']['n1']['viewports'])));
    $files[] = array('path' => 'website/box-tree/route-' . $order . '.json', 'content' => json_encode($tree, JSON_THROW_ON_ERROR));
}

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'provenance' => array('source_url' => 'https://example.test/'), 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$parts = array_column($plan['template_parts'], null, 'slug');
$assert(isset($parts['header'], $parts['footer']), 'The routes share one header and one footer part: ' . implode(',', array_keys($parts)));
$assert(1 === count(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null))), 'One header part serves every route.');
$assert(str_contains($parts['header']['canonical_block_markup'], 'floatingCart') && str_contains($parts['header']['canonical_block_markup'], 'header-nav'), 'The bound cart leading the header travels with the header part.');
foreach ($plan['pages'] as $page) {
    $markup = (string) $page['canonical_block_markup'];
    $assert(!str_contains($markup, 'wp:template-part'), 'Page content carries no template-part reference: ' . $page['source_path']);
    $assert(!str_contains($markup, 'floatingCart') && !str_contains($markup, 'siteWrapper') && !str_contains($markup, 'header-nav'), 'Page content keeps no site chrome: ' . $page['source_path']);
}
$referencing = array('header' => 0, 'footer' => 0);
foreach ($plan['templates'] as $template) foreach (array_keys($referencing) as $slug) if (str_contains((string) $template['canonical_block_markup'], '"slug":"' . $slug . '"')) ++$referencing[$slug];
$assert($referencing['header'] > 0 && $referencing['footer'] > 0, 'Templates reference the shared header and footer: ' . json_encode($referencing));
foreach ($plan['templates'] as $template) {
    if ('page' !== $template['slug']) continue;
    $markup = (string) $template['canonical_block_markup'];
    $assert(str_contains($markup, 'siteWrapper') && strpos($markup, '"slug":"header"') < strpos($markup, 'wp:post-content') && strpos($markup, 'wp:post-content') < strpos($markup, '"slug":"footer"'), 'The page template keeps the wrapper around header, content and footer.');
}
$bindings = array();
foreach ($plan['runtime_declarations'] as $declaration) {
    if ('cart_links' !== ($declaration['type'] ?? null)) continue;
    foreach ($declaration['payload']['entities'] as $entity) foreach ($entity['bindings'] as $binding) $bindings[] = (string) $binding['source_path'];
}
$assert(array() !== $bindings && array() === array_diff($bindings, array($parts['header']['source_path'])), 'The cart binding anchors once on the header part: ' . json_encode($bindings));
echo "Shell wrapper shared header template: passed\n";
