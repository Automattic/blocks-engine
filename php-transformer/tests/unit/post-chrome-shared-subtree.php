<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Posts share a link ("Visit the shop") inside a row whose other link varies.
// Factoring post chrome must keep that whole row in each post body.
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
    $files['journal/' . $slug . '/index.html'] = $document($titles[$slug], $article, '<article><div class="hero"><p class="meta">Guide</p><h1>' . $titles[$slug] . '</h1><p>Lede for ' . $slug . '.</p></div><div class="body"><p>Body text for ' . $slug . '.</p></div><div class="after"><a class="link" href="/shop/">Visit the shop</a><a class="link" href="/journal/' . $next . '/">Next: ' . $titles[$next] . '</a></div></article>');
}

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$failures = array();
$posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === ($page['post_type'] ?? null)));
if (3 !== count($posts)) $failures[] = 'the three articles import as posts';
foreach ($posts as $post) {
    $markup = $post['canonical_block_markup'];
    if (1 !== preg_match('/Visit the shop.*Next: /s', $markup)) $failures[] = $post['source_path'] . ' keeps the shared link beside the varying link';
}
// The posts' own <h1> is not a link, so the single template's title is not one either.
foreach ($plan['templates'] as $template) {
    if (str_starts_with($template['slug'], 'single') && preg_match('/<!-- wp:post-title [^>]*"isLink":true/', $template['canonical_block_markup'])) $failures[] = $template['slug'] . ' keeps the unlinked source title unlinked';
}
foreach ($plan['diagnostics'] as $diagnostic) {
    if ('wordpress_site_plan_content_lost' === ($diagnostic['code'] ?? null) && in_array($diagnostic['source_path'] ?? '', array_column($posts, 'source_path'), true)) {
        $failures[] = 'no post text is lost: ' . json_encode($diagnostic['lost_text'] ?? null);
    }
}
if (array() !== $failures) {
    fwrite(STDERR, "FAIL: " . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "post chrome shared subtree: passed\n";
