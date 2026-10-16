<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Each route marks its own menu item current, with aria-current and a state
// class, and the source styles both the resting items and the current one.
// Every item also has a class of its own, which is identity rather than state. The header around the menu also holds a
// route-specific call to action, so each route compiles its own menu. The item
// the snapshot served is route state, not a different item: every route's menu
// is the same navigation, and the current-item paint follows WordPress'
// served-route hook.
$routes = array('index' => 'Home', 'about' => 'About', 'visit' => 'Visit');
$page = static function (string $current) use ($routes): string {
    $menu = '';
    foreach ($routes as $slug => $label) $menu .= '<li><a class="item-' . $slug . ($slug === $current ? ' is-active" aria-current="page"' : '"') . ' href="' . $slug . '.html">' . $label . '</a></li>';
    return '<!doctype html><html><head><title>' . $routes[$current] . '</title><style>'
        . '.primary a{color:#123456;border-bottom:2px solid transparent}'
        . '.primary a[aria-current="page"]{color:#654321}.primary a.is-active{border-bottom-color:#abcdef}.item-about{letter-spacing:1px}'
        . '</style></head><body><header><a class="brand" href="index.html">Harbor</a><a class="cta" href="' . $current . '.html#book">Book ' . $routes[$current] . '</a><nav class="primary"><ul>' . $menu . '</ul></nav></header>'
        . '<main><h1>' . $routes[$current] . '</h1><p>' . $routes[$current] . ' body copy.</p></main></body></html>';
};
$files = array();
foreach (array_keys($routes) as $slug) $files['website/' . $slug . '.html'] = $page($slug);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];

$menus = array_values(array_filter($plan['menus'], static fn (array $menu): bool => 3 === ($menu['items'] ?? 0)));
$assert(1 === count($menus), 'Every route renders one shared navigation, whichever item it serves: ' . json_encode(array_column($plan['menus'], 'source_path')));
$references = array();
foreach ($plan['pages'] as $document) {
    preg_match_all('/"ref":"(\{\{wordpress-site-plan:navigation:[^"]+)"/', (string) $document['canonical_block_markup'], $refs);
    foreach ($refs[1] as $ref) $references[$ref] = true;
}
$assert(1 === count($references), 'Each route references that one navigation entity: ' . json_encode(array_keys($references)));

$css = '';
array_walk_recursive($plan, static function ($value) use (&$css): void {
    if (is_string($value) && str_contains($value, '#abcdef')) $css .= $value . "\n";
});
preg_match_all('/([^{}]*)\{[^}]*#abcdef[^}]*\}/i', $css, $rules);
$projected = array_filter($rules[1], static fn (string $selector): bool => str_contains($selector, 'wp-block-navigation'));
$assert(array() !== $projected, 'The current-item paint is projected onto the native navigation.');
$arms = array();
foreach ($projected as $selector) {
    $depth = 0; $arm = '';
    foreach (str_split($selector) as $char) {
        $depth += '(' === $char ? 1 : (')' === $char ? -1 : 0);
        if (',' === $char && 0 === $depth) { $arms[] = $arm; $arm = ''; continue; }
        $arm .= $char;
    }
    $arms[] = $arm;
}
foreach ($arms as $selector) {
    // A shared menu renders every item on every route, so a selector that
    // freezes the snapshot's current item would paint Home current on About.
    $assert(str_contains($selector, '[aria-current'), 'Current-item paint applies only to the served route\'s item: ' . trim($selector));
}

echo "navigation current item shape contract passed\n";
