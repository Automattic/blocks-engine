<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Blogger-style posts: the body container carries a per-post id. An id names
// one post's element, so the anchored container belongs to post content; left
// in the template it would make every post's layout unique and block factoring.
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => '<html><head><title>Journal</title></head><body><main><p>Journal home</p></main></body></html>'),
));
foreach (array('first' => array('First story', '18', '5339'), 'second' => array('Second story', '19', '8973')) as $slug => [$title, $day, $id]) {
    $artifact['files'][] = array(
        'path' => $slug . '.html',
        'content' => '<html><head><title>' . $title . '</title><meta property="article:published_time" content="2023-12-' . $day . 'T00:00:00Z"></head><body><main><article><h1>' . $title . '</h1><p class="post-date">Dec ' . $day . ', 2023</p>'
            . '<div class="post-body entry-content" id="post-body-' . $id . '"><p>Opening for ' . $slug . '.</p><p>Closing for ' . $slug . '.</p></div></article></main></body></html>',
        'metadata' => array('post_type' => 'post'),
    );
}
$plan = (new ArtifactCompiler())->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $plan, 'Anchored post bodies must produce a valid site plan.');
WordPressSitePlan::assertValid($plan);
$singles = array_values(array_filter($plan['templates'], static fn(array $template): bool => 'single' === $template['slug']));
$assert(1 === count($singles), 'The posts must share a single template.');
$single = $singles[0]['canonical_block_markup'];
$assert(str_contains($single, '<!-- wp:post-title') && str_contains($single, '<!-- wp:post-content'), 'Single must factor title and body slots: ' . $single);
$assert(!str_contains($single, 'post-body-'), 'No post-specific id may live in the shared template.');
foreach (array_filter($plan['pages'], static fn(array $page): bool => 'post' === $page['post_type']) as $post) {
    $id = str_contains($post['source_path'], 'first') ? '5339' : '8973';
    $assert(str_contains($post['canonical_block_markup'], 'post-body-' . $id) && str_contains($post['canonical_block_markup'], 'Closing for '), 'Each post keeps its anchored body container: ' . $post['source_path']);
}
fwrite(STDOUT, "post-body-wrapper-anchor: ok\n");
