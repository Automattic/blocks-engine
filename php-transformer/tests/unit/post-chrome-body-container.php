<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #4676: a varying lede must not put the body in a centred header,
// and related links must not jump ahead of the shared author card.
$document = static fn(string $title, string $head, string $main): string => '<!doctype html><html><head><title>' . $title . '</title>' . $head . '</head><body><header><nav><a href="/">Home</a><a href="/journal/">Journal</a></nav></header><main>' . $main . '</main><footer><p>Footer</p></footer></body></html>';
$titles = array('first' => 'First story', 'second' => 'Second story', 'third' => 'Third story');
$slugs = array_keys($titles);
$cards = '';
foreach ($titles as $slug => $title) $cards .= '<a class="post" href="/journal/' . $slug . '/"><h3>' . $title . '</h3></a>';
$files = array(
    'index.html' => $document('Home', '', '<h1>Home</h1><p>Welcome.</p>'),
    'journal/index.html' => $document('Journal', '', '<h1>Journal</h1><div class="posts">' . $cards . '</div>'),
);
foreach ($slugs as $position => $slug) {
    $next = $slugs[($position + 1) % count($slugs)];
    $article = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","headline":"' . $titles[$slug] . '","datePublished":"2026-0' . ($position + 1) . '-01"}</script>';
    $files['journal/' . $slug . '/index.html'] = $document($titles[$slug], $article . '<style>.center{text-align:center}.body{text-align:left}</style>', '<article><header class="center"><h1>' . $titles[$slug] . '</h1><p class="lede">Lede for ' . $slug . '.</p></header><div class="body"><p>Body text for ' . $slug . '.</p><h2>Section of ' . $slug . '</h2><p>More body for ' . $slug . '.</p></div><aside class="author"><p>Written by</p><h3>The Team</h3></aside></article><section class="related"><h2>Related posts</h2><a href="/journal/' . $next . '/"><h3>' . $titles[$next] . '</h3></a></section>');
}

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$failures = array();
$posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === ($page['post_type'] ?? null)));
if (3 !== count($posts)) $failures[] = 'the three articles import as posts';
$templates = array_values(array_filter($plan['templates'], static fn(array $template): bool => str_starts_with($template['slug'], 'single')));
if (1 !== count($templates)) $failures[] = 'one shared single template';
$template = $templates[0]['canonical_block_markup'] ?? '';
foreach ($templates as $entry) {
    if (str_contains($entry['canonical_block_markup'], 'Lede for') || preg_match('~/journal/(first|second|third)~', $entry['canonical_block_markup'])) $failures[] = 'no post-specific data frozen in templates';
}
$index = new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($template);
foreach ($index->ranges() as $range) {
    $slice = substr($template, $range['offset'], $range['length']);
    if (!preg_match('/^<!-- wp:group (\{[^\n]*?\}) -->/', $slice, $match) || 'header' !== (json_decode($match[1], true)['tagName'] ?? null)) continue;
    if (str_contains($slice, '<!-- wp:post-content')) $failures[] = 'body is outside header';
    if (!str_contains($slice, '<!-- wp:post-excerpt') || !str_contains($slice, 'lede')) $failures[] = 'header renders styled excerpt';
}
$bodyWrapper = false;
foreach ($index->ranges() as $range) {
    $slice = substr($template, $range['offset'], $range['length']);
    if (!preg_match('/^<!-- wp:group (\{[^\n]*?\}) -->/', $slice, $match)) continue;
    $attrs = json_decode($match[1], true);
    if (!in_array('body', explode(' ', $attrs['className'] ?? ''), true)) continue;
    $bodyWrapper = str_contains($slice, '<!-- wp:post-content');
}
if (!$bodyWrapper) $failures[] = 'post-content keeps its source body styling context';
if (!str_contains($template, '<!-- wp:post-excerpt')) $failures[] = 'excerpt slot exists';
if (false === strpos($template, '<!-- wp:query') || strpos($template, '<!-- wp:query') < strpos($template, 'The Team')) $failures[] = 'related query follows author';
foreach ($posts as $post) {
    $slug = basename(dirname($post['source_path']));
    if (($post['route_template'] ?? 'single') !== $templates[0]['slug']) $failures[] = 'all posts share the template';
    if (($post['metadata']['excerpt'] ?? '') !== 'Lede for ' . $slug . '.') $failures[] = 'post owns its lede excerpt';
    if (!str_contains($post['canonical_block_markup'], 'More body for ' . $slug)) $failures[] = 'body preserved';
}
foreach ($plan['diagnostics'] as $diagnostic) {
    if ('wordpress_site_plan_content_lost' === ($diagnostic['code'] ?? null) && in_array($diagnostic['source_path'] ?? '', array_column($posts, 'source_path'), true)) {
        $failures[] = 'no post text is lost: ' . json_encode($diagnostic['lost_text'] ?? null);
    }
}
// Retain the original slot (no excerpt, no route-specific templates) when the
// lede cannot own post_excerpt: rich ledes, or a differing source description.
$variants = array(
    'rich lede' => array('Lede for ', '<em>Lede</em> for ', '<em>Lede</em>'),
    'differing description' => array('<title>', '<meta name="description" content="Summary."><title>', 'Lede for'),
);
foreach ($variants as $label => list($search, $replace, $kept)) {
    $variantFiles = array_map(static fn(string $html): string => str_replace($search, $replace, $html), $files);
    $variantPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $variantFiles))->toArray()['source_reports']['wordpress_site_plan'];
    $variantTemplates = array_values(array_filter($variantPlan['templates'], static fn(array $entry): bool => str_starts_with($entry['slug'], 'single')));
    if (1 !== count($variantTemplates)) $failures[] = "$label: posts still share a template";
    if (str_contains($variantTemplates[0]['canonical_block_markup'] ?? '', '<!-- wp:post-excerpt')) $failures[] = "$label: lede does not become an excerpt";
    foreach ($variantPlan['pages'] as $post) {
        if ('post' === ($post['post_type'] ?? null) && !str_contains($post['canonical_block_markup'], $kept)) $failures[] = "$label: lede remains post-owned";
    }
    foreach ($variantPlan['diagnostics'] as $diagnostic) if ('wordpress_site_plan_content_lost' === ($diagnostic['code'] ?? null)) $failures[] = "$label: fallback loses no content";
}
if (array() !== $failures) {
    fwrite(STDERR, "FAIL: " . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "post chrome body container: passed\n";
