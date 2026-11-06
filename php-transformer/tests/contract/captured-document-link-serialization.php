<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array(
    'website/index.html' => '<main><p>Home</p></main>',
    'website/wordcamp-us-cheatsheet.html' => '<meta property="article:published_time" content="2023-08-12T12:00:00Z"><main><article><h1>Cheat Sheet</h1><p>Body</p></article></main>',
)))->toArray();
$pages = array_column($result['source_reports']['wordpress_site_plan']['pages'], null, 'source_path');
if ('post' !== $pages['website/wordcamp-us-cheatsheet.html']['post_type']) {
    throw new RuntimeException('The link regression target must be an imported post.');
}
$plan = new WordPressSitePlan();
$plan->fromResult($result);
$paragraph = '<p>After the huge success of the <a href="/wordcamp-us-cheatsheet.html" target="_blank" rel="noopener">WordCamp US 2023 Cheat Sheet</a>…</p>';
// Companion attributes can carry editable HTML in JSON with escaped solidi.
$serialized = json_encode(array('html' => $paragraph), JSON_THROW_ON_ERROR);
$routes = $result['source_reports']['wordpress_site_plan']['routes'];
$resolved = $plan->routeLinks($serialized, 'website/index.html', $routes);
if (!str_contains($resolved, '/wordcamp-us-cheatsheet\\"') || str_contains($resolved, '.html')) {
    throw new RuntimeException('JSON-serialized captured links must resolve just like ordinary HTML links.');
}
$uncaptured = '<a href="https://example.test/not-captured.html">Other</a>';
if ($uncaptured !== $plan->routeLinks($uncaptured, 'website/index.html', $routes)) {
    throw new RuntimeException('Uncaptured absolute source links must remain unchanged.');
}
// Exercise the generated archive filter with a non-default WordPress URL policy.
function add_filter(string $hook, callable $callback): void { $GLOBALS['archive_link_filter'] = $callback; }
function get_day_link(int $year, int $month, int $day): string { return "https://local.test/date/$year/$month/$day/"; }
function get_user_by(string $field, string $slug): object { return (object) array('ID' => 17); }
function get_author_posts_url(int $id): string { return "https://local.test/writers/$id/"; }
function get_term_by(string $field, string $slug, string $taxonomy): object { return (object) array('slug' => $slug, 'taxonomy' => $taxonomy); }
function get_term_link(object $term): string { return "https://local.test/terms/{$term->taxonomy}/{$term->slug}/"; }
class WP_HTML_Tag_Processor
{
    private DOMDocument $document;
    private int $offset = -1;
    private ?DOMElement $element = null;
    public function __construct(string $content) { $this->document = new DOMDocument(); $this->document->loadHTML($content); }
    public function next_tag(): bool { $this->element = $this->document->getElementsByTagName('*')->item(++$this->offset); return null !== $this->element; }
    public function get_attribute(string $name): ?string { return $this->element->hasAttribute($name) ? $this->element->getAttribute($name) : null; }
    public function set_attribute(string $name, string $value): void { $this->element->setAttribute($name, $value); }
    public function get_updated_html(): string { return $this->document->saveHTML(); }
}
$bootstrap = Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::archiveLinks(array_map(static fn(string $path): array => array('route' => array('path' => $path)), array('2023/04/02', 'category/news', 'tag/events', 'author/editor')));
eval($bootstrap);
$archiveMarkup = ($GLOBALS['archive_link_filter'])('<a href="/2023/04/02/?view=all#posts">Archive</a>');
if (!str_contains($archiveMarkup, 'https://local.test/date/2023/4/2/?view=all#posts')) {
    throw new RuntimeException('Captured archive links must use WordPress date links, preserving query and fragment.');
}
foreach (array('category/news' => 'terms/category/news', 'tag/events' => 'terms/post_tag/events', 'author/editor' => 'writers/17') as $path => $destination) {
    $markup = ($GLOBALS['archive_link_filter'])('<a href="/' . $path . '">Archive</a>');
    if (!str_contains($markup, 'https://local.test/' . $destination . '/')) {
        throw new RuntimeException('Term and author archives must use their native WordPress links.');
    }
}
echo "captured document link serialization contract passed\n";
