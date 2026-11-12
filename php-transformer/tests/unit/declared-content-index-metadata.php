<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A content index declares each post's slug, dates, author and terms, and
// names a term archive route. The declaration is explicit: no HTML date
// clues or reciprocal archive links are needed.
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$compile = static function (bool $declared): array {
    $files = array();
    $add = static function (string $path, string $route, string $body, array $metadata = array()) use (&$files): void {
        $files[] = array('path' => $path, 'content' => '<html><head><title>' . strip_tags($body) . '</title></head><body><main>' . $body . '</main></body></html>', 'metadata' => $metadata + array('route_path' => $route));
    };
    $add('index.html', '/', '<h1>Home</h1>');
    $posts = array(
        'one' => array('first-story', '2021-03-04T10:00:00-05:00', array('Travel', 'Food')),
        'two' => array('second-story', '2021-04-05T08:30:00Z', array('Travel')),
        'three' => array('third-story', '2021-05-06', array('Food')),
    );
    foreach ($posts as $file => [$slug, $published, $terms]) {
        $add('2021/' . $file . '.html', '/2021/' . $file, '<h1>Story ' . $file . '</h1><p>Body.</p>', $declared ? array('post_type' => 'post', 'declared_content' => array('slug' => $slug, 'published' => $published, 'updated' => '2022-01-01T00:00:00Z', 'author' => 'Ada', 'terms' => $terms)) : array());
    }
    $add('search/label/travel.html', '/search/label/travel', '<h1>Posts labelled Travel</h1><article><h2><a href="/2021/one">Story one</a></h2></article><article><h2><a href="/2021/two">Story two</a></h2></article>', $declared ? array('declared_content' => array('term_archive' => array('name' => 'Travel'), 'slug' => 42)) : array());
    $add('about.html', '/about', '<h1>About</h1><p>Undeclared page.</p>', $declared ? array('declared_content' => array('slug' => '', 'published' => 'yesterday', 'terms' => 'Travel')) : array());
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    if (!isset($result['source_reports']['wordpress_site_plan'])) throw new RuntimeException('Fixture must produce a valid plan: ' . json_encode($result['diagnostics'] ?? $result));
    return $result['source_reports']['wordpress_site_plan'];
};

$plan = $compile(true);
WordPressSitePlan::assertValid($plan);
$pages = array_column($plan['pages'], null, 'source_path');
$expected = array('2021/one.html' => array('first-story', '2021-03-04T15:00:00Z'), '2021/two.html' => array('second-story', '2021-04-05T08:30:00Z'), '2021/three.html' => array('third-story', '2021-05-06T00:00:00Z'));
foreach ($expected as $source => [$slug, $timestamp]) {
    $page = $pages[$source] ?? array();
    $assert('post' === ($page['post_type'] ?? null), 'Declared post is a post: ' . $source);
    $assert($slug === ($page['slug'] ?? null), 'Declared slug names the row: ' . $source . ' => ' . ($page['slug'] ?? ''));
    $assert($timestamp === ($page['publication_timestamp'] ?? null), 'Declared publication timestamp: ' . $source . ' => ' . ($page['publication_timestamp'] ?? ''));
    $assert('declared' === $page['content_decision']['state'] && 'metadata:content_index' === $page['content_decision']['provenance'], 'Declared decision provenance: ' . $source);
    $assert('2022-01-01T00:00:00Z' === ($page['modified_timestamp'] ?? null) && 'Ada' === ($page['author_name'] ?? null), 'Declared modified time and author reach the row: ' . $source);
}
$assert('2021-03-04T10:00:00-05:00' === ($pages['2021/one.html']['publication_local_time'] ?? null), 'Declared offset keeps its local time.');
$assert(!isset($pages['search/label/travel.html']), 'The declared term archive is not a document.');
$terms = array_column($plan['taxonomy_entities'], null, 'slug');
$assert(array('food', 'travel') === array_values(array_intersect(array('food', 'travel'), array_keys($terms))) && 2 === count($terms), 'Two declared tags: ' . json_encode(array_keys($terms)));
$assert('post_tag' === $terms['travel']['taxonomy'] && 'Travel' === $terms['travel']['name'] && array('2021/one.html', '2021/two.html') === $terms['travel']['membership_source_paths'], 'Travel memberships.');
$assert('post_tag' === $terms['food']['taxonomy'] && 'Food' === $terms['food']['name'] && array('2021/one.html', '2021/three.html') === $terms['food']['membership_source_paths'], 'Food memberships.');
$assert('/search/label/travel' === ($terms['travel']['archive']['source_route'] ?? null) && 'search/label/travel.html' === $terms['travel']['archive']['source_path'], 'The archive file is the term source route.');
$assert(array('membership' => true, 'name' => true, 'archive' => true) === $terms['travel']['evidence'] && array('membership' => true, 'name' => true) === $terms['food']['evidence'] && !isset($terms['food']['archive']), 'Evidence shapes match the importer contract.');
// Invalid declarations are dropped: the page behaves as undeclared.
$about = $pages['about.html'];
$assert('page' === $about['post_type'] && 'about' === $about['slug'] && !isset($about['metadata']['declared_content']) && !isset($about['publication_timestamp']) && 'metadata:content_index' !== ($about['content_decision']['provenance'] ?? null), 'Invalid declarations are dropped.');

// Undeclared files compile as before: route slugs, no declared rows, no proof-less terms.
$baseline = $compile(false);
WordPressSitePlan::assertValid($baseline);
$basePages = array_column($baseline['pages'], null, 'source_path');
$assert('one' === $basePages['2021/one.html']['slug'] && !isset($basePages['2021/one.html']['modified_timestamp']) && !isset($basePages['2021/one.html']['author_name']), 'Undeclared slug comes from the route.');
$assert(isset($basePages['search/label/travel.html']) && array() === $baseline['taxonomy_entities'], 'Undeclared label listings stay documents.');
foreach (array('slug', 'post_type', 'metadata', 'content_decision', 'content_hash', 'route') as $key) $assert(($basePages['about.html'][$key] ?? null) === ($about[$key] ?? null), 'An invalid declaration compiles as an undeclared page: ' . $key);
echo "declared-content-index-metadata: passed\n";
