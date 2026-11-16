<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A replacement section is not an absent optional section. Keep both complete
// articles page-owned even when the section has exactly the same block shape.
foreach (array('header', 'aside') as $tag) {
    $files = array('index.html' => '<html><body><main><h1>Home</h1><p>Welcome.</p></main></body></html>');
    foreach (array('First', 'Second') as $title) {
        $files[strtolower($title) . '.html'] = '<html><head><title>' . $title . '</title><meta property="og:type" content="article"><meta property="article:published_time" content="2024-01-01T00:00:00Z"></head><body><' . $tag . '><p>' . $title . ' chrome</p></' . $tag . '><main><h1>' . $title . '</h1><p class="date">1/1/2024</p><p>' . str_repeat($title . ' body. ', 40) . '</p></main></body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    $pages = array_column($plan['pages'], null, 'source_path');
    foreach (array('First', 'Second') as $title) {
        $post = $pages[strtolower($title) . '.html'];
        if ('post' !== $post['post_type'] || !str_contains($post['canonical_block_markup'], $title . ' chrome')) {
            fwrite(STDERR, 'FAIL: different ' . $tag . ' stays with ' . $title . "\n");
            exit(1);
        }
    }
}
echo "post chrome different section: passed\n";
