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

echo "taxonomy-archive-heading-names-term-element: passed\n";
