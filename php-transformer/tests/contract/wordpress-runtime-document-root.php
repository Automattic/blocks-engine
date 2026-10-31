<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$artifact = array('entrypoint' => 'index.html', 'files' => array());
foreach (array('index' => 'Home', 'first' => 'First story', 'second' => 'Second story') as $slug => $title) {
    $navigation = '';
    foreach (array('prev' => 'previous', 'next' => 'next') as $rel => $type) {
        $navigation .= '<div class="wp-block-post-navigation-link post-navigation-link-' . $type . ' is-arrow-arrow"><a href="/' . $slug . '-' . $type . '" rel="' . $rel . '"><span class="post-navigation-link__arrow">→</span><span class="post-navigation-link__label">' . ucfirst($type) . ':</span> <mark class="post-navigation-link__title">Neighbor of ' . $title . '</mark></a></div>';
    }
    $html = '<html><head><title>' . $title . '</title></head><body><div class="wp-site-blocks"><header class="wp-block-template-part"><p>Publication</p></header><main class="wp-block-group"><h1 class="wp-block-post-title">' . $title . '</h1><div class="wp-block-post-date"><time datetime="2024-03-01">March 1, 2024</time></div><div class="entry-content wp-block-post-content"><p>Unique article for ' . $slug . '.</p><p>More about ' . $slug . '.</p></div><div class="comments"><p>Discussion</p></div></main><footer class="wp-block-template-part"><p>Copyright publication</p></footer></div></body></html>';
    if ('first' === $slug) {
        $comment = static fn(string $id, string $text, string $reply = ''): string => '<li id="comment-' . $id . '"><div class="wp-block-group captured-comment"><div class="wp-block-avatar"><img src="https://example.org/avatar.png" width="40"></div><div class="wp-block-group comment-body"><div class="wp-block-comment-author-name"><a href="https://example.org/author">Alice</a></div><div class="wp-block-comment-date"><time datetime="2024-03-02T10:00:00+00:00">March 2</time></div><div class="wp-block-comment-content"><p>' . $text . '</p><script>alert(1)</script><a href="javascript:alert(1)" onclick="alert(1)">Safe</a></div><div class="wp-block-comment-reply-link"><a href="?replytocom=' . $id . '">Reply</a></div></div></div>' . $reply . '</li>';
        $comments = '<div class="wp-block-comments"><h2 class="wp-block-comments-title">Captured responses</h2><ol class="wp-block-comment-template">' . $comment('249', 'Captured top-level Redmibook', '<ol class="children">' . $comment('250', 'Captured nested reply') . '</ol>') . '</ol><div id="respond" class="comment-respond"><h3 id="reply-title">Leave a Reply</h3><form id="commentform" class="comment-form" action="/wp-comments-post.php" method="post"><textarea name="comment"></textarea><input type="hidden" name="comment_post_ID" value="1"><input type="submit" value="Post Comment"></form></div></div>';
        $html = str_replace('<div class="comments">', $comments . '<div class="comments">', $html);
    }
    $html = str_replace('<div class="comments">', $navigation . '<div class="comments">', $html);
    $artifact['files'][] = array('path' => $slug . '.html', 'content' => $html, 'metadata' => array('post_type' => 'index' === $slug ? 'page' : 'post'));
}
$result = (new ArtifactCompiler())->compile($artifact)->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
WordPressSitePlan::assertValid($plan);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$single = $templates['single'];
$entities = $plan['comment_entities'];
$assert(2 === count($entities), 'Both captured comments become native records.');
$assert('first.html' === $entities[0]['source_path'] && 'comment-249' === $entities[0]['source_id'] && null === $entities[0]['parent_source_id'], 'Top-level comment keeps its page and source identity.');
$assert('comment-250' === $entities[1]['source_id'] && 'comment-249' === $entities[1]['parent_source_id'], 'Nested reply keeps its parent source id.');
$assert('Alice' === $entities[0]['author_name'] && 'https://example.org/author' === $entities[0]['author_url'] && '2024-03-02T10:00:00+00:00' === $entities[0]['date'] && 'https://example.org/avatar.png' === $entities[0]['avatar_url'], 'Captured author, date and avatar survive.');
$assert(str_contains($entities[0]['content'], '<p>Captured top-level Redmibook</p>') && !str_contains($entities[0]['content'], 'script') && !str_contains($entities[0]['content'], 'onclick'), 'Comment content preserves safe HTML only.');
foreach (array('comments', 'comments-title', 'comment-template', 'avatar', 'comment-author-name', 'comment-date', 'comment-content', 'comment-reply-link', 'post-comments-form') as $type) $assert(str_contains($single, '<!-- wp:' . $type), 'Single binds native ' . $type . '.');
$assert(str_contains($single, 'captured-comment') && str_contains($single, 'comment-body'), 'Captured comment wrapper groups survive.');
$assert(!str_contains($single, 'Captured top-level') && !str_contains($single, 'Captured nested') && !str_contains($single, 'comment-249') && !str_contains($single, '<ol'), 'Single has no static captured list.');
$assert(str_contains($single, 'wp:post-content'), 'Single must render the article body.');
$assert(str_contains($single, '"area":"header"') && str_contains($single, '"area":"footer"'), 'Single must bind both shell parts.');
$assert(str_contains($single, '<main') && str_contains($single, 'wp:post-title') && str_contains($single, 'wp:post-date') && str_contains($single, 'Discussion'), 'Single owns the main, title, date, and discussion chrome.');
$posts = array_filter($plan['pages'], static fn(array $page): bool => 'post' === $page['post_type']);
$assert(2 === count($posts), 'Both posts survive.');
$assert(2 === substr_count($single, '<!-- wp:post-navigation-link '), 'Both navigation directions are native blocks.');
$assert(str_contains($single, '"type":"previous","showTitle":true,"arrow":"arrow","label":"Previous:"') && str_contains($single, '"type":"next","showTitle":true,"arrow":"arrow","label":"Next:"'), 'Navigation attributes come from captured markup.');
$assert(!str_contains($single, 'Neighbor of') && !str_contains($single, 'href="/first-'), 'Neighbor titles and destinations must not be frozen.');
$assert(!array_filter(array_keys($templates), static fn(string $slug): bool => str_starts_with($slug, 'single-post-')), 'Navigation differences must not generate per-post templates.');
$assert(!str_contains(json_encode($result), 'blocksEnginePostContent') && !str_contains(json_encode($plan), 'blocksEnginePostContent'), 'No compiler or plan output may persist a private boundary marker.');
foreach ($posts as $post) {
    $assert('single' === ($post['route_template'] ?? 'single'), 'Every post uses the shared single lifecycle.');
    $content = $post['canonical_block_markup'];
    $assert(!str_contains($content, 'Redmibook') && !str_contains($content, 'Captured nested reply') && !str_contains($content, 'wp:comment-template'), 'Captured comments must not remain in post content.');
    $assert(!str_contains($content, 'wp:template-part') && !str_contains($content, 'wp-site-blocks'), 'Post content must not contain runtime document chrome.');
    $assert(str_contains($content, 'Unique article') && !str_contains($content, '<main') && !str_contains($content, 'Discussion'), 'Only article children belong to the post.');
}
// A comment-free post may own the shared chrome. The repeatable comment
// presentation must still be taken from a different captured post.
$reordered = $artifact;
$reordered['files'] = array($artifact['files'][0], $artifact['files'][2], $artifact['files'][1]);
$reorderedPlan = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile($reordered)->toArray());
$reorderedSingle = array_column($reorderedPlan['templates'], 'canonical_block_markup', 'slug')['single'];
$assert(str_contains($reorderedSingle, '<!-- wp:comment-template') && str_contains($reorderedSingle, 'captured-comment'), 'Shared single uses captured comments even when its chrome owner has none.');
$assert(2 === count($reorderedPlan['comment_entities']), 'Changing the chrome owner does not lose comment records.');
// A route may retain separate desktop/mobile documents. Its visibility
// wrappers must surround article children, not another copy of the site shell.
$source = $artifact['files'][2]['content'];
preg_match('~<body>(.*)</body>~s', $source, $match);
$copies = '';
foreach (array('desktop', 'mobile') as $device) {
    $copies .= '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-document-scope="">' . $match[1] . '</div>';
}
$artifact['files'][2]['content'] = str_replace($match[1], $copies, $source);
$dualPlan = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile($artifact)->toArray());
WordPressSitePlan::assertValid($dualPlan);
foreach ($dualPlan['pages'] as $post) {
    if ('post' !== $post['post_type']) continue;
    $assert('single' === ($post['route_template'] ?? 'single'), 'Dual-document posts share the single lifecycle.');
    $assert(!str_contains($post['canonical_block_markup'], 'wp:template-part') && !str_contains($post['canonical_block_markup'], '<main'), 'Neither responsive copy keeps shell chrome.');
    if ('second' === $post['slug']) {
        $assert(2 === substr_count($post['canonical_block_markup'], 'Unique article'), 'Both responsive article copies survive.');
        $assert(str_contains($post['canonical_block_markup'], 'data-blocks-engine-capture-mobile-document'), 'Responsive visibility ownership survives.');
    }
}
$assert(!str_contains(json_encode($dualPlan), 'blocksEnginePostContent'), 'Dual documents must not leak private markers.');
fwrite(STDOUT, "wordpress-runtime-document-root: ok\n");
