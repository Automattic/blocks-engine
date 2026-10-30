<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationAnchorRuntime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$loadFixture = static fn(string $dir): array => array_combine(
    array_map(basename(...), $files = glob(__DIR__ . '/fixtures/' . $dir . '/*.html')),
    array_map(file_get_contents(...), $files)
);
$compile = static fn(array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$headers = static fn(array $plan): array => array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));

// The checkbox-label menu is a menu #3322 converts: the three routes share one
// header part holding one core/navigation whose shared wp_navigation entity
// keeps the three links, and no route's active state is baked into the shared
// markup — core navigation marks the current item at render time.
$plan = $compile($loadFixture('retained-label-shell'));
WordPressSitePlan::assertValid($plan);
$assert(3 === count($plan['pages']) && 1 === count($headers($plan)), 'Three routes must share one header, not three selected-state identities.');
$header = $headers($plan)[0]['canonical_block_markup'];
$assert(1 === substr_count($header, '<!-- wp:navigation '), 'The converted checkbox-label menu becomes one core/navigation block in the shared header.');
$menu = null;
foreach ($plan['menus'] as $candidate) {
    if (is_string($candidate['token'] ?? null) && str_contains($header, WordPressSitePlan::NAVIGATION_TOKEN_PREFIX . $candidate['token'] . '}}')) $menu = $candidate;
}
$assert(is_array($menu), 'The header navigation references the shared navigation entity.');
$entityMarkup = str_replace('\\/', '/', (string) $menu['block_markup']);
$assert(3 === ($menu['items'] ?? 0) && 3 === substr_count($entityMarkup, '<!-- wp:navigation-link '), 'The navigation entity keeps all three links.');
foreach (array('"url":"/"', '"url":"/about"', '"url":"/work"') as $url) {
    $assert(str_contains($entityMarkup, $url), 'The navigation entity links to ' . $url . '.');
}
$assert(!str_contains($header, 'item--active') && !str_contains($entityMarkup, 'item--active'), 'No route\'s active state is hard-coded into the shared header; core navigation marks the current item at render time.');
foreach ($plan['pages'] as $page) {
    $assert(!str_contains($page['canonical_block_markup'], 'wp:navigation') && !str_contains($page['canonical_block_markup'], 'wp:custom/authored-input') && str_contains($page['canonical_block_markup'], 'Page body.'), 'Header is shared; body is page-owned.');
}

// Menus NavigationPattern cannot convert — repeated heading/list pairs are
// document navigation sections, not one flat menu — keep their label shells.
// The captured checkbox survives as an authored input whose labelMarkup
// carries the navigation route-state binding, the three routes still share
// one header, and the generated theme resolves the binding to the served
// route at render time.
$retainedFiles = $loadFixture('retained-label-shell-sectioned');
$retainedPlan = $compile($retainedFiles);
WordPressSitePlan::assertValid($retainedPlan);
$assert(3 === count($retainedPlan['pages']) && 1 === count($headers($retainedPlan)), 'Label shells navigation cannot convert also share one header.');
$retainedHeader = $headers($retainedPlan)[0]['canonical_block_markup'];
$assert(0 === substr_count($retainedHeader, '<!-- wp:navigation ') && 3 === substr_count($retainedHeader, '<!-- wp:custom/authored-input '), 'Label shells navigation cannot convert stay authored inputs.');
$assert(str_contains($retainedHeader, 'data-blocks-engine-route-state'), 'Resting labels carry the navigation route-state binding in saved HTML and labelMarkup.');
$bootstrap = current(array_filter($retainedPlan['writes'], static fn(array $write): bool => 'functions.php' === $write['target_path']));
$assert(str_contains($bootstrap['payload']['data'], NavigationAnchorRuntime::retainedLabelSource()), 'Generated theme installs the retained-label navigation binding.');

// A small DOM-backed stand-in for the WordPress HTML API, so this contract
// executes the generated filter without installing or booting WordPress.
class WP_HTML_Tag_Processor
{
    private DOMDocument $document;
    private array $nodes;
    private int $position = -1;
    public function __construct(string $html) {
        $this->document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $this->document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        $this->nodes = iterator_to_array((new DOMXPath($this->document))->query('//body//*'));
    }
    public function next_tag(?string $tag = null): bool {
        while (isset($this->nodes[++$this->position])) if (null === $tag || strtolower($tag) === strtolower($this->nodes[$this->position]->tagName)) return true;
        return false;
    }
    public function get_attribute(string $name): ?string { $node = $this->nodes[$this->position]; return $node->hasAttribute($name) ? $node->getAttribute($name) : null; }
    public function set_attribute(string $name, string $value): void { $this->nodes[$this->position]->setAttribute($name, $value); }
    public function remove_attribute(string $name): void { $this->nodes[$this->position]->removeAttribute($name); }
    public function get_updated_html(): string {
        $html = ''; foreach ($this->document->getElementsByTagName('body')->item(0)->childNodes as $node) $html .= $this->document->saveHTML($node);
        return $html;
    }
}
$filters = array();
function add_filter(string $name, callable $callback, int $priority = 10, int $arguments = 1): void { $GLOBALS['filters'][$name][] = $callback; }
function get_queried_object_id(): int { return 1; }
function is_front_page(): bool { return '/' === $GLOBALS['served']; }
function get_post_type(int $id): string { return 'page'; }
function get_page_uri(int $id): string { return trim($GLOBALS['served'], '/'); }
eval(NavigationAnchorRuntime::retainedLabelSource());
$render = static function (string $markup): string {
    preg_match_all('/<!-- wp:custom\/authored-input (\{.*?\}) -->.*?<label\b.*?<\/label>/s', $markup, $matches, PREG_SET_ORDER);
    $output = '';
    foreach ($matches as $match) {
        $attrs = json_decode($match[1], true);
        if (str_contains($attrs['labelMarkup'], 'class="item item--active"') || str_contains($attrs['labelMarkup'], 'data-key="active-')) throw new RuntimeException('Serialized labels must rest just like saved HTML.');
        $html = substr($match[0], strpos($match[0], '<label'));
        foreach ($GLOBALS['filters']['render_block'] as $filter) $html = $filter($html, array('attrs' => $attrs));
        $output .= $html;
    }
    return $output;
};
foreach (array('/' => 'index', '/about' => 'about', '/work' => 'work', '/new-route' => null) as $route => $expected) {
    $GLOBALS['served'] = $route;
    $rendered = $render($retainedHeader);
    $assert((null === $expected ? 0 : 1) === substr_count($rendered, 'class="item item--active"'), 'Only the served route is current: ' . $route);
    if (null !== $expected) $assert(str_contains($rendered, 'data-key="active-' . $expected . '"'), 'The current data attribute round-trips on ' . $route);
    $assert(!str_contains($rendered, 'data-blocks-engine-route-state'), 'Binding transport does not leak into served markup.');
}
$styledFiles = array_map(static fn(string $html): string => str_replace(array('class="item item--active"', 'class="item"'), array('class="item item--active" style="color:red"', 'class="item" style="color:blue"'), $html), $retainedFiles);
$styledPlan = $compile($styledFiles);
$assert(1 === count($headers($styledPlan)), 'Cross-capture resting presentation also normalizes retained label styles.');
$GLOBALS['served'] = '/about';
$styledRender = $render($headers($styledPlan)[0]['canonical_block_markup']);
$assert(1 === substr_count($styledRender, 'style="color:red"') && 2 === substr_count($styledRender, 'style="color:blue"'), 'Only the current route receives selected presentation.');
$edited = str_replace('href="/about"', 'href="/changed"', $retainedHeader);
$assert(!str_contains($render($edited), 'class="item item--active"'), 'Editing a menu URL does not keep a captured current marker.');

// Both excluded routes have the same terminal slug. The dominant header is
// still shared, but neither excluded route can claim the other's template.
$collision = array();
$fixtureRoot = __DIR__ . '/fixtures/route-template-collision/';
foreach (array('index.html', 'work.html', 'north/about.html', 'south/about.html') as $path) $collision[$path] = file_get_contents($fixtureRoot . $path);
$collisionPlan = $compile($collision);
WordPressSitePlan::assertValid($collisionPlan);
$sharedCollision = $collision;
preg_match('/<header>.*?<\/header>/', $collision['index.html'], $sharedHeader);
foreach (array('north/about.html', 'south/about.html') as $path) $sharedCollision[$path] = preg_replace('/<header\b.*?<\/header>/', $sharedHeader[0], $sharedCollision[$path]);
$sharedCollisionPlan = $compile($sharedCollision);
WordPressSitePlan::assertValid($sharedCollisionPlan);
$assert(1 === count($headers($sharedCollisionPlan)), 'Routes sharing a terminal slug also share one identical header part.');
foreach ($sharedCollisionPlan['pages'] as $page) if (in_array($page['source_path'], array('north/about.html', 'south/about.html'), true)) {
    $template = current(array_filter($sharedCollisionPlan['templates'], static fn(array $template): bool => $page['route_template'] === $template['slug']));
    $assert(str_contains($template['canonical_block_markup'], '"slug":"header"'), 'Each colliding route custom template uses the shared header.');
}
$assert(1 === count(array_filter($headers($collisionPlan), static fn(array $part): bool => 'header' === $part['slug'])), 'Slug collisions must not veto shared header extraction; substantive variants remain separate.');
$themeWrite = current(array_filter($collisionPlan['writes'], static fn(array $write): bool => 'theme.json' === $write['target_path']));
$theme = json_decode($themeWrite['payload']['data'], true);
$keys = array();
foreach ($collisionPlan['pages'] as $page) {
    if (!in_array($page['source_path'], array('north/about.html', 'south/about.html'), true)) continue;
    $key = $page['route_template'] ?? '';
    $assert('' !== $key && $key === ($page['metadata']['post_meta']['_wp_page_template'] ?? null), 'Every colliding page has a native custom template assignment.');
    $keys[] = $key;
    $assert(in_array($key, array_column($collisionPlan['templates'], 'slug'), true), 'Assigned custom template exists.');
    $assert(in_array($key, array_column($theme['customTemplates'] ?? array(), 'name'), true), 'Assigned custom template is registered in theme.json.');
}
$assert(2 === count(array_unique($keys)), 'Same terminal slug under different parents gets distinct template keys.');
sort($keys);
$assert(array('page-north-about', 'page-south-about') === $keys, 'Route-qualified template keys stay readable in the Site Editor: ' . implode(', ', $keys));
$dominant = current(array_filter($headers($collisionPlan), static fn(array $part): bool => 'header' === $part['slug']));
$assert(array() === array_diff($keys, $dominant['placement']['excluded_template_slugs'] ?? array()), 'Each variant exclusion uses the same route-qualified key as its assignment.');
foreach ($collisionPlan['templates'] as $template) if (in_array($template['slug'], $keys, true)) $assert(!str_contains($template['canonical_block_markup'], '"slug":"header"'), 'Excluded routes do not inherit the dominant header.');
$assert('page-work' === WordPressSitePlan::routeTemplateKey(current(array_filter($collisionPlan['pages'], static fn(array $page): bool => 'work.html' === $page['source_path']))), 'Non-colliding routes retain page-slug behavior.');

// Unbound varying URLs remain authored content, not route-state differences.
$links = array();
foreach (array('index', 'about', 'work') as $slug) $links[$slug . '.html'] = '<header><a href="/">Site</a><nav><a href="https://example.org/alternate/' . $slug . '">Alternate</a></nav></header><main><h1>' . $slug . '</h1><p>Page body.</p></main>';
$assert(array() === $headers($compile($links)), 'Unbound per-page targets must not freeze the first page URL into a shared header.');
echo "retained-label-shell contract passed\n";
