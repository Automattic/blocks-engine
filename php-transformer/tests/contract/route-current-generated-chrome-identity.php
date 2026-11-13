<?php
declare(strict_types=1);

/**
 * The served route is not part of chrome identity. Routes whose header is
 * identical except for which menu item the source marked current share one
 * header part, also when the header is kept as a generated component's markup
 * rather than lowered to native navigation. The shared part saves every item
 * at rest; the served route restores its own item's current state at render.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationAnchorRuntime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$routes = array('index' => 'Home', 'about' => 'About', 'contact' => 'Contact');
$href = static fn(string $slug): string => 'index' === $slug ? '/' : '/' . $slug . '/';
// The source paints its served item with a class, aria-current, and a data
// value no other item carries; none of these names is engine vocabulary.
$menu = static function (string $current) use ($routes, $href): string {
    $items = '';
    foreach ($routes as $slug => $label) {
        $on = $slug === $current;
        $items .= '<li class="menu-entry"><a href="' . $href($slug) . '" class="menu-link' . ($on ? ' is-sel' : '') . '"' . ($on ? ' aria-current="page" data-state="selected-page"' : '') . '><span class="menu-text">' . $label . '</span></a></li>';
    }
    return '<nav class="primary"><ul class="menu-list">' . $items . '</ul></nav>';
};
// A header nested deep enough, under a custom-element host, to be kept as one
// generated component; or a plain header whose menu lowers to navigation.
$generated = static fn(string $nav): string => '<header class="masthead">' . str_repeat('<div class="w">', 15)
    . '<site-bar class="bar"><div class="cell"><a href="/"><img src="/media/logo.png" alt="Logo" width="32" height="9"></a></div><div class="cell">' . $nav . '</div>'
    . '<div class="cell"><a class="cta" href="/contact/"><img src="/media/call.png" alt="Call" width="16" height="16"></a></div></site-bar>'
    . str_repeat('</div>', 15) . '</header>';
$native = static fn(string $nav): string => '<header class="masthead"><p class="brand">Brand</p>' . $nav . '</header>';
$png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
$compile = static function (callable $header) use ($routes, $menu, $png): array {
    $files = array();
    foreach ($routes as $slug => $title) {
        $files[] = array('path' => ('index' === $slug ? '' : $slug . '/') . 'index.html', 'content' => '<!doctype html><html><head><title>' . $title . '</title><style>.menu-link{color:#333}.menu-link.is-sel{color:#c00}</style></head><body>'
            . $header($menu($slug)) . '<main><h1>' . $title . '</h1><p>' . $title . ' body copy.</p></main><footer class="site-footer"><p>Footer copy</p></footer></body></html>');
    }
    foreach (array('media/logo.png', 'media/call.png') as $path) {
        $files[] = array('path' => $path, 'mime_type' => 'image/png', 'payload_reference' => array('schema' => 'blocks-engine/payload-reference/v1', 'id' => $path, 'bytes' => strlen($png), 'sha256' => hash('sha256', $png)));
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    WordPressSitePlan::assertValid($plan);
    return $plan;
};
$headers = static fn(array $plan): array => array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));

$plan = $compile($generated);
$parts = $headers($plan);
$assert(1 === count($parts), 'Routes differing only in their current item share one header part, got ' . count($parts));
foreach ($plan['pages'] as $page) {
    $assert(!str_contains($page['canonical_block_markup'], 'menu-link'), "{$page['source_path']} keeps no per-route copy of the header.");
}
$part = (string) $parts[0]['canonical_block_markup'];
$assert(1 === preg_match('/<!-- wp:([a-z0-9-]+\/[a-z0-9-]+) (\{"content":.*?\}) \/?-->/', $part, $block) && !str_starts_with($block[1], 'core/'), 'The header is kept as one generated component: ' . $part);
$content = (string) (json_decode($block[2], true)['content'] ?? '');
$assert(!preg_match('/\sclass="[^"]*\bis-sel\b/', $content), 'No item is saved current: ' . $content);
$assert(!str_contains($content, 'aria-current="page"') && !str_contains($content, 'data-state="selected-page"'), 'No item saves the served route\'s state.');
$assert(!str_contains((string) json_encode($plan), 'data-blocks-engine-resting-state'), 'Capture evidence does not reach the plan.');

// A DOM-backed stand-in for the WordPress HTML API runs the emitted filter.
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
    public function get_tag(): string { return strtoupper($this->nodes[$this->position]->tagName); }
    public function get_attribute(string $name): ?string { $node = $this->nodes[$this->position]; return $node->hasAttribute($name) ? $node->getAttribute($name) : null; }
    public function set_attribute(string $name, string $value): void { $this->nodes[$this->position]->setAttribute($name, $value); }
    public function remove_attribute(string $name): void { $this->nodes[$this->position]->removeAttribute($name); }
    public function get_updated_html(): string {
        $html = ''; foreach ($this->document->getElementsByTagName('body')->item(0)->childNodes as $node) $html .= $this->document->saveHTML($node);
        return $html;
    }
}
function add_filter(string $name, callable $callback, int $priority = 10, int $arguments = 1): void { $GLOBALS['filters'][$name][] = $callback; }
function get_queried_object_id(): int { return 1; }
function is_front_page(): bool { return '/' === $GLOBALS['served']; }
function get_post_type(int $id): string { return 'page'; }
function get_page_uri(int $id): string { return trim($GLOBALS['served'], '/'); }
function get_permalink(int $id): string { return $GLOBALS['served']; }
$assert(str_contains($content, 'data-blocks-engine-route-state'), 'The shared part binds its items to the served route.');
eval(NavigationAnchorRuntime::retainedLabelSource());
foreach (array('/' => '/', '/about' => '/about', '/contact' => '/contact', '/elsewhere' => null) as $served => $expected) {
    $GLOBALS['served'] = $served;
    $html = $content;
    foreach ($GLOBALS['filters']['render_block'] as $filter) $html = $filter($html, array('blockName' => $block[1], 'attrs' => array('content' => $content)));
    preg_match_all('/<a\b[^>]*>/', $html, $anchors);
    $current = array_values(array_filter($anchors[0], static fn(string $anchor): bool => str_contains($anchor, 'aria-current="page"')));
    $assert((null === $expected ? 0 : 1) === count($current), "Only the served route's item is current on {$served}: " . implode(' ', $current));
    if (null !== $expected) {
        $assert(1 === preg_match('/href="' . preg_quote($expected, '/') . '\/?"/', $current[0]) && str_contains($current[0], 'is-sel') && str_contains($current[0], 'data-state="selected-page"'), "The served item regains its current class and data on {$served}: " . $current[0]);
    }
    $assert((null === $expected ? 0 : 1) === count(array_filter($anchors[0], static fn(string $anchor): bool => 1 === preg_match('/\sclass="[^"]*\bis-sel\b/', $anchor))), "The current class marks only the served item on {$served}.");
    $assert(!str_contains($html, 'data-blocks-engine-route-state'), 'Binding transport does not leak into served markup.');
}

// The same header lowered to native navigation also shares one part.
$nativeParts = $headers($compile($native));
$assert(1 === count($nativeParts), 'Native navigation headers differing only in their current item share one part, got ' . count($nativeParts));

echo "Route-current generated chrome identity tests passed\n";
