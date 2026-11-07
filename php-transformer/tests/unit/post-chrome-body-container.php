<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex;

// #4676: rich header fields, a lead image, a weekday date and related cards
// wrapped in a grid all get native slots in one shared single template.
$document = static fn(string $title, string $head, string $main): string => '<!doctype html><html><head><title>' . $title . '</title>' . $head . '</head><body><header><nav><a href="/">Home</a><a href="/journal/">Journal</a></nav></header><main>' . $main . '</main><footer><p>Footer</p></footer></body></html>';
$titles = array('first' => 'First story', 'second' => 'Second story', 'third' => 'Third story');
$slugs = array_keys($titles);
$files = array('index.html' => $document('Home', '', '<h1>Home</h1><p>Welcome.</p>'));
$ledes = array('first' => 'Lede for first.', 'second' => '<em>Lede</em> for second.', 'third' => 'Lede for third.');
$dates = array('Thursday, January 1, 2026', 'Sunday, February 1, 2026', 'Sunday, March 1, 2026');
foreach ($slugs as $position => $slug) {
    $next = $slugs[($position + 1) % count($slugs)];
    $head = '<meta name="description" content="SEO summary for ' . $slug . '."><script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","headline":"' . $titles[$slug] . '","datePublished":"2026-0' . ($position + 1) . '-01"}</script>';
    $files['journal/' . $slug . '/index.html'] = $document($titles[$slug], $head, '<article><header class="center"><span class="pill">Journal</span><h1>' . $titles[$slug] . '</h1><p class="lede">' . $ledes[$slug] . '</p><div class="byline"><time datetime="2026-0' . ($position + 1) . '-01">' . $dates[$position] . '</time><p class="read">' . ($position + 3) . ' min read</p></div></header><figure class="lead"><img src="/img/' . $slug . '.jpg" alt=""></figure><div class="body"><p>Body text for ' . $slug . '.</p><h2>Section of ' . $slug . '</h2><p>More body for ' . $slug . '.</p></div><aside class="author"><p>Written by</p><h3>The Team</h3></aside></article><div class="related"><h2>Related posts</h2><div class="grid"><a href="/journal/' . $next . '/" class="card"><img src="/img/' . $next . '.jpg" alt=""><h3>' . $titles[$next] . '</h3><p>' . $ledes[$next] . '</p></a></div></div>');
    $files['img/' . $slug . '.jpg'] = 'image';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$failures = array();
$posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === ($page['post_type'] ?? null)));
if (3 !== count($posts)) $failures[] = 'three Article posts';
$templates = array_values(array_filter($plan['templates'], static fn(array $entry): bool => str_starts_with($entry['slug'], 'single')));
if (1 !== count($templates)) $failures[] = 'one shared single template';
$template = $templates[0]['canonical_block_markup'] ?? '';
foreach ($plan['templates'] as $entry) {
    if (preg_match('/Lede| min read|First story|Second story|Third story/', $entry['canonical_block_markup'])) $failures[] = 'no post-specific text in templates';
}
$keys = array();
$bodyWrapper = false;
foreach ((new BlockMarkupIndex($template))->ranges() as $range) {
    $slice = substr($template, $range['offset'], $range['length']);
    if (!preg_match('/^<!-- wp:[^ ]+ (\{[^\n]*?\}) -->/', $slice, $match)) continue;
    $attrs = json_decode($match[1], true);
    $classes = explode(' ', $attrs['className'] ?? '');
    if (in_array('center', $classes, true) && str_contains($slice, '<!-- wp:post-content')) $failures[] = 'body outside centered header';
    if (in_array('body', $classes, true)) $bodyWrapper = str_contains($slice, '<!-- wp:post-content');
    foreach (array('lede', 'read') as $field) {
        if (!in_array($field, $classes, true)) continue;
        $binding = $attrs['metadata']['bindings']['content'] ?? array();
        if ('core/post-meta' !== ($binding['source'] ?? null)) $failures[] = "$field uses native post meta";
        $keys[$field] = $binding['args']['key'] ?? '';
    }
}
if (!$bodyWrapper) $failures[] = 'post-content in body container';
if (!str_contains($template, '<!-- wp:post-featured-image')) $failures[] = 'lead image is the featured image';
if (!str_contains($template, '"format":"l, F j, Y"')) $failures[] = 'post date keeps its weekday format';
if (2 !== count($keys)) $failures[] = 'both header fields bound';
if (false === strpos($template, '<!-- wp:query') || strpos($template, '<!-- wp:query') < strpos($template, 'The Team') || !str_contains($template, '<!-- wp:post-template')) $failures[] = 'related query after author';
foreach ($posts as $post) {
    $slug = basename(dirname($post['source_path']));
    $position = array_search($slug, $slugs, true);
    if (($post['route_template'] ?? 'single') !== ($templates[0]['slug'] ?? null)) $failures[] = 'shared template used';
    $meta = $post['metadata']['post_meta'] ?? array();
    if (($meta[$keys['lede'] ?? ''] ?? null) !== $ledes[$slug]) $failures[] = 'own rich lede preserved';
    if (($meta[$keys['read'] ?? ''] ?? null) !== ($position + 3) . ' min read') $failures[] = 'own read time preserved';
    if (!str_contains($post['canonical_block_markup'], 'More body for ' . $slug)) $failures[] = 'body preserved';
    if (!isset($post['metadata']['featured_image'])) $failures[] = 'own featured image';
}
foreach ($plan['diagnostics'] as $diagnostic) {
    if ('wordpress_site_plan_content_lost' === ($diagnostic['code'] ?? null)) $failures[] = 'no content lost: ' . json_encode($diagnostic);
}
if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "post chrome body container: passed\n";
