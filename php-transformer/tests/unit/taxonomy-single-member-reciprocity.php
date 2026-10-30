<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TaxonomyProjection;

// A single reciprocal member proves a term only with a matching label from a post.
$documents = array(
    array('source_path' => 'archive.html', 'html' => '<h1>Writing</h1><a href="/story">Story</a>'),
    array('source_path' => 'story.html', 'metadata' => array('post_type' => 'post'), 'html' => '<a href="/category/writing">Writing</a>'),
);
$routes = array(
    array('source_path' => 'archive.html', 'target_path' => '/category/writing'),
    array('source_path' => 'story.html', 'target_path' => '/story'),
);
$result = TaxonomyProjection::project($documents, $routes);
if ('Writing' !== ($result['entities'][0]['name'] ?? null)) throw new RuntimeException('The archive heading names the proven term.');
$documents[1]['html'] = '<a href="/category/writing">Different name</a>';
if (array() !== TaxonomyProjection::project($documents, $routes)['entities']) throw new RuntimeException('A mismatched backlink label cannot prove even a single-member category.');
$documents[1]['html'] = '<a href="/category/writing">Writing</a>';
unset($documents[1]['metadata']);
if (array() !== TaxonomyProjection::project($documents, $routes)['entities']) throw new RuntimeException('A reciprocal ordinary page cannot prove post membership.');
echo "taxonomy-single-member-reciprocity: passed\n";
