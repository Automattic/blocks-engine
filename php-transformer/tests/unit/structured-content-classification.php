<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$document = static fn(string $type, string $body): string => '<html><head><meta property="og:type" content="article"><meta property="article:published_time" content="2024-03-02T10:30:00Z"><script type="application/ld+json">{"@graph":[{"@type":"' . $type . '"}]}</script></head><body>' . $body . '</body></html>';
$files = array(
    'index.html' => $document('WebPage', '<main><h1>About</h1><p>Our story.</p></main>'),
    'essay/index.html' => $document('BlogPosting', '<main><article><h1>Essay</h1><p>Article body.</p></article></main>'),
    'blog/page/2/index.html' => $document('CollectionPage', '<main><a href="/essay/">Essay</a></main>'),
);
$build = static fn(array $files): array => (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray());
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$plan = $build($files);
$pages = array_column($plan['pages'], null, 'source_path');
$assert(2 === count($pages), 'One page, one post, no pagination page');
$assert('page' === $pages['index.html']['post_type'], 'WebPage overrides SEO article metadata');
$assert('post' === $pages['essay/index.html']['post_type'], 'BlogPosting is a post');
$assert('inferred' === $pages['index.html']['content_decision']['state'], 'WebPage is positive page evidence');
$assert(array(array('source' => 'json-ld:WebPage')) === $pages['index.html']['content_decision']['evidence'], 'WebPage decision names its structured type');

$files['ordinary/index.html'] = $document('WebPage', '<main><h1>Ordinary page</h1><p>Not an article.</p></main>');
$files['about/index.html'] = $document('AboutPage', '<main><h1>About us</h1></main>');
$files['contact/index.html'] = $document('ContactPage', '<main><h1>Contact</h1></main>');
$files['category/business/index.html'] = $document('CollectionPage', '<main><h1>Business</h1><a href="/essay/">Essay</a></main>');
$files['category/business/page/2/index.html'] = $document('CollectionPage', '<main><h1>Business, page two</h1></main>');
$files['collections/strips/index.html'] = $document('CollectionPage', '<main><h1>Strips</h1><a href="/essay/">Essay</a></main>');
$files['go/essay/index.html'] = '<html><head><meta http-equiv="refresh" content="0;url=/essay/"></head><body></body></html>';
$files['news/index.html'] = '<script type="application/ld+json">{"@type":"NewsArticle"}</script><main><h1>News</h1><p>News body.</p></main>';
$plan = $build($files);
$pages = array_column($plan['pages'], null, 'source_path');
$assert('page' === $pages['ordinary/index.html']['post_type'], 'Non-entry WebPage overrides publication metadata');
$assert('page' === $pages['about/index.html']['post_type'], 'AboutPage overrides publication metadata');
$assert('page' === $pages['contact/index.html']['post_type'], 'ContactPage overrides publication metadata');
$assert('post' === $pages['news/index.html']['post_type'], 'Undated structured NewsArticle is a post');
foreach (array('index.html' => 'WebPage', 'ordinary/index.html' => 'WebPage', 'about/index.html' => 'AboutPage', 'contact/index.html' => 'ContactPage') as $path => $type) {
    $assert(array('schema' => 'blocks-engine/content-decision/v1', 'state' => 'inferred', 'post_type' => 'page', 'evidence' => array(array('source' => 'json-ld:' . $type))) === $pages[$path]['content_decision'], $type . ' carries an inferred page decision');
}
foreach (array('essay/index.html' => 'BlogPosting', 'news/index.html' => 'NewsArticle') as $path => $type) {
    $decision = $pages[$path]['content_decision'];
    $assert('inferred' === $decision['state'] && 'post' === $decision['post_type'], $type . ' retains its inferred post decision');
    $assert(in_array('json-ld:' . $type, array_column($decision['evidence'], 'source'), true), $type . ' retains its structured post evidence');
}
$assert(!isset($pages['go/essay/index.html']), 'Empty redirect is not materialized');
$assert(!isset($pages['category/business/index.html']), 'Collection is not a standalone page');
$assert(!isset($pages['category/business/page/2/index.html']), 'Archive pagination is not a standalone page');
// A collection used as the entry page remains a page, with positive evidence.
$collectionPlan = $build(array('index.html' => $document('CollectionPage', '<main><h1>Collection</h1></main>')));
$assert(array('schema' => 'blocks-engine/content-decision/v1', 'state' => 'inferred', 'post_type' => 'page', 'evidence' => array(array('source' => 'json-ld:CollectionPage'))) === $collectionPlan['pages'][0]['content_decision'], 'CollectionPage carries an inferred page decision when materialized');
// A collection on a route no WordPress query resolves to an archive template
// keeps its route as a page instead of becoming an unreachable template.
$assert('page' === ($pages['collections/strips/index.html']['post_type'] ?? null), 'Collection outside a taxonomy route stays a routed page');
$assert(array('schema' => 'blocks-engine/content-decision/v1', 'state' => 'inferred', 'post_type' => 'page', 'evidence' => array(array('source' => 'json-ld:CollectionPage'))) === $pages['collections/strips/index.html']['content_decision'], 'Routed collection carries its inferred page decision');
$assert(array() === array_filter($plan['templates'], static fn(array $template): bool => 'collections/strips/index.html' === ($template['source_path'] ?? null)), 'Routed collection does not become an archive template');
$templates = array_column($plan['templates'], null, 'slug');
$assert(isset($templates['category-business']), 'Collection retains its archive presentation');
$assert(isset($templates['single']), 'Posts retain a shared single template');
fwrite(STDOUT, "structured-content-classification: passed\n");
