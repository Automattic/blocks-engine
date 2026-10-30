<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$form = '<div class="comment-respond"><h3 id="reply-title">Leave a Reply</h3><form action="/wp-comments-post.php" id="commentform" class="comment-form" method="post"><p><label>Comment<textarea name="comment"></textarea></label></p><input name="author"><input name="email" type="email"><input name="url" type="url"><input type="hidden" name="comment_post_ID" value="987654"><input type="hidden" name="_wpnonce" value="secret-nonce"><input type="submit" value="Post Comment"></form></div>';
$converted = (new HtmlTransformer())->transform($form)->toArray();
$markup = $converted['serialized_blocks'];
$assert(str_contains($markup, '<!-- wp:post-comments-form /-->'), 'dynamic form survives serialization');
$assert(str_contains($markup, '<!-- wp:comments -->'), 'comments context wraps the form');
$assert(!str_contains($markup, 'wp:html') && !str_contains($markup, 'Leave a Reply'), 'no HTML fallback or duplicate heading');
$assert(!str_contains($markup, '987654') && !str_contains($markup, 'secret-nonce'), 'captured secrets are retired');
foreach ($converted['diagnostics'] as $diagnostic) $assert(!str_starts_with($diagnostic['code'], 'wp_block_validity_'), 'valid block serialization');
$loggedIn = (new HtmlTransformer())->transform('<form class="comment-form" action="https://example.test/blog/wp-comments-post.php"><textarea name="comment"></textarea><input type="hidden" name="comment_post_ID" value="123"></form>')->toArray();
$assert(str_contains($loggedIn['serialized_blocks'], 'wp:post-comments-form'), 'logged-in form and subdirectory endpoint recognized');
foreach (array(str_replace('/wp-comments-post.php', '/contact', $form), str_replace('name="comment"', 'name="message"', $form)) as $other) {
    $result = (new HtmlTransformer())->transform($other)->toArray();
    $assert(!str_contains($result['serialized_blocks'], 'wp:post-comments-form'), 'non-WordPress form remains unaffected');
    $assert(str_contains($result['serialized_blocks'], 'Leave a Reply'), 'unrelated heading survives');
}
foreach (array(false, true) as $split) {
    $files = array('index.html' => '<html><head><title>Home</title></head><body><main><h1>Home</h1><a href="/first/">First</a><a href="/second/">Second</a></main></body></html>');
    foreach (array('first', 'second') as $slug) {
        $body = '<main><article><h1>' . ucfirst($slug) . '</h1><p>' . str_repeat($slug . ' story text. ', 30) . '</p>' . $form . '</article></main>';
        if ($split) $body = '<div class="desktop">' . $body . '</div><div class="phone">' . $body . '</div>';
        $files[$slug . '/index.html'] = '<html><head><title>' . $slug . '</title><meta property="og:type" content="article"><meta property="article:published_time" content="2024-03-01T00:00:00Z"></head><body>' . $body . '</body></html>';
    }
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    $plan = (new WordPressSitePlan())->fromCompilerResult($result);
    $posts = array_filter($plan['pages'], static fn(array $page): bool => 'post' === $page['post_type']);
    $assert(2 === count($posts), 'two captured posts');
    foreach ($posts as $post) $assert(!str_contains($post['canonical_block_markup'], 'wp:post-comments-form'), 'form removed from post content');
    $templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
    $assert(1 === substr_count($templates['single'], 'wp:post-comments-form'), 'one shared form in single template');
    $all = implode('\n', array_column(array_merge($plan['pages'], $plan['templates'], $plan['template_parts']), 'canonical_block_markup'));
    $assert(!str_contains($all, 'wp:html'), 'two-post import has no HTML fallback');
}
fwrite(STDOUT, "wordpress-comment-form: passed\n");
