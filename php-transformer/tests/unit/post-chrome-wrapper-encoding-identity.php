<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ChromeIdentity;

// #6104: content depth must not split equivalent wrapper encodings.
$failures = array();
$folded = '<!-- wp:custom/layout-shell {"wrappers":[{"tagName":"div","attributes":{"class":"outer"}},{"tagName":"article","attributes":{"class":"inner"}}]} --><div class="outer"><article class="inner"><!-- wp:post-content /--></article></div><!-- /wp:custom/layout-shell -->';
$native = '<!-- wp:group {"className":"outer"} --><div class="outer"><!-- wp:group {"tagName":"article","className":"inner"} --><article class="inner"><!-- wp:post-content /--></article><!-- /wp:group --></div><!-- /wp:group -->';
// Importers own the generated-block namespace, as SSI does with ssi-<site>.
foreach (array($folded, str_replace('custom/', 'ssi-example-journal/', $folded)) as $encoding) {
    if (ChromeIdentity::markup($encoding) !== ChromeIdentity::markup($native)) $failures[] = 'equivalent wrapper encodings share identity';
}
foreach (array(str_replace('article', 'section', $native), str_replace('inner', 'different', $native), str_replace(array('outer', 'inner'), array('inner', 'outer'), $native)) as $different) {
    if (ChromeIdentity::markup($folded) === ChromeIdentity::markup($different)) $failures[] = 'wrapper tags, classes and order remain significant';
}
foreach (array(array('a', 'b', 'c'), array('c', 'a', 'b')) as $order) {
    $files = array();
    $records = array();
    $links = '';
    foreach ($order as $slug) {
        $title = strtoupper($slug) . ' journal notes';
        $date = '2024-01-' . (10 + 2 * (ord($slug) - ord('a')));
        $related = '<div class="related" style="display: none"><div class="spinner"><svg width="30" height="30" viewBox="0 0 30 30" xmlns="http://www.w3.org/2000/svg"><circle fill="none" stroke="#000" cx="15" cy="15" r="14"></circle></svg></div></div>';
        if ('c' !== $slug) {
            $cards = '';
            foreach ($order as $other) {
                if ($other === $slug || 'c' === $other) continue;
                $card = '<a href="/2024/01/' . $other . '.html">' . strtoupper($other) . ' journal notes</a>';
                for ($depth = 0; $depth < 5; ++$depth) $card = '<div class="card-' . $depth . '">' . $card . '</div>';
                $cards .= '<li>' . $card . '</li>';
            }
            $related = '<div class="related"><h3 class="related-title">Related Posts</h3><ul class="related-list">' . $cards . '</ul></div>';
        }
        $files['2024/01/' . $slug . '.html'] = '<!doctype html><html><head><title>' . $title . '</title><meta property="article:published_time" content="' . $date . 'T00:00:00Z"><style>.main-content{max-width:1200px;margin:0 auto;padding:0 16px}.grid{display:flex;gap:24px}.col-main{flex:2}.col-side{flex:1}</style></head><body>
<header class="site-header"><p>Shared header</p></header>
<div class="main-content"><div class="grid">
<div class="col-main"><div class="blog"><article class="post"><div class="post-content">
<h1 class="post-title">' . $title . '</h1>
<p class="post-date"><time datetime="' . $date . '">' . $date . '</time></p>
<div class="post-body"><p>' . $slug . ' opening paragraph.</p><p>' . $slug . ' middle paragraph.</p><p>' . $slug . ' closing paragraph.</p></div>
' . $related . '
</div></article></div></div>
<div class="col-side"><div class="sidebar"><h2>About</h2><p>Sidebar text.</p></div></div>
</div></div>
<footer class="site-footer"><p>Footer text</p></footer>
</body></html>';
        $links .= '<p><a href="/2024/01/' . $slug . '.html">' . $title . '</a></p>';
        $records[] = array('permalink' => 'https://example.test/2024/01/' . $slug . '.html', 'slug' => $slug, 'kind' => 'post', 'title' => $title, 'published' => $date . 'T00:00:00Z', 'updated' => $date . 'T00:00:00Z', 'author' => 'Example Author', 'terms' => 'c' === $slug ? array() : array('Notes'), 'path' => '/2024/01/' . $slug . '.html');
    }
    $files['index.html'] = '<!doctype html><html><head><title>Example Journal</title></head><body><header class="site-header"><p>Shared header</p></header><div class="main-content"><h1>Example Journal</h1>' . $links . '</div><footer class="site-footer"><p>Footer text</p></footer></body></html>';
    $files['content-index.json'] = json_encode(array('schema' => 'data-liberation-agent/content-index/v1', 'posts' => $records, 'terms' => array(), 'redirects' => array()), JSON_THROW_ON_ERROR);
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files, 'block_namespace' => 'ssi-example-journal'))->toArray()['source_reports']['wordpress_site_plan'];
    $posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === ($page['post_type'] ?? null)));
    if (3 !== count($posts)) $failures[] = 'three posts';
    $templates = array_values(array_filter($plan['templates'], static fn(array $template): bool => str_starts_with($template['slug'], 'single')));
    if (1 !== count($templates)) $failures[] = 'one single-post template';
    $template = $templates[0]['canonical_block_markup'] ?? '';
    foreach (array('<!-- wp:post-content', '<!-- wp:post-title', '<!-- wp:ssi-example-journal/layout-shell') as $expected) {
        if (!str_contains($template, $expected)) $failures[] = 'shared template contains ' . $expected;
    }
    foreach ($posts as $post) {
        $slug = pathinfo($post['source_path'], PATHINFO_FILENAME);
        if (($post['route_template'] ?? 'single') !== ($templates[0]['slug'] ?? null)) $failures[] = $slug . ' uses shared template';
        $content = $post['canonical_block_markup'];
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($content)) ?? '');
        if ($text !== $slug . ' opening paragraph.' . $slug . ' middle paragraph.' . $slug . ' closing paragraph.') $failures[] = $slug . ' post_content contains only body text';
        foreach (array('opening', 'middle', 'closing') as $paragraph) {
            if (!str_contains($content, $slug . ' ' . $paragraph . ' paragraph.')) $failures[] = $slug . ' retains ' . $paragraph;
        }
        foreach (array('post-title', 'post-date', 'related', 'spinner', 'Shared header', 'Footer text', 'Sidebar text', 'main-content', 'col-main') as $excluded) {
            if (str_contains($content, $excluded)) $failures[] = $slug . ' body excludes ' . $excluded;
        }
    }
    foreach ($plan['diagnostics'] as $diagnostic) {
        if (str_contains($diagnostic['code'] ?? '', 'post_chrome_order_retained')) $failures[] = 'no order-retained finding';
    }
}
if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "post chrome wrapper encoding identity: passed\n";
