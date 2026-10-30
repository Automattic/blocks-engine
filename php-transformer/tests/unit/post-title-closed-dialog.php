<?php
declare(strict_types=1);

/**
 * A captured sign-up dialog sits closed (hidden) ahead of the article on
 * every post, and every post shares one generic document title. The posts
 * are named by their visible headings, and the single template places
 * post-title and post-content outside the closed dialog, so the article
 * renders instead of staying hidden with it.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (!$condition) throw new RuntimeException($message . ('' !== $detail ? ' - ' . $detail : ''));
};

$post = static fn (string $slug, string $name, bool $shareTitle): string => '<!doctype html><html><head><title>Example</title>'
    . ($shareTitle ? '<meta property="og:title" content="' . $name . '">' : '') . '</head><body>'
    . '<div class="data-blocks-engine-capture-desktop-document" data-blocks-engine-capture-document-scope=""><div class="app"><p><a href="/">Example</a></p>'
    . '<div class="data-blocks-engine-capture-dialog" role="dialog" aria-modal="true" hidden data-blocks-engine-capture-dialog-panel="dialog-0"><div class="panel"><h2>Join us.</h2><p><a href="/signup?from=' . $slug . '">Sign up</a></p><p>Create an account to keep reading ' . $name . '.</p></div></div>'
    . '<div class="byline"><h2>Jane Doe</h2></div>'
    . '<article><h1>' . $name . '</h1><p class="date">Dec 1, 2017</p><p>' . $name . ' body text.</p></article>'
    . '<p>Footer</p></div></div></body></html>';
$compile = static function (bool $shareTitle) use ($post): array {
    $files = array(
        array('path' => 'index.html', 'content' => '<!doctype html><html><head><title>Example</title></head><body><main><h1>Jane Doe</h1><h2><a href="first/index.html">First story</a></h2><h2><a href="second/index.html">Second story</a></h2></main></body></html>'),
        array('path' => 'first/index.html', 'content' => $post('first', 'First story', $shareTitle), 'metadata' => array('post_type' => 'post')),
        array('path' => 'second/index.html', 'content' => $post('second', 'Second story', $shareTitle), 'metadata' => array('post_type' => 'post')),
    );
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
};

$plan = $compile(true);
$titles = array_column($plan['pages'], 'title', 'source_path');
$assert('First story' === ($titles['first/index.html'] ?? null), 'The share title names the visible heading, not the closed dialog or byline', (string) json_encode($titles));
$assert('Second story' === ($titles['second/index.html'] ?? null), 'Each post owns its visible heading', (string) json_encode($titles));

$single = (string) (array_column($plan['templates'], null, 'slug')['single']['canonical_block_markup'] ?? '');
$assert(str_contains($single, '<!-- wp:post-title') && str_contains($single, '<!-- wp:post-content'), 'The article chrome factors into one single template', $single);
$assert(!str_contains($single, 'role="dialog"'), 'The single template places neither post-title nor post-content inside the closed dialog', $single);
foreach ($plan['pages'] as $page) {
    if ('post' !== $page['post_type']) continue;
    $assert(str_contains($page['canonical_block_markup'], 'keep reading ' . $page['title'] . '.'), 'Each post keeps its own closed dialog in its body', $page['canonical_block_markup']);
    $assert(str_contains($page['canonical_block_markup'], $page['title'] . ' body text.'), 'Each post keeps its article body', $page['canonical_block_markup']);
}

// Without a share title, a heading inside the closed dialog still never names the post.
$titles = array_column($compile(false)['pages'], 'title', 'source_path');
$assert('Join us.' !== ($titles['first/index.html'] ?? null), 'A closed dialog heading never names the post', (string) json_encode($titles));

echo "post title closed dialog: passed\n";
