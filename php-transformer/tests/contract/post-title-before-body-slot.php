<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// An article whose unbound lead image precedes the title in the same
// container as its body: the image starts the body slot, so body after the
// title must not join it, or the template renders the title after the body.
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => '<html><head><title>Journal</title></head><body><main><p>Journal home</p></main></body></html>'),
));
foreach (array('first' => array('First story', '18'), 'second' => array('Second story', '19')) as $slug => [$title, $day]) {
    $artifact['files'][] = array('path' => $slug . '.html', 'metadata' => array('post_type' => 'post'),
        'content' => '<html><head><title>' . $title . '</title><meta property="article:published_time" content="2023-12-' . $day . 'T00:00:00Z"></head><body><main>'
        . '<section class="hero"><h2>Blog</h2></section><div class="rte"><img src="' . $slug . '.jpg" alt="Lead ' . $slug . '"><h1>' . $title . '</h1>'
        . '<p>Intro for ' . $slug . '.</p><h3>Section of ' . $slug . '</h3><p>Closing for ' . $slug . '.</p></div></main></body></html>');
}
$plan = (new ArtifactCompiler())->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'] ?? array();
WordPressSitePlan::assertValid($plan);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
foreach (array_filter($plan['pages'], static fn(array $page): bool => 'post' === $page['post_type']) as $post) {
    $rendered = preg_replace('/<!-- wp:post-title(?:\s+\{.*?\})?\s*\/-->/', $post['title'], $templates[$post['route_template'] ?? 'single'] ?? '') ?? '';
    $rendered = preg_replace_callback('/<!-- wp:post-content(?:\s+\{.*?\})?\s*\/-->/', static fn(): string => $post['canonical_block_markup'], $rendered) ?? '';
    $slug = basename($post['source_path'], '.html');
    $order = array_map(static fn(string $text): int|false => strpos($rendered, $text), array($post['title'], 'Intro for ' . $slug, 'Section of ' . $slug, 'Closing for ' . $slug));
    $sorted = $order;
    sort($sorted);
    $assert(!in_array(false, $order, true) && $sorted === $order, $post['source_path'] . ' renders its title and body out of source order: ' . json_encode($order));
}
fwrite(STDOUT, "post-title-before-body-slot: ok\n");
