<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$failures = array();
foreach (array(
    'heading control' => array(true, false, false),
    'bare link title' => array(false, false, false),
    'JSON-LD-only ellipsis' => array(true, true, false),
    'repeated byline' => array(false, true, true),
) as $variant => [$heading, $jsonOnly, $byline]) {
    $cards = array();
    $files = array('index.html' => '<main><h1>Home</h1><a href="/news/">News</a></main>');
    foreach (array('alpha', 'beta', 'gamma') as $index => $slug) {
        $title = ucfirst($slug) . ' news';
        $summary = 'First body paragraph about ' . $slug . '...';
        $date = (string) (14 - $index) . ' Sep 2026';
        $author = $byline ? '<div class="byline"><span>Site Team</span></div>' : '';
        $link = '<a class="title" href="/news/' . $slug . '/">' . $title . '</a>';
        $cards[] = '<article>' . ($heading ? '<h3>' . $link . '</h3>' : $link) . $author
            . '<div class="teaser">' . $summary . '</div><time>' . $date . '</time></article>';
        $structured = json_encode(array('@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $title, 'description' => $summary, 'datePublished' => '2026-09-' . (14 - $index)));
        $files['news/' . $slug . '/index.html'] = '<html><head><title>' . $title . '</title><script type="application/ld+json">' . $structured . '</script>'
            . ($jsonOnly ? '' : '<meta name="description" content="' . $summary . '">')
            . '</head><body><main><article><h1>' . $title . '</h1>' . $author . '<time>' . $date . '</time><p>First body paragraph about ' . $slug . ' with the full story.</p><p>More details for ' . $slug . '.</p></article></main></body></html>';
    }
    $files['news/index.html'] = '<main><h1>News</h1><section>' . $cards[0] . $cards[1] . '</section><a href="/news/page/2/">Next</a></main>';
    $files['news/page/2/index.html'] = '<main><h1>News</h1><section>' . $cards[2] . '</section><a href="/news/page/2/">Next</a></main>';
    $plan = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray());
    $pages = array_column($plan['pages'], null, 'source_path');
    $markup = $pages['news/index.html']['canonical_block_markup'];
    $checks = array(
        'native query' => str_contains($markup, '<!-- wp:query'),
        'no post-content in cards' => !str_contains($markup, 'wp:post-content'),
        'native excerpt' => str_contains($markup, 'wp:post-excerpt'),
        'native title' => str_contains($markup, 'wp:post-title'),
        'no content loss' => !array_filter($plan['diagnostics'], static fn(array $row): bool => 'wordpress_site_plan_content_lost' === ($row['code'] ?? null)),
        'pagination folded' => !isset($pages['news/page/2/index.html']),
    );
    if (!$heading) $checks['paragraph title level'] = 1 === preg_match('/<!-- wp:post-title \{[^\n]*"level":0/', $markup);
    if ($byline) $checks['literal byline'] = str_contains($markup, 'Site Team');
    foreach ($checks as $check => $passed) {
        if (!$passed) $failures[] = $variant . ': ' . $check;
    }
    echo $variant . ': ' . (in_array(false, $checks, true) ? 'FAIL' : 'ok') . "\n";
}
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . "\n");
exit(array() === $failures ? 0 : 1);
