<?php
declare(strict_types=1);

// Date-only routes listing dated cards are native archives, without body classes (#7389).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
$archive = <<<'HTML'
<meta property="og:type" content="website">
<main><h1>2026.10</h1>
  <article class="blog-item"><time datetime="2026-10-16T14:30:19Z">2026-10-16</time><h2><a href="/posts/1/index.html">Post 1</a></h2></article>
  <article class="blog-item"><time datetime="2026-10-17T03:03:01Z">2026-10-17</time><h2><a href="/posts/2/index.html">Post 2</a></h2></article>
</main>
HTML;
foreach (array(
    'month archive' => array('posts/archives/2026/10', $archive, true),
    'year archive body classes' => array('2026', '<body class="archive date">' . $archive . '</body>', true),
    'day archive' => array('posts/archives/2026/10/16', $archive, true),
    'trailing slug' => array('posts/archives/2026/10/story', $archive, false),
    'invalid month' => array('posts/archives/2026/13', $archive, false),
    'undated cards' => array('posts/archives/2026/10', str_replace(array('>2026-10-16<', '>2026-10-17<'), '>Unknown<', $archive), false),
    'single card' => array('posts/archives/2026/10', preg_replace('~  <article[^\n]+Post 2[^\n]+\n~', '', $archive), true),
    // Image, date and title each link to the entry; the date link wraps the time.
    'multi-link cards' => array('posts/archives/2026/10', str_replace(array('<time datetime="2026-10-16T14:30:19Z">2026-10-16</time>', '<time datetime="2026-10-17T03:03:01Z">2026-10-17</time>'), array('<a href="/posts/1/index.html"><img src="a.jpg" alt=""></a><a href="/posts/1/index.html"><time datetime="2026-10-16T14:30:19Z">2026-10-16</time></a>', '<a href="/posts/2/index.html"><img src="b.jpg" alt=""></a><a href="/posts/2/index.html"><time datetime="2026-10-17T03:03:01Z">2026-10-17</time></a>'), $archive), true),
    'one outward card' => array('posts/archives/2026/10', str_replace('/posts/2/index.html', '/posts/archives/2026/10/index.html', $archive), true),
    'self links' => array('posts/archives/2026/10', str_replace(array('/posts/1/index.html', '/posts/2/index.html'), '/posts/archives/2026/10/index.html', $archive), false),
) as $name => [$route, $html, $native]) {
    $path = $route . '/index.html';
    $files = array(
        'index.html' => '<main><h1>Home</h1><a href="/posts/1/">Post 1</a><a href="/posts/2/">Post 2</a><a href="/' . $path . '">Archive</a></main>',
        'posts/1/index.html' => '<main><article><h1>Post 1</h1><time datetime="2026-10-16T14:30:19Z">2026-10-16</time><p>First story.</p></article></main>',
        'posts/2/index.html' => '<main><article><h1>Post 2</h1><time datetime="2026-10-17T03:03:01Z">2026-10-17</time><p>Second story.</p></article></main>',
        $path => $html,
    );
    $compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    $plan = (new WordPressSitePlan())->fromCompilerResult($compiled);
    $pages = array_column($plan['pages'], null, 'source_path');
    $assert(in_array($path, array_column($compiled['source_reports']['compiled_site']['pages'], 'source_path'), true), "{$name}: capture stays in the source catalog");
    $assert($native === !isset($pages[$path]), "{$name}: only native date listings are excluded from content");
    foreach (array('1', '2') as $slug) {
        $assert('post' === $pages["posts/{$slug}/index.html"]['post_type'], "{$name}: real post {$slug} remains a post");
    }
    $expected = $native ? array(array('archive' => array('source_path' => $path, 'source_route' => '/' . $route, 'query' => array_merge(array_merge(array('year' => 2026), 'year archive body classes' === $name ? array() : array('monthnum' => 10)), 'day archive' === $name ? array('day' => 16) : array())))) : array();
    $assert($expected === $plan['date_archives'], "{$name}: native date query retains its source route and date parts");
    WordPressSitePlan::assertValid($plan);
    if ($native) {
        foreach (array('missing query' => null, 'wrong month' => array('year' => 2026, 'monthnum' => 11), 'string year' => array('year' => '2026', 'monthnum' => 10)) as $mutation => $query) {
            $invalid = $plan;
            $invalid['date_archives'][0]['archive']['query'] = $query;
            try {
                WordPressSitePlan::assertValid($invalid);
                $assert(false, "{$name}: rejects {$mutation}");
            } catch (InvalidArgumentException) {
                // Malformed declarations cannot reach the materializer.
            }
        }
        $assert(str_contains($pages['index.html']['canonical_block_markup'], 'href="/' . $route . '/"'), "{$name}: captured archive link resolves to the native route");
    }
}

fwrite(STDOUT, "date route listing archive tests: passed\n");
