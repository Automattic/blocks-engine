<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Blogger wraps authored lines in <div class="separator">. A separator class
// marks a divider only while it renders no words, so these wrappers are post
// text: posts factor into one single template and each keeps its own lines.
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => '<html><head><title>Journal</title></head><body><main><p>Journal home</p></main></body></html>'),
));
foreach (array('first' => array('First story', '18', '5339'), 'second' => array('Second story', '19', '8973')) as $slug => [$title, $day, $id]) {
    $artifact['files'][] = array(
        'path' => $slug . '.html',
        'content' => '<html><head><title>' . $title . '</title><meta property="article:published_time" content="2023-12-' . $day . 'T00:00:00Z"></head><body><main><article><h1>' . $title . '</h1><p class="post-date">Dec ' . $day . ', 2023</p>'
            . '<div class="post-body entry-content" id="post-body-' . $id . '"><div class="separator">Opening for ' . $slug . '.</div><div class="separator">Middle for ' . $slug . '.</div><div class="separator">Images courtesy of the ' . $slug . ' publisher</div></div></article></main></body></html>',
        'metadata' => array('post_type' => 'post'),
    );
}
$plan = (new ArtifactCompiler())->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $plan, 'Separator-wrapped post text must produce a valid site plan.');
WordPressSitePlan::assertValid($plan);
$singles = array_values(array_filter($plan['templates'], static fn(array $template): bool => 'single' === $template['slug']));
$assert(1 === count($singles) && str_contains($singles[0]['canonical_block_markup'], '<!-- wp:post-title'), 'The posts must factor into one single template.');
$assert(!str_contains($singles[0]['canonical_block_markup'], 'courtesy'), 'Post text must not live in the shared template.');
foreach (array_filter($plan['pages'], static fn(array $page): bool => 'post' === $page['post_type']) as $post) {
    $slug = str_contains($post['source_path'], 'first') ? 'first' : 'second';
    foreach (array('Opening for ' . $slug, 'Images courtesy of the ' . $slug . ' publisher') as $text) {
        $assert(str_contains($post['canonical_block_markup'], $text), 'Each post keeps its separator-wrapped text "' . $text . '": ' . $post['source_path']);
    }
}
fwrite(STDOUT, "post-separator-class-text: ok\n");
