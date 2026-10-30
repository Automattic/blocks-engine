<?php
declare(strict_types=1);

/**
 * A WordPress category or tag archive shows each listed post's date in a
 * `wp-block-post-date`. Those dates belong to the posts, so the archive is a
 * native term archive, never a post of its own or a member of another term.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$terms = '<a href="/category/blog/" rel="category tag">Blog</a> <a href="/category/foto/" rel="category tag">Foto</a>';
$dated = static fn(string $date): string => '<div class="wp-block-post-date"><time datetime="' . $date . '">' . $date . '</time></div>';
$post = static fn(string $title, string $date, string $extra = ''): string => '<html><head><meta property="og:type" content="article"><meta property="article:published_time" content="' . $date . '"></head><body><main><article><h1>' . $title . '</h1>' . $dated($date) . '<p>' . $title . ' body.</p>' . $terms . $extra . '</article></main></body></html>';
$entry = static fn(string $slug, string $title, string $date): string => '<article><h2><a href="/' . $slug . '/">' . $title . '</a></h2>' . $dated($date) . $terms . '</article>';
$archive = static fn(string $heading, string $entries): string => '<html><head><meta property="og:type" content="article"></head><body><main><h1>' . $heading . '</h1>' . $entries . '</main></body></html>';
$amsterdam = $entry('amsterdam', 'Amsterdam', '2026-05-29T11:11:39+00:00');
$rotterdam = $entry('rotterdam', 'Rotterdam', '2026-04-16T03:38:34+00:00');

$plan = ( new WordPressSitePlan() )->fromCompilerResult(( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<html><body><main><h1>Home</h1><a href="/amsterdam/">Amsterdam</a> <a href="/rotterdam/">Rotterdam</a></main></body></html>',
        'amsterdam/index.html' => $post('Amsterdam', '2026-05-29T11:11:39+00:00'),
        'rotterdam/index.html' => $post('Rotterdam', '2026-04-16T03:38:34+00:00', '<a href="/tag/linux/" rel="tag">Linux</a>'),
        'category/blog/index.html' => $archive('Blog', $amsterdam . $rotterdam),
        'category/foto/index.html' => $archive('Foto', $amsterdam . $rotterdam),
        'tag/linux/index.html' => $archive('Linux', $rotterdam),
    ),
))->toArray());
WordPressSitePlan::assertValid($plan);

$terms = array();
$collect = static function (mixed $node) use (&$collect, &$terms): void {
    if ( ! is_array($node) ) {
        return;
    }
    if ( 'taxonomy_term' === ($node['kind'] ?? null) ) {
        $terms[ $node['taxonomy'] . ':' . $node['slug'] ] = $node['membership_source_paths'];
        return;
    }
    array_map($collect, $node);
};
$collect($plan);
ksort($terms);

$expected = array(
    'category:blog' => array( 'amsterdam/index.html', 'rotterdam/index.html' ),
    'category:foto' => array( 'amsterdam/index.html', 'rotterdam/index.html' ),
    'post_tag:linux' => array( 'rotterdam/index.html' ),
);
if ( $expected !== $terms ) {
    fwrite(STDERR, 'FAIL: each archive is a term whose members are the posts it lists: ' . json_encode($terms) . PHP_EOL);
    exit(1);
}
$paths = array_column($plan['pages'], 'source_path');
sort($paths);
if ( array( 'amsterdam/index.html', 'index.html', 'rotterdam/index.html' ) !== $paths ) {
    fwrite(STDERR, 'FAIL: no archive materializes as a page or post: ' . json_encode($paths) . PHP_EOL);
    exit(1);
}
echo "taxonomy archive listed dates: passed\n";
