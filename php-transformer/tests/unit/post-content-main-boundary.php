<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ListingQueryLoops;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\PostChrome;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ChromeIdentity;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$svg = '<svg viewBox="0 0 448 512"><path d="M0 0h448v512H0z"/></svg>';
$files = array('index.html' => '<main><article><h2><a href="/one.html">One</a></h2><p>Body for One.</p><a href="/one.html">Read more</a></article><article><h2><a href="/two.html">Two</a></h2><p>Body for Two.</p><a href="/two.html">Read more</a></article><article><h2><a href="/three.html">Three</a></h2><p>Body for Three.</p><a href="/three.html">Read more</a></article></main>');
foreach (array('One', 'Two', 'Three') as $i => $title) {
    $day = $i + 1;
    $files[strtolower($title) . '.html'] = '<html><head><title>' . $title . '</title><meta property="og:type" content="article"><meta property="article:published_time" content="2020-08-0' . $day . 'T00:00:00Z"></head><body><header><figure class="breakdance-image">' . $svg . '</figure><nav><a href="/">Home</a></nav></header><article><section><h1>' . $title . '</h1><ul class="bde-post-meta"><li><div class="ee-postmeta-icon">' . $svg . '</div><span class="ee-postmeta-date">August ' . $day . ', 2020</span></li></ul><div class="bde-social-share-buttons"><a href="https://example.com/share">' . $svg . '</a></div></section><section><main class="bde-themeless-template-content-area"><p>Body for ' . $title . '.</p><p>Second paragraph ' . $title . '.</p></main></section></article></body></html>';
}
$plan = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray());
foreach ($plan['templates'] as $template) {
    $assert(substr_count($template['canonical_block_markup'], '<!-- wp:post-content') <= 1, 'Every generated template has at most one content slot');
}
$templates = array_column($plan['templates'], null, 'slug');
foreach ($plan['pages'] as $page) {
    if ('index.html' === $page['source_path']) continue;
    $assert('post' === $page['post_type'], 'Dated article inferred as post');
    $body = $page['canonical_block_markup'];
    $assert(str_contains($body, 'Body for ' . $page['title']), 'Own body retained');
    $assert(!str_contains($body, 'ee-postmeta') && !str_contains($body, 'breakdance-image') && !str_contains($body, '<h1'), 'Only main children retained');
    $template = $templates[$page['route_template'] ?? 'single']['canonical_block_markup'];
    $assert(1 === substr_count($template, '<!-- wp:post-content'), 'Exactly one slot');
    $assert(1 === preg_match('~<main[^>]*>\s*<!-- wp:post-content /-->\s*</main>~', $template), 'Slot is directly inside main, never an icon or logo');
}
WordPressSitePlan::assertValid($plan);

// Without an unambiguous body boundary, independent top-level variable
// regions must not produce a template with several content slots.
$posts = array();
foreach (array('One', 'Two', 'Three') as $i => $title) {
    $posts[] = array('post_type' => 'post', 'title' => $title, 'route' => array('path' => '/' . strtolower($title)), 'publication_timestamp' => '2020-08-01T00:00:00Z', 'canonical_block_markup' => '<!-- wp:heading --><h1>' . $title . '</h1><!-- /wp:heading --><!-- wp:paragraph --><p>August 1, 2020</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>First ' . $title . '</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Second ' . $title . '</p><!-- /wp:paragraph -->');
}
$chrome = new PostChrome(new ListingQueryLoops('', static fn(string $markup): string => ChromeIdentity::markup($markup)), static fn(string $markup): string => ChromeIdentity::markup($markup), static function (array $finding): void {});
$fallback = $chrome->extractArticleChrome($posts, array());
$assert(null === $fallback['single'] && $posts === $fallback['pages'], 'Ambiguous articles remain complete and page-owned');
fwrite(STDOUT, "post content main boundary tests: passed\n");
