<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CoreSelectorScoping;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
// Reduced from mebenedetto.com's dell-xps-16-and-linux-compatibility-9640
// capture, with control state metadata and unrelated post chrome removed.
$form = '<div id="respond" class="comment-respond wp-block-post-comments-form">
<h3 id="reply-title" class="comment-reply-title">Leave a Reply</h3>
<form action="/blog/wp-comments-post.php" method="post" id="commentform" class="comment-form">
<p class="comment-notes"><span id="email-notes">Your email address will not be published.</span></p>
<p class="comment-form-comment"><label for="comment">Comment <span class="required">*</span></label> <textarea id="comment" name="comment" cols="45" rows="8" maxlength="65525" required=""></textarea></p>
<p class="comment-form-author"><label for="author">Name <span class="required">*</span></label> <input id="author" name="author" type="text" value="" size="30" maxlength="245" autocomplete="name" required=""></p>
<p class="form-submit wp-block-button"><input name="submit" type="submit" id="submit" class="wp-block-button__link wp-element-button" value="Post Comment"> <input type="hidden" name="comment_post_ID" value="1243" id="comment_post_ID"></p>
</form></div>';
// capture-05c24f1fa914be8571228cc9002c304a4f0935fd639749bd260de7db25ec5ab5.css
$selector = '.wp-block-post-comments-form .comment-form > p';
$css = '.wp-block-post-comments-form .comment-form { display: flex; flex-direction: column; gap: 1.5rem; }
' . $selector . ' { margin: 0px; }
.wp-block-post-comments-form .comment-form label { display: block; margin-bottom: 0.4rem; }';
$html = '<!doctype html><html><head><title>Post</title></head><body>' . $form . '</body></html>';
$document = str_replace('</head>', '<style>' . $css . '</style></head>', $html);
$result = (new HtmlTransformer())->transform($document)->toArray();
$assert(str_contains($result['serialized_blocks'], '<!-- wp:post-comments-form /-->'), 'form is a runtime Core block');
$assert(!str_contains($result['serialized_blocks'], '1243'), 'source post identity is not serialized');
$assets = implode("\n", array_column($result['assets'], 'content'));
$assert(str_contains($assets, $selector . ' { margin: 0px; }'), 'runtime paragraphs keep their authored selector and margin reset');
$assert(str_contains($assets, '.wp-block-post-comments-form .comment-form label { display: block; margin-bottom: 0.4rem; }'), 'runtime labels do not acquire source markers either');
(new CoreSelectorScoping())->assertScoped($css, 'runtime-comment-form-test');
$unscoped = (new HtmlTransformer())->transform(str_replace($css, '.wp-block-post-comments-form p { color: red; }', $document))->toArray();
$assert(!str_contains(implode("\n", array_column($unscoped['assets'], 'content')), '.wp-block-post-comments-form p { color: red; }'), 'bare Core rules do not bypass selector scoping');

// Conditional and mixed selector lists retain their context; unrelated subjects
// still use normal lowering, rather than bypassing projection with the form.
$mixed = '@media (min-width: 600px) {' . $selector . ', article > p { margin: 0px; }}';
$conditional = (new HtmlTransformer())->transform(str_replace('</head>', '<style>' . $mixed . '</style></head>', str_replace('</body>', '<article><p>Ordinary paragraph</p></article></body>', $html)))->toArray();
$conditionalCss = implode("\n", array_column($conditional['assets'], 'content'));
$assert(str_contains($conditionalCss, '@media (min-width: 600px)'), 'media condition survives');
$assert(str_contains($conditionalCss, $selector . '{'), 'runtime arm of mixed list survives');
$assert(!str_contains($conditionalCss, 'article > p{'), 'ordinary arm still projects source tag identity');

// Exercise the artifact path too: stylesheet projection/reconciliation must not
// discard rules just because serialized dynamic blocks have no saved children.
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => str_replace('</head>', '<link rel="stylesheet" href="/assets/css/capture.css"></head>', $html),
    'assets/css/capture.css' => $css,
)))->toArray();
$encoded = json_encode($compiled, JSON_UNESCAPED_SLASHES);
$assert(str_contains($encoded, $selector . ' { margin: 0px; }'), 'artifact assets retain the runtime margin reset');

// Similar-looking non-WordPress forms must not opt out of source projection.
$ordinary = (new HtmlTransformer())->transform(str_replace('/blog/wp-comments-post.php', '/contact', $document))->toArray();
$assert(!str_contains($ordinary['serialized_blocks'], 'wp:post-comments-form'), 'ordinary forms do not become runtime blocks');
$assert(!str_contains(implode("\n", array_column($ordinary['assets'], 'content')), $selector . ' { margin: 0px; }'), 'ordinary form rules still project normally');
fwrite(STDOUT, "wordpress-comment-form-author-rules: passed\n");
