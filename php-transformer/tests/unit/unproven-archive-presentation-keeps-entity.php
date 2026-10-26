<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$files = array('index.html' => '<h1>Home</h1>');
$add = static function (string $path, string $route, string $html, array $metadata = array()) use (&$files): void {
    $files[] = array('path' => $path, 'content' => $html, 'metadata' => array_merge($metadata, array('route_path' => $route)));
};
$add('category/programming.html', '/category/programming', '<main><h1>Programming</h1><article><h2><a href="/first">First</a></h2><p>First summary.</p></article></main>');
// Navigation proves reciprocal membership, but is not a projectable listing.
$add('category/programming/wordpress.html', '/category/programming/wordpress', '<main><h1>WordPress</h1><nav><a href="/first">First</a></nav></main>');
$add('category/programming/wordpress/page-two.html', '/category/programming/wordpress/page/2', '<main><h1>WordPress</h1><nav><a href="/second">Second</a></nav></main>');
$add('author/adam.html', '/author/adam', '<main><h1>Author: <span>Adam</span></h1><nav><a href="/first">First</a></nav></main>');
$add('author/page-two.html', '/author/adam/page/2', '<main><h1>Author: <span>Adam</span></h1><nav><a href="/second">Second</a></nav></main>');
foreach (array('first' => 'First', 'second' => 'Second') as $slug => $title) {
    $add($slug . '.html', '/' . $slug, '<article><h1>' . $title . '</h1><p>' . $title . ' full story.</p><a href="/category/programming">Programming</a><a href="/category/programming/wordpress">WordPress</a><a href="/author/adam">Adam</a></article>', array('post_type' => 'post'));
}
// An ordinary descendant must not keep a parent that becomes an archive.
$add('category/programming/wordpress/about.html', '/category/programming/wordpress/about', '<h1>About this collection</h1>');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$terms = array_column($plan['taxonomy_entities'], null, 'slug');
$assert(isset($terms['programming'], $terms['wordpress']), 'Both taxonomy entities survive presentation failure.');
$assert('Programming' === $terms['programming']['name'] && 'WordPress' === $terms['wordpress']['name'] && 'programming' === $terms['wordpress']['parent'], 'Term identity and parent survive.');
$assert(isset($terms['programming']['archive']['presentation_markup']) && !array_key_exists('presentation_markup', $terms['wordpress']['archive']), 'Only the projected parent carries presentation.');
$assert(array('first.html', 'second.html') === $terms['wordpress']['membership_source_paths'], 'Child retains members from its pagination capture.');
$author = $plan['author_entities'][0] ?? array();
$assert('adam' === ($author['slug'] ?? null) && 'Adam' === $author['name'] && array('first.html', 'second.html') === $author['membership_source_paths'], 'Unprojectable author keeps identity and all membership.');
$assert(!array_key_exists('presentation_markup', $author['archive']), 'Unprojectable author has no presentation.');
$pages = array_column($plan['pages'], null, 'source_path');
$archiveSources = array('category/programming.html', 'category/programming/wordpress.html', 'author/adam.html');
foreach (array_merge($archiveSources, array('category/programming/wordpress/page-two.html', 'author/page-two.html')) as $source) {
    $assert(!isset($pages[$source]), 'Archive and pagination source is not a plan page: ' . $source);
    $assert(!in_array($source, array_column($plan['operations'], 'source_path'), true), 'Archive and pagination source has no page operation.');
}
foreach ($pages as $page) {
    $parent = $page['parent_source_path'];
    $assert('' === $parent || isset($pages[$parent]), 'Every page parent materializes.');
    $assert(!in_array($parent, $archiveSources, true), 'No page is parented to an archive.');
}
$slugs = array_column($plan['templates'], 'slug');
$assert(in_array('category-programming', $slugs, true) && !in_array('category-wordpress', $slugs, true) && !in_array('author-adam', $slugs, true), 'Only a proven presentation emits a per-entity template.');
foreach (array('taxonomy' => 'category/programming/wordpress.html', 'author' => 'author/adam.html') as $kind => $source) {
    $findings = array_values(array_filter($plan['diagnostics'], static fn(array $d): bool => 'wordpress_site_plan_' . $kind . '_archive_presentation_template_hierarchy' === $d['code'] && $source === ($d['source_path'] ?? null)));
    $assert(1 === count($findings) && 'info' === $findings[0]['severity'] && isset($findings[0]['source_route']) && str_contains($findings[0]['message'], 'template hierarchy'), 'One source-owned info diagnostic describes the presentation fallback.');
}
$assert(!str_contains(json_encode($plan['diagnostics']), '_archive_query_unproven'), 'Retired query-unproven diagnostic is not emitted.');
WordPressSitePlan::assertValid($plan);
foreach (array('taxonomy_entities', 'author_entities') as $kind) {
    $invalid = $plan;
    $index = 'taxonomy_entities' === $kind ? array_search('wordpress', array_column($invalid[$kind], 'slug'), true) : 0;
    $invalid[$kind][$index]['archive']['presentation_markup'] = null;
    try {
        WordPressSitePlan::assertValid($invalid);
        throw new RuntimeException('Null presentation was accepted instead of requiring omission.');
    } catch (InvalidArgumentException) {
    }
}
$bootstrap = array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];
$assert(str_contains($bootstrap, 'category/programming/wordpress/page/') && str_contains($bootstrap, 'category_name=wordpress&paged='), 'Fallback term retains pagination query ownership.');
echo "unproven-archive-presentation-keeps-entity: passed\n";
