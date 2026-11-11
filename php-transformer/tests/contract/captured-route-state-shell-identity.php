<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// A site builder renders the same header on every route, but each capture
// carries that route's state: the current link swaps its anchor utilities,
// every item carries per-document generated markers, and the captured drawer
// snapshot paints its current link and numbers its ids and click counters
// per page. The header also holds a font the theme binds to a
// preset after shell candidates are read. One header part still serves every
// route, and no route keeps a page-owned copy. The masthead is unlabeled
// chrome: a leading sibling holding the menu inside the page wrapper.
$routes = array('index.html' => array('/', 'Home', 411), 'about/index.html' => array('/about/', 'About', 412), 'contact/index.html' => array('/contact/', 'Contact', 413));
$capture = static function (string $path) use ($routes): string {
    [$current, , $number] = $routes[$path];
    $links = '';
    $drawer = '';
    foreach (array_values($routes) as $position => [$url, $label]) {
        $active = $url === $current;
        $attrs = array('className' => 'item item-' . $position . ' blocks-engine-navigation-anchor-' . substr(md5($path . $position), 0, 12) . '-0' . ($active ? ' blocks-engine-current-navigation-item' : ''), 'kind' => 'custom', 'label' => $label, 'metadata' => array('blocksEngineNavigationAnchor' => array('className' => 'link ' . ($active ? 'c1-6p c1-6q' : 'c1-4o c1-4p'))), 'url' => $url);
        $links .= '<!-- wp:navigation-link ' . json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' /-->';
        $drawer .= '<li><a id="nav-' . $number . '-link-' . $position . '" class="' . ($active ? 'c1-97 c1-98' : 'c1-91 c1-92') . '" data-tccl="nav.link.' . ($active ? 'active' : 'default') . '.' . ($number * 10 + $position) . '.click" href="' . $url . '">' . $label . '</a></li>';
    }
    $html = '<div id="nav-' . $number . '-drawer" role="navigation"><ul>' . $drawer . '</ul></div>';
    $snapshot = json_encode(array('resting' => array('html' => $html, 'style' => null, 'class' => 'drawer', 'hidden' => true), 'opened' => array('html' => $html, 'style' => null, 'class' => 'drawer', 'hidden' => false)), JSON_UNESCAPED_SLASHES);
    $panel = '<!-- wp:ssi-site/layout-shell ' . json_encode(array('wrappers' => array(array('tagName' => 'div', 'attributes' => array('class' => 'drawer', 'data-blocks-engine-capture-existing-panel' => $snapshot)))), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) . ' --><div class="drawer" data-blocks-engine-capture-existing-panel="' . htmlspecialchars($snapshot, ENT_QUOTES) . '"><!-- wp:paragraph --><p>Menu</p><!-- /wp:paragraph --></div><!-- /wp:ssi-site/layout-shell -->';
    $navigation = '<!-- wp:group {"className":"primary","style":{"typography":{"fontFamily":"Poppins, sans-serif"}}} --><div class="wp-block-group primary" style="font-family:Poppins, sans-serif"><!-- wp:navigation {"className":"blocks-engine-list-navigation"} -->' . $links . '<!-- /wp:navigation --></div><!-- /wp:group -->';
    return '<!-- wp:group {"className":"wrapper"} --><div class="wp-block-group wrapper"><!-- wp:group {"className":"masthead"} --><div class="wp-block-group masthead"><!-- wp:paragraph --><p>Four Fires</p><!-- /wp:paragraph -->' . $navigation . $panel . '</div><!-- /wp:group --><!-- wp:group {"className":"canvas"} --><div class="wp-block-group canvas"><!-- wp:heading --><h2 class="wp-block-heading">' . $routes[$path][1] . ' body</h2><!-- /wp:heading --><!-- wp:paragraph --><p>' . $routes[$path][1] . ' body copy.</p><!-- /wp:paragraph --></div><!-- /wp:group --></div><!-- /wp:group -->';
};

$files = array('style.css' => 'body{font-family:Georgia,serif}.primary{font-family:Poppins,sans-serif}');
foreach ($routes as $path => [, $title]) $files[$path] = '<!doctype html><html><head><link rel="stylesheet" href="' . str_repeat('../', substr_count($path, '/')) . 'style.css"></head><body><main><h1>' . $title . '</h1><p class="primary">' . $title . ' copy</p></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
foreach ($result['source_reports']['compiled_site']['pages'] as &$page) $page['block_markup'] = $capture($page['source_path']);
unset($page);
$plan = (new WordPressSitePlan())->fromResult($result);
WordPressSitePlan::assertValid($plan);

$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Captured route state still yields one shared header: ' . json_encode(array_map(static fn(array $diagnostic): string => $diagnostic['code'] . ':' . ($diagnostic['provenance']['reason'] ?? ''), $plan['diagnostics'])));
$navigation = $headers[0]['canonical_block_markup'] . implode('', array_column($plan['menus'], 'block_markup'));
$assert(!str_contains($navigation, 'c1-6p'), 'The shared navigation freezes no route-current anchor paint: ' . $navigation);
foreach ($plan['pages'] as $page) $assert(!str_contains((string) $page['canonical_block_markup'], 'Four Fires'), "{$page['source_path']} renders no page-owned header copy.");
echo "captured-route-state-shell-identity contract passed\n";
