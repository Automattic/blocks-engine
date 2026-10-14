<?php
declare(strict_types=1);

// Navigation entities are factored before post article chrome and listing
// cards are projected. Both projections keep one copy of repeated markup: the
// first post's chrome becomes the single template, and the first listing card
// becomes the query loop template. When another copy had a different menu, that
// menu was dropped and its entity stayed declared with no reference, so the
// plan failed with "WordPress site plan navigation entities require explicit
// owned references." Such copies now stay as captured; copies with the same
// menu are still factored.

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

$links = static fn(array $items): string => '<ul>' . implode('', array_map(static fn(string $href, string $label): string => '<li><a href="' . $href . '">' . $label . '</a></li>', array_keys($items), array_values($items))) . '</ul>';
$menu = static fn(array $items): string => '<div class="nav">' . $links($items) . '</div>';
$date = static fn(string $timestamp): string => '<p class="date">' . gmdate('n/j/Y', (int) strtotime($timestamp)) . '</p>';
$article = static fn(string $title, string $timestamp, string $chrome): string => '<html><head><title>' . $title . '</title><meta property="og:type" content="article"><meta property="article:published_time" content="' . $timestamp . '"></head><body>' . $chrome . '<main><h1>' . $title . '</h1>' . $date($timestamp) . '<p>' . str_repeat($title . ' body text. ', 40) . '</p></main></body></html>';
$card = static fn(string $slug, string $title, string $timestamp, array $tags): string => '<article class="card"><h2><a href="/blog/' . $slug . '/">' . $title . '</a></h2>' . $date($timestamp) . '<nav class="tags">' . $links($tags) . '</nav><p>' . $title . ' summary.</p></article>';

$plan = static function (array $files) use ($assert): ?array {
    try {
        $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
        return (new WordPressSitePlan())->fromCompilerResult($result);
    } catch (Throwable $error) {
        $assert(false, 'site plan threw ' . get_class($error) . ': ' . $error->getMessage());
        return null;
    }
};
$documents = static fn(array $plan): array => array_merge($plan['pages'], $plan['template_parts'], $plan['templates']);
$assertEntitiesReferenced = static function (array $plan, string $case) use ($assert, $documents): void {
    $markup = implode("\n", array_column($documents($plan), 'canonical_block_markup'));
    foreach ($plan['menus'] as $order => $menu) {
        $assert($order === $menu['order'], $case . ': menu order follows the declared list');
        $assert(str_contains($markup, WordPressSitePlan::NAVIGATION_TOKEN_PREFIX . $menu['token'] . '}}'), $case . ': declared menu ' . $menu['token'] . ' is referenced by a document');
    }
};

$singleMarkup = static fn(array $plan): string => (string) (array_column($plan['templates'], null, 'slug')['single']['canonical_block_markup'] ?? '');
$refs = static fn(string $markup): int => substr_count($markup, WordPressSitePlan::NAVIGATION_TOKEN_PREFIX);
$home = '<html><body>' . $menu(array('/' => 'Home', '/blog/first/' => 'First')) . '<main><h1>Home</h1><p>Welcome.</p></main></body></html>';
$blogMenu = $menu(array('/' => 'Home', '/blog/' => 'Blog', '/news/' => 'News'));

// Posts whose chrome menus differ: one single template cannot render both, so
// every post keeps its own chrome and menu.
$posts = $plan(array(
    'index.html' => $home,
    'blog/first/index.html' => $article('First', '2019-05-01T00:00:00Z', $blogMenu),
    'blog/second/index.html' => $article('Second', '2019-04-01T00:00:00Z', $menu(array('/' => 'Home', '/news/' => 'News', '/blog/' => 'Blog'))),
));
if (is_array($posts)) {
    $pages = array_column($posts['pages'], null, 'source_path');
    $assert('post' === ($pages['blog/first/index.html']['post_type'] ?? null) && 'post' === ($pages['blog/second/index.html']['post_type'] ?? null), 'posts: fixture articles are planned as posts');
    $assert(1 === $refs((string) ($pages['blog/first/index.html']['canonical_block_markup'] ?? '')) && 1 === $refs((string) ($pages['blog/second/index.html']['canonical_block_markup'] ?? '')), 'posts: each post keeps its own menu');
    $assert(0 === $refs($singleMarkup($posts)), 'posts: no single template chrome is factored');
    $assert(3 === count($posts['menus']), 'posts: the home menu and both post menus are declared');
    $assertEntitiesReferenced($posts, 'posts');
}

// Control: posts with the same chrome menu are still factored.
$shared = $plan(array(
    'index.html' => $home,
    'blog/first/index.html' => $article('First', '2019-05-01T00:00:00Z', $blogMenu),
    'blog/second/index.html' => $article('Second', '2019-04-01T00:00:00Z', $blogMenu),
));
if (is_array($shared)) {
    $pages = array_column($shared['pages'], null, 'source_path');
    $single = $singleMarkup($shared);
    $assert(str_contains($single, '<!-- wp:post-content'), 'shared posts: article chrome is factored into the single template');
    $assert(0 === $refs((string) ($pages['blog/second/index.html']['canonical_block_markup'] ?? '')), 'shared posts: posts keep only their article body');
    $assertEntitiesReferenced($shared, 'shared posts');
}

// Listing cards with different tag menus: one query loop cannot render both,
// so the listing stays as captured.
$listingFiles = static fn(array $secondTags): array => array(
    'index.html' => '<html><body><main><h1>Home</h1><p>Welcome.</p></main></body></html>',
    'blog/index.html' => '<html><body><main><h1>Blog</h1><div class="list">' . $card('first', 'First', '2019-05-01T00:00:00Z', array('/tag/a/' => 'Alpha', '/tag/b/' => 'Beta')) . $card('second', 'Second', '2019-04-01T00:00:00Z', $secondTags) . '</div></main></body></html>',
    'blog/first/index.html' => $article('First', '2019-05-01T00:00:00Z', ''),
    'blog/second/index.html' => $article('Second', '2019-04-01T00:00:00Z', ''),
);
$listing = $plan($listingFiles(array('/tag/c/' => 'Gamma', '/tag/d/' => 'Delta')));
if (is_array($listing)) {
    $index = (string) (array_column($listing['pages'], null, 'source_path')['blog/index.html']['canonical_block_markup'] ?? '');
    $assert(!str_contains($index, '<!-- wp:query') && 2 === $refs($index), 'listing: both card menus stay on the listing page');
    $assert(2 === count($listing['menus']), 'listing: both card menus are declared');
    $assertEntitiesReferenced($listing, 'listing');
}

// Control: cards with the same tag menu still become one query loop.
$sameTags = $plan($listingFiles(array('/tag/a/' => 'Alpha', '/tag/b/' => 'Beta')));
if (is_array($sameTags)) {
    $index = (string) (array_column($sameTags['pages'], null, 'source_path')['blog/index.html']['canonical_block_markup'] ?? '');
    $assert(str_contains($index, '<!-- wp:query') && 1 === $refs($index), 'same tags: cards become one query loop with the shared menu');
    $assertEntitiesReferenced($sameTags, 'same tags');
}

if ($failures > 0) {
    exit(1);
}

fwrite(STDOUT, "navigation-entities-after-repeated-chrome: passed\n");
