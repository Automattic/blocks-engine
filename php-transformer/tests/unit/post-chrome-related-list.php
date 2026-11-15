<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #6073: list items, even a single item behind wrappers, are related cards.
$failures = array();
foreach (array('ul', 'ol') as $tag) {
    foreach (array(0, 2) as $wrappers) {
        $files = array('index.html' => '<!doctype html><html><head><title>Home</title></head><body><h1>Home</h1><p>Welcome.</p></body></html>');
        $titles = array('alpha' => 'Alpha notes', 'beta' => 'Beta notes');
        foreach ($titles as $slug => $title) {
            $other = 'alpha' === $slug ? 'beta' : 'alpha';
            $date = 'alpha' === $slug ? '2024-01-10' : '2024-01-11';
            $related = str_repeat('<div class="wrapper">', $wrappers) . '<' . $tag . '><li><a href="/' . $other . '/">' . $titles[$other] . '</a></li></' . $tag . '>' . str_repeat('</div>', $wrappers);
            $files[$slug . '/index.html'] = '<!doctype html><html><head><title>' . $title . '</title><script type="application/ld+json">{"@context":"https://schema.org","@type":"BlogPosting","headline":"' . $title . '","datePublished":"' . $date . '"}</script></head><body><header class="site-header"><p>Shared header</p></header><main class="site-main"><article class="post"><h1 class="post-title">' . $title . '</h1><p class="post-date"><time datetime="' . $date . '">' . $date . '</time></p><div class="post-body"><p>' . $title . ' opening paragraph.</p><p>' . $title . ' middle paragraph.</p><p>' . $title . ' closing paragraph.</p></div><div class="related"><h3>Related</h3>' . $related . '</div></article></main><footer class="site-footer"><p>Footer text</p></footer></body></html>';
        }
        $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
        $context = $tag . ' with ' . $wrappers . ' wrappers: ';
        foreach ($plan['diagnostics'] as $diagnostic) {
            if ('wordpress_site_plan_post_chrome_order_retained' === ($diagnostic['code'] ?? null)) $failures[] = $context . 'no order-retained finding';
        }
        $posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === ($page['post_type'] ?? null)));
        if (2 !== count($posts)) $failures[] = $context . 'two posts';
        $templates = array_values(array_filter($plan['templates'], static fn(array $entry): bool => str_starts_with($entry['slug'], 'single')));
        if (1 !== count($templates)) $failures[] = $context . 'one shared single template';
        $template = $templates[0]['canonical_block_markup'] ?? '';
        foreach (array('post-content', 'query') as $block) {
            if (!str_contains($template, '<!-- wp:' . $block)) $failures[] = $context . 'shared template contains core/' . $block;
        }
        foreach ($posts as $post) {
            $slug = basename(dirname($post['source_path']));
            $other = 'alpha' === $slug ? 'beta' : 'alpha';
            $content = $post['canonical_block_markup'];
            if (($post['route_template'] ?? 'single') !== ($templates[0]['slug'] ?? null)) $failures[] = $context . 'both posts use the shared template';
            foreach (array('opening', 'middle', 'closing') as $paragraph) {
                if (!str_contains($content, $titles[$slug] . ' ' . $paragraph . ' paragraph.')) $failures[] = $context . $slug . ' retains its ' . $paragraph . ' paragraph';
            }
            foreach (array('Related', '<ul', '<ol', $titles[$other], 'Shared header', 'Footer text') as $excluded) {
                if (str_contains($content, $excluded)) $failures[] = $context . $slug . ' post_content excludes ' . $excluded;
            }
        }
    }
}
if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "post chrome related list: passed\n";
