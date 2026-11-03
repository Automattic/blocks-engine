<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => '<html><head><title>Journal</title></head><body><main><p>Journal home</p></main></body></html>'),
));
foreach (array('first' => array('First story', 'Dec 18, 2023'), 'second' => array('Second story', 'Dec 19, 2023')) as $slug => [$title, $date]) {
    $artifact['files'][] = array(
        'path' => $slug . '.html',
        'content' => '<html><head><title>' . $title . '</title><meta property="article:published_time" content="2023-12-' . ('first' === $slug ? '18' : '19') . 'T00:00:00Z"></head><body><main><article><h1>' . $title . '</h1><div class="post-date"><div>' . $date . '</div></div><p>Unique body for ' . $slug . '.</p></article></main></body></html>',
        'metadata' => array('post_type' => 'post'),
    );
}
$result = (new ArtifactCompiler())->compile($artifact)->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $plan, 'Wrapper dates must produce a valid site plan.');
WordPressSitePlan::assertValid($plan);
$singles = array_values(array_filter($plan['templates'], static fn(array $template): bool => 'single' === $template['slug']));
$assert(1 === count($singles), 'The posts must share a single template.');
$single = $singles[0]['canonical_block_markup'];
$assert(1 === preg_match('~<div[^>]*class="[^"]*\bpost-date\b[^"]*"[^>]*>(.*?)</div>~s', $single, $wrapper), 'The date wrapper must remain in single.');
$assert(str_contains($wrapper[1], '<!-- wp:post-date'), 'The wrapper must contain a native post date.');
$assert(!str_contains($wrapper[1], '<!-- wp:post-content'), 'Post content must not inherit the date wrapper.');
$assert(str_contains($single, '<!-- wp:post-title') && str_contains($single, '<!-- wp:post-content'), 'Single must retain title and body slots.');
$posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === $page['post_type']));
$assert(2 === count($posts), 'Both source posts must survive.');
foreach ($posts as $post) {
    $assert(!str_contains($post['canonical_block_markup'], 'Dec '), 'Literal publication dates must leave post content.');
    $assert(str_contains($post['canonical_block_markup'], 'Unique body for '), 'Article text must remain in post content.');
}
fwrite(STDOUT, "wrapper-class-post-date: ok\n");
