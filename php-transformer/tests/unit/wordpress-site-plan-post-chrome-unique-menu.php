<?php
declare(strict_types=1);

// Article factoring keeps one post-content slot per post and moves the rest
// of the first post's chrome into the single template. A chrome menu unique
// to one post (an "On this page" table of contents inside a later article
// section) is dropped with that section's template copy, so it is in neither
// the single template nor that post's body, and its navigation entity
// was left with no reference and the plan failed with "WordPress site plan
// navigation entities require explicit owned references." (#2838). Every menu
// a post renders must survive in the single template or the post body;
// otherwise the articles stay page-owned.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

$header = '<header class="site-header"><a href="/">Example Blog</a><nav><ul><li><a href="/">Home</a></li><li><a href="/blog/">Blog</a></li></ul></nav></header>';
$footer = '<footer class="site-footer"><p>Example footer text</p><p>Copyright Example</p></footer>';
$toc = '<aside><nav aria-label="On this page"><ol><li><a href="#a">A</a></li><li><a href="#b">B</a></li></ol></nav></aside>';
$document = static fn(string $title, string $body, string $head = ''): string => '<html><head><title>' . $title . '</title>' . $head . '</head><body>' . $header . $body . $footer . '</body></html>';
$post = static fn(string $title, string $timestamp, string $aside = ''): string => $document(
    $title,
    '<main><article><h1>' . $title . '</h1><p><time datetime="' . $timestamp . '">' . gmdate('F j, Y', (int) strtotime($timestamp)) . '</time></p>'
        . '<p>' . str_repeat($title . ' introduction text. ', 10) . '</p>'
        . '<div class="article-body">' . $aside
        . '<h2 id="a">Section A</h2><p>' . str_repeat($title . ' first section text. ', 20) . '</p>'
        . '<h2 id="b">Section B</h2><p>' . str_repeat($title . ' second section text. ', 20) . '</p></div></article></main>',
    '<meta property="og:type" content="article"><meta property="article:published_time" content="' . $timestamp . '">'
);

$files = array(
    'index.html' => $document('Home', '<main><h1>Home</h1><p>Welcome to the example blog.</p></main>'),
    'blog/index.html' => $document('Blog', '<main><h1>Blog</h1><ul><li><a href="/blog/first/">First</a></li><li><a href="/blog/second/">Second</a></li><li><a href="/blog/third/">Third</a></li></ul></main>'),
    'blog/first/index.html' => $post('First', '2024-03-01T00:00:00Z'),
    'blog/second/index.html' => $post('Second', '2024-02-01T00:00:00Z', $toc),
    'blog/third/index.html' => $post('Third', '2024-01-01T00:00:00Z'),
);

$plan = null;
try {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    $plan = (new WordPressSitePlan())->fromCompilerResult($result);
} catch (Throwable $error) {
    $assert(false, 'site plan threw ' . get_class($error) . ': ' . $error->getMessage());
}

if (is_array($plan)) {
    $pages = array_column($plan['pages'], null, 'source_path');
    foreach (array('blog/first/index.html', 'blog/second/index.html', 'blog/third/index.html') as $path) {
        $assert('post' === ($pages[$path]['post_type'] ?? null), $path . ' is planned as a post');
    }

    $documents = array_merge($plan['pages'], $plan['template_parts'], $plan['templates']);
    $markup = implode("\n", array_column($documents, 'canonical_block_markup'));
    $assert(array() !== $plan['menus'], 'menus are declared');
    $tocMenu = null;
    foreach ($plan['menus'] as $menu) {
        $assert(str_contains($markup, WordPressSitePlan::NAVIGATION_TOKEN_PREFIX . $menu['token'] . '}}'), 'declared menu ' . $menu['token'] . ' is referenced by a document');
        $items = (string) ($menu['block_markup'] ?? '');
        if (str_contains($items, '"label":"A"') && str_contains($items, '"label":"B"')) $tocMenu = $menu;
    }

    // The TOC still renders for its post: either inline in the post body or
    // as a navigation entity the post references.
    $second = (string) ($pages['blog/second/index.html']['canonical_block_markup'] ?? '');
    $rendersInline = str_contains($second, '>A</a>') && str_contains($second, '>B</a>');
    $rendersEntity = is_array($tocMenu) && str_contains($second, WordPressSitePlan::NAVIGATION_TOKEN_PREFIX . $tocMenu['token'] . '}}');
    $assert($rendersInline || $rendersEntity, 'the TOC links still render for the post that owns them');
}

if ($failures > 0) {
    exit(1);
}

fwrite(STDOUT, "wordpress-site-plan-post-chrome-unique-menu: passed\n");
