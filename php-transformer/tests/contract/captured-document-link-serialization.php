<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$member = '<p>After the huge success of the <a href="https://robertjacobi.com/wordcamp-us-cheatsheet.html" target="_blank" rel="noopener">WordCamp US 2023 Cheat Sheet</a> and <a href="https://other.test/wordcamp-us-cheatsheet.html?view=all#posts">Other site</a>…</p>';
$component = array('kind' => 'collection', 'selector' => '#records', 'status' => 'captured', 'states' => array(), 'collection' => array(
    'coverage' => 'complete', 'controls' => array(), 'states' => array(array('id' => 'initial', 'members' => array('cheat-sheet'))),
    'members' => array(array('key' => 'cheat-sheet', 'html' => $member)),
));
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array(
    'website/index.html' => '<main><section><div id="records"><p>Initial content</p></div></section></main>',
    'website/wordcamp-us-cheatsheet.html' => '<meta property="article:published_time" content="2023-08-12T12:00:00Z"><main><article><h1>Cheat Sheet</h1><p>Body</p></article></main>',
    'capture-receipt.json' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://robertjacobi.com/', 'path' => 'website/index.html')))),
    'interaction-states.json' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://robertjacobi.com/', 'components' => array($component))))),
)))->toArray();
$pages = array_column($result['source_reports']['wordpress_site_plan']['pages'], null, 'source_path');
if ('post' !== $pages['website/wordcamp-us-cheatsheet.html']['post_type']) {
    throw new RuntimeException('The link regression target must be an imported post.');
}
$plan = (new WordPressSitePlan())->fromResult($result);
$emitted = json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (!str_contains($emitted, 'href=\\"/wordcamp-us-cheatsheet\\"') || str_contains($emitted, 'https://robertjacobi.com/wordcamp-us-cheatsheet.html')) {
    throw new RuntimeException('Captured collection member links must resolve to the imported post route.');
}
if (!str_contains($emitted, 'https://other.test/wordcamp-us-cheatsheet.html?view=all#posts')) {
    throw new RuntimeException('Cross-origin captured member links must remain unchanged.');
}
// The shared reader normalizes sibling state HTML without changing evidence identities.
$state = array('html' => '<form action="https://example.test:8443/search?q=a&amp;b=c#results"><a href="https://example.test:8443/post.html?x=%2F#part">Post</a><img src="https://example.test:8443/image.png"><a href="http://example.test:8443/post.html">HTTP</a><a href="https://example.test/post.html">Other port</a><a href="#part">Fragment</a><a href="?q=1">Query</a><span title=\'href="https://example.test:8443/not-a-link"\'>Text</span></form>');
$state['htmlBytes'] = strlen($state['html']);
$normalized = Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedStateUrls::rebase($state, 'https://example.test:8443/page.html');
foreach (array('action="/search?q=a&amp;b=c#results"', 'href="/post.html?x=%2F#part"', 'src="/image.png"', 'href="http://example.test:8443/post.html"', 'href="https://example.test/post.html"', 'href="#part"', 'href="?q=1"', 'href="https://example.test:8443/not-a-link"') as $attribute) {
    if (!str_contains($normalized['html'], $attribute)) throw new RuntimeException('Captured state URL normalization lost ' . $attribute);
}
if ($normalized['htmlBytes'] !== strlen($normalized['html'])) throw new RuntimeException('Corroborated HTML byte counts must remain valid after rebasing.');
$state['htmlBytes'] = 1;
if (1 !== Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedStateUrls::rebase($state, 'https://example.test:8443/')['htmlBytes']) throw new RuntimeException('Rebasing must not repair invalid capture byte counts.');

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
