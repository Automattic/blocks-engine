<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TaxonomyProjection;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$post = static fn(string $title, string $term, string $label): string => '<main><h1>' . $title . '</h1><a href="/category/' . $term . '/" rel="tag">' . $label . '</a></main>';
$archive = static fn(string $heading): string => '<main><h1>' . $heading . '</h1><a href="/2024/01/01/first/">First</a><a href="/2024/01/02/second/">Second</a></main>';
$project = static function (string $heading) use ($post, $archive): array {
    $documents = array(
        array('source_path' => '2024/01/01/first/index.html', 'html' => $post('First', 'programming', 'Programming'), 'metadata' => array('post_type' => 'post')),
        array('source_path' => '2024/01/02/second/index.html', 'html' => $post('Second', 'programming', 'Programming'), 'metadata' => array('post_type' => 'post')),
        array('source_path' => 'category/programming/index.html', 'html' => $archive($heading), 'metadata' => array('post_type' => 'page')),
    );
    $routes = array(
        array('source_path' => '2024/01/01/first/index.html', 'target_path' => '/2024/01/01/first'),
        array('source_path' => '2024/01/02/second/index.html', 'target_path' => '/2024/01/02/second'),
        array('source_path' => 'category/programming/index.html', 'target_path' => '/category/programming'),
    );
    return TaxonomyProjection::project($documents, $routes);
};

// The term name is its own element beside an archive prefix.
$prefixed = $project('Category: <span>Programming</span>');
$assert(1 === count($prefixed['entities']), 'A prefixed archive heading whose term name is its own element proves the term.');
$assert('Programming' === $prefixed['entities'][0]['name'] && 'programming' === $prefixed['entities'][0]['slug'], 'The term is named by the element, not the prefixed heading text.');
$assert(2 === count($prefixed['entities'][0]['membership_source_paths']), 'Both reciprocal posts are members.');

// A plain heading still proves the term exactly as before.
$plain = $project('Programming');
$assert(1 === count($plain['entities']) && 'Programming' === $plain['entities'][0]['name'], 'A plain heading still names the term.');

// Prefix text that is not marked up separately is not stripped by guessing.
$unmarked = $project('Category: Programming');
$assert(array() === $unmarked['entities'], 'An unmarked prefix is not guessed away.');

// A heading made of several named elements has no single term element.
$several = $project('<span>Category:</span> <span>Programming</span>');
$assert(array() === $several['entities'], 'A heading with several named elements does not pick one.');

// Exercise archive proof and routing through the public compiler, including
// descriptive headings, absent captures and inconsistent return-link labels.
foreach (array(
    array('tag', 'post_tag', 2, 'Topics', 'All about topics', 'Topics', true),
    array('category', 'category', 2, 'Topics', 'All about topics', 'Topics', true),
    array('category', 'category', 1, 'Topics', 'Topics', '', true),
    array('tag', 'post_tag', 0, 'Topics', 'All about topics', 'Topics', false),
    array('category', 'category', 0, 'Topics', 'All about topics', 'Topics', false),
    array('author', null, 0, 'Topics', 'All about topics', 'Topics', false),
    array('tag', 'post_tag', 2, 'Different', 'All about topics', 'Topics', false),
    array('tag', 'post_tag', 2, 'Topics', 'All about topics', '', false),
) as [$base, $taxonomy, $memberCount, $secondLabel, $heading, $schemaName, $proven]) {
    $source = $base . '/topics/index.html';
    $route = '/' . $base . '/topics';
    $files = array('index.html' => '<h1>Home</h1>', $source => '<script type="application/ld+json">' . json_encode(array('@graph' => array(array('@type' => 'CollectionPage', 'name' => $schemaName)))) . '</script><main><h1>' . $heading . '</h1><article><h2><a href="/first/">First</a></h2><p>First summary.</p></article><article><h2><a href="/second/">Second</a></h2><p>Second summary.</p></article></main>');
    $expectedMembers = array();
    foreach (array_slice(array('first' => 'Topics', 'second' => $secondLabel), 0, $memberCount, true) as $slug => $label) {
        $expectedMembers[] = $slug . '/index.html';
        $files[$slug . '/index.html'] = '<script type="application/ld+json">{"@type":"BlogPosting"}</script><main><h1>' . ucfirst($slug) . '</h1><p>Story body.</p><a href="' . $route . '/" rel="tag">' . $label . '</a></main>';
    }
    $compiled = (new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    $plan = $compiled['source_reports']['wordpress_site_plan'];
    $entities = array_merge($plan['taxonomy_entities'], $plan['author_entities']);
    $templates = array_filter($plan['templates'], static fn(array $template): bool => $source === ($template['source_path'] ?? null));
    $pages = array_column($plan['pages'], null, 'source_path');
    $case = $base . ': ' . $memberCount . ' members, ' . $secondLabel . ', schema name ' . $schemaName;
    if ($proven) {
        $assert(1 === count($entities), 'Reciprocal collection proves one term: ' . $case);
        $term = $entities[0];
        $assert($taxonomy === $term['taxonomy'] && 'Topics' === $term['name'] && 'topics' === $term['slug'], 'Return label names the correct taxonomy term: ' . $case);
        $assert($expectedMembers === $term['membership_source_paths'], 'Captured reciprocal members are retained: ' . $case);
        $assert(array('post_type' => 'post', 'taxonomy' => $taxonomy, 'term' => 'topics') === $term['archive']['query'], 'Archive query targets the term: ' . $case);
        $assert(!isset($pages[$source]) && 1 === count($templates) && $base . '-topics' === array_values($templates)[0]['slug'], 'Proven term owns its archive template instead of a page: ' . $case);
    } else {
        $assert(array() === $entities && array() === $templates, 'Unproven collection has neither entity nor archive template: ' . $case);
        $assert('page' === ($pages[$source]['post_type'] ?? null) && $route === $pages[$source]['route']['path'], 'Unproven collection retains its routed page: ' . $case);
        $assert(array() !== array_filter($plan['diagnostics'], static fn(array $diagnostic): bool => $source === ($diagnostic['source_path'] ?? null)), 'Unproven collection has a source-local diagnostic: ' . $case);
    }
}

echo "taxonomy-archive-heading-names-term-element: passed\n";
