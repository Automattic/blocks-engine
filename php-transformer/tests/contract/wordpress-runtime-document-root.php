<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$artifact = array('entrypoint' => 'index.html', 'files' => array());
foreach (array('index' => 'Home', 'first' => 'First story', 'second' => 'Second story') as $slug => $title) {
    $html = '<html><head><title>' . $title . '</title></head><body><div class="wp-site-blocks"><header class="wp-block-template-part"><p>Publication</p></header><main class="wp-block-group"><h1 class="wp-block-post-title">' . $title . '</h1><div class="wp-block-post-date"><time datetime="2024-03-01">March 1, 2024</time></div><div class="entry-content wp-block-post-content"><p>Unique article for ' . $slug . '.</p><p>More about ' . $slug . '.</p></div><div class="comments"><p>Discussion</p></div></main><footer class="wp-block-template-part"><p>Copyright publication</p></footer></div></body></html>';
    $artifact['files'][] = array('path' => $slug . '.html', 'content' => $html, 'metadata' => array('post_type' => 'index' === $slug ? 'page' : 'post'));
}
$result = (new ArtifactCompiler())->compile($artifact)->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
WordPressSitePlan::assertValid($plan);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$single = $templates['single'];
$assert(str_contains($single, 'wp:post-content'), 'Single must render the article body.');
$assert(str_contains($single, '"area":"header"') && str_contains($single, '"area":"footer"'), 'Single must bind both shell parts.');
$assert(str_contains($single, '<main') && str_contains($single, 'wp:post-title') && str_contains($single, 'wp:post-date') && str_contains($single, 'Discussion'), 'Single owns the main, title, date, and discussion chrome.');
$posts = array_filter($plan['pages'], static fn(array $page): bool => 'post' === $page['post_type']);
$assert(2 === count($posts), 'Both posts survive.');
foreach ($posts as $post) {
    $content = $post['canonical_block_markup'];
    $assert(!str_contains($content, 'wp:template-part') && !str_contains($content, 'wp-site-blocks'), 'Post content must not contain runtime document chrome.');
    $assert(str_contains($content, 'Unique article') && !str_contains($content, '<main') && !str_contains($content, 'Discussion'), 'Only article children belong to the post.');
}
fwrite(STDOUT, "wordpress-runtime-document-root: ok\n");
