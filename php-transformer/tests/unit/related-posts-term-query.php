<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #6438: a per-post related listing whose cards are other members of the
// post's tag becomes a term-scoped Query Loop that excludes the current post.
// Portfolio has four members (each post shows two of its three peers) and
// Landing has three (each shows both peers), so cards = min(2, members - 1).
$titles = array('p1' => 'Portfolio one', 'p2' => 'Portfolio two', 'p3' => 'Portfolio three', 'p4' => 'Portfolio four', 'l1' => 'Landing one', 'l2' => 'Landing two', 'l3' => 'Landing three');
$tags = array('p1' => 'Portfolio', 'p2' => 'Portfolio', 'p3' => 'Portfolio', 'p4' => 'Portfolio', 'l1' => 'Landing', 'l2' => 'Landing', 'l3' => 'Landing');
$sameMinute = array('p1' => '2023-11-10T11:44:00Z', 'p2' => '2023-11-10T11:44:00Z', 'p3' => '2023-11-10T11:44:00Z', 'p4' => '2023-11-10T11:45:00Z', 'l1' => '2023-11-10T11:45:00Z', 'l2' => '2023-11-10T11:45:00Z', 'l3' => '2023-11-10T11:45:00Z');
$distinct = array('p1' => '2023-11-01T10:00:00Z', 'p2' => '2023-11-02T10:00:00Z', 'p3' => '2023-11-03T10:00:00Z', 'p4' => '2023-11-04T10:00:00Z', 'l1' => '2023-11-05T10:00:00Z', 'l2' => '2023-11-06T10:00:00Z', 'l3' => '2023-11-07T10:00:00Z');
$cases = array(
    // A shuffled subset of same-tag peers, as the source randomizes per load.
    'shuffled members' => array('dates' => $sameMinute, 'cards' => array('p1' => array('p3', 'p2'), 'p2' => array('p4', 'p1'), 'p3' => array('p1', 'p4'), 'p4' => array('p2', 'p3'), 'l1' => array('l2', 'l3'), 'l2' => array('l3', 'l1'), 'l3' => array('l1', 'l2')), 'scoped' => true, 'order' => 'rand'),
    // The newest other members of the tag, newest first, with no tied dates.
    'newest-first members' => array('dates' => $distinct, 'cards' => array('p1' => array('p4', 'p3'), 'p2' => array('p4', 'p3'), 'p3' => array('p4', 'p2'), 'p4' => array('p3', 'p2'), 'l1' => array('l3', 'l2'), 'l2' => array('l3', 'l1'), 'l3' => array('l2', 'l1')), 'scoped' => true, 'order' => 'date'),
    // The site's latest posts, whatever their tag: today's latest-posts query.
    'latest posts' => array('dates' => $distinct, 'cards' => array_fill_keys(array_keys($titles), array('l3', 'l2')), 'scoped' => false, 'order' => 'date'),
    // #6538: both tags share one layout, and one template's other posts lack
    // its trailing badge. Across the whole template that badge is unslotted
    // variation, so the tags group together before the related query is
    // known; each tag must still render its own term.
    'identical layouts' => array('dates' => $distinct, 'cards' => array('p1' => array('p4', 'p3'), 'p2' => array('p4', 'p3'), 'p3' => array('p4', 'p2'), 'p4' => array('p3', 'p2'), 'l1' => array('l3', 'l2'), 'l2' => array('l3', 'l1'), 'l3' => array('l2', 'l1')), 'scoped' => true, 'order' => 'date', 'extra' => array('n1' => 'Notes one', 'n2' => 'Notes two')),
);
$failures = array();
foreach ($cases as $case => $row) {
    $extra = $row['extra'] ?? array();
    $links = '';
    foreach ($titles + $extra as $slug => $title) $links .= '<p><a href="/2023/11/' . $slug . '.html">' . $title . '</a></p>';
    $files = array(array('path' => 'index.html', 'content' => '<!doctype html><html><head><title>Example Journal</title></head><body><h1>Example Journal</h1>' . $links . '</body></html>'));
    foreach ($titles as $slug => $title) {
        $date = $row['dates'][$slug];
        $cards = '';
        foreach ($row['cards'][$slug] as $other) $cards .= '<li><a href="/2023/11/' . $other . '.html">' . $titles[$other] . '</a></li>';
        $html = '<!doctype html><html><head><title>' . $title . '</title></head><body><header class="site-header"><p>Shared header</p></header><main class="site-main"><article class="post"><h1 class="post-title">' . $title . '</h1><p class="post-date"><time datetime="' . $date . '">' . substr($date, 0, 10) . '</time></p><div class="post-body"><p>' . $slug . ' opening paragraph.</p><p>' . $slug . ' middle paragraph.</p><p>' . $slug . ' closing paragraph.</p></div><div class="related"><h3>Related Posts</h3><ul class="related-list">' . $cards . '</ul></div></article></main><footer class="site-footer"><p>Footer text</p></footer></body></html>';
        if (array() !== $extra) $html = str_replace('</article>', '<figure class="post-badge"><img src="/badge.png" alt="Badge"></figure></article>', $html);
        $files[] = array('path' => '2023/11/' . $slug . '.html', 'content' => $html, 'metadata' => array('post_type' => 'post', 'declared_content' => array('title' => $title, 'slug' => $slug, 'published' => $date, 'updated' => $date, 'author' => 'Example Author', 'terms' => array($tags[$slug]))));
    }
    foreach ($extra as $slug => $title) {
        $date = '2023-10-0' . substr($slug, 1) . 'T10:00:00Z';
        $html = '<!doctype html><html><head><title>' . $title . '</title></head><body><header class="site-header"><p>Shared header</p></header><main class="site-main"><article class="post"><h1 class="post-title">' . $title . '</h1><p class="post-date"><time datetime="' . $date . '">' . substr($date, 0, 10) . '</time></p><div class="post-body"><p>' . $slug . ' opening paragraph.</p><p>' . $slug . ' middle paragraph.</p><p>' . $slug . ' closing paragraph.</p></div></article><aside class="notes-sidebar"><p>Notes sidebar</p></aside></main><footer class="site-footer"><p>Footer text</p></footer></body></html>';
        $files[] = array('path' => '2023/11/' . $slug . '.html', 'content' => $html, 'metadata' => array('post_type' => 'post', 'declared_content' => array('title' => $title, 'slug' => $slug, 'published' => $date, 'updated' => $date, 'author' => 'Example Author', 'terms' => array('Notes'))));
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    $templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
    $posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === ($page['post_type'] ?? null) && !isset($extra[basename($page['source_path'], '.html')])));
    if (count($titles) !== count($posts)) $failures[] = $case . ': seven posts';
    $used = array();
    foreach ($posts as $post) {
        $slug = basename($post['source_path'], '.html');
        $templateSlug = $post['route_template'] ?? 'single';
        $used[$templateSlug][$tags[$slug]] = true;
        $markup = $templates[$templateSlug] ?? '';
        if (1 !== preg_match('/<!-- wp:query (\{.*?\}) -->/', $markup, $match)) {
            $failures[] = $case . ': ' . $slug . ' template owns the related query';
            continue;
        }
        $query = json_decode($match[1], true)['query'] ?? array();
        $term = '{{wordpress-site-plan:term:post_tag:' . strtolower($tags[$slug]) . '}}';
        $expected = $row['scoped'] ? array('include' => array('post_tag' => array($term))) : null;
        if (($query['taxQuery'] ?? null) !== $expected) $failures[] = $case . ': ' . $slug . ' taxQuery is ' . json_encode($query['taxQuery'] ?? null);
        if (($query['excludeCurrent'] ?? false) !== $row['scoped']) $failures[] = $case . ': ' . $slug . ' excludeCurrent';
        if (($query['orderBy'] ?? null) !== $row['order']) $failures[] = $case . ': ' . $slug . ' orderBy is ' . json_encode($query['orderBy'] ?? null);
        if (2 !== ($query['perPage'] ?? null)) $failures[] = $case . ': ' . $slug . ' perPage is ' . json_encode($query['perPage'] ?? null);
        if (str_contains($post['canonical_block_markup'], 'Related Posts')) $failures[] = $case . ': ' . $slug . ' body excludes the related section';
    }
    // Each template serves one tag; latest posts keep one shared template.
    foreach ($used as $templateSlug => $groupTags) if (1 !== count($groupTags) && $row['scoped']) $failures[] = $case . ': ' . $templateSlug . ' serves one tag';
    if (!$row['scoped'] && 1 !== count($used)) $failures[] = $case . ': one shared single template';
}
if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "related posts term query: passed\n";
