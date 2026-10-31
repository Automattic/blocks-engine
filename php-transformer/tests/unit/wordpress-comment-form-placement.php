<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$form = '<div class="comment-respond"><h3 id="reply-title">Leave a Reply</h3><form action="/wp-comments-post.php" id="commentform" class="comment-form" method="post"><p><label>Comment<textarea name="comment"></textarea></label></p><input name="author"><input name="email" type="email"><input name="url" type="url"><input type="hidden" name="comment_post_ID" value="987654"><input type="hidden" name="_wpnonce" value="secret-nonce"><input type="submit" value="Post Comment"></form></div>';
$cases = array(
    'trailing form' => array('after' => '', 'split' => false, 'factor' => true),
    'paragraph after form' => array('after' => '<p>After the form</p>', 'split' => false, 'factor' => false),
    'second device document' => array('after' => '', 'split' => true, 'factor' => false),
    'only one post has trailing content' => array('after' => '<p>After the form</p>', 'split' => false, 'factor' => false, 'only_second' => true),
);
foreach ($cases as $name => $case) {
    $files = array('index.html' => '<html><head><title>Home</title></head><body><main><h1>Home</h1><a href="/first/">First</a><a href="/second/">Second</a></main></body></html>');
    foreach (array('first', 'second') as $slug) {
        $after = !empty($case['only_second']) && 'first' === $slug ? '' : $case['after'];
        $body = '<main><article><h1>' . ucfirst($slug) . '</h1><p>' . str_repeat($slug . ' story text. ', 30) . '</p>' . $form . $after . '</article></main>';
        if ($case['split']) $body = '<div class="desktop">' . $body . '</div><div class="phone">' . $body . '</div>';
        $files[$slug . '/index.html'] = '<html><head><title>' . $slug . '</title><meta property="og:type" content="article"><meta property="article:published_time" content="2024-03-01T00:00:00Z"></head><body>' . $body . '</body></html>';
    }
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    $plan = (new WordPressSitePlan())->fromCompilerResult($result);
    $posts = array_filter($plan['pages'], static fn(array $page): bool => 'post' === $page['post_type']);
    $assert(2 === count($posts), 'two captured posts');
    foreach ($posts as $post) {
        $expectedForms = $case['factor'] ? 0 : ($case['split'] ? 2 : 1);
        $assert($expectedForms === substr_count($post['canonical_block_markup'], 'wp:post-comments-form'), $name . ': forms stay in place unless all are trailing');
        $assert($case['factor'] === !empty($post['metadata']['native_comment_form']), $name . ': shared form metadata');
        if (str_contains($post['canonical_block_markup'], 'After the form')) {
            $assert(strpos($post['canonical_block_markup'], 'wp:post-comments-form') < strpos($post['canonical_block_markup'], 'After the form'), $name . ': original form order');
        }
    }
    $templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
    $assert(($case['factor'] ? 1 : 0) === substr_count($templates['single'], 'wp:post-comments-form'), $name . ': template only receives trailing forms');
    $all = implode('\n', array_column(array_merge($plan['pages'], $plan['templates'], $plan['template_parts']), 'canonical_block_markup'));
    $assert(!str_contains($all, 'wp:html'), 'two-post import has no HTML fallback');
}
fwrite(STDOUT, "wordpress-comment-form-placement: passed\n");
