<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Reduced reproduction from Blocks Engine #6082: both routes share a site title.
foreach (array('declared empty' => array('title' => ''), 'undeclared' => null) as $case => $declared) {
    $files = array(
        array('path' => 'index.html', 'content' => '<html><head><title>Blog</title></head><body><h1>Home</h1></body></html>'),
        array(
            'path' => '2024/01/post.html',
            'content' => '<html><head><title>Blog</title></head><body><main><article><div class="entry-content"><p>Body.</p></div><div id="comments"><h3>Comments</h3></div></article></main></body></html>',
            'metadata' => null === $declared ? array() : array('declared_content' => $declared),
        ),
    );
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? null;
    if (!is_array($plan)) throw new RuntimeException('Fixture must produce a site plan: ' . json_encode($result['diagnostics'] ?? $result));
    $pages = array_column($plan['pages'], null, 'source_path');
    $post = $pages['2024/01/post.html'] ?? array();
    $title = $post['title'] ?? null;
    if (!is_string($title) || 'Comments' === $title || (null !== $declared && '' !== $title)) {
        throw new RuntimeException($case . ': expected ' . (null !== $declared ? 'an empty title' : 'no Comments title') . ', got ' . json_encode($title));
    }
    if (null !== $declared && ($post['metadata']['declared_content'] ?? null) !== $declared) {
        throw new RuntimeException('An empty title alone must survive declared-content sanitization.');
    }
}
echo "declared-content-title: passed\n";
