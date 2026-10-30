<?php
declare(strict_types=1);

// Unbound per-post text beside the card image must not render the article (#3636).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
$compile = static function (array $badges): array {
    $files = array('index.html' => '<main><h1>Home</h1><a href="/blog/">Blog</a></main>');
    $cards = '';
    foreach (array('one', 'two') as $index => $slug) {
        $title = 'Post ' . $slug;
        $summary = 'Summary of post ' . $slug . '.';
        $date = '2026-10-0' . ($index + 1);
        $cards .= '<article class="card"><a href="/blog/' . $slug . '/"><div class="image-wrap"><img src="/images/' . $slug . '.png" alt="' . $title . '"><div class="badge" aria-hidden="true">' . $badges[$index] . '</div></div></a><div><h2><a href="/blog/' . $slug . '/">' . $title . '</a></h2><p>' . $summary . '</p><time datetime="' . $date . '">' . $date . '</time></div></article>';
        $files['blog/' . $slug . '/index.html'] = '<html><head><title>' . $title . '</title><meta name="description" content="' . $summary . '"></head><body><main><article itemscope itemtype="https://schema.org/BlogPosting"><h1 itemprop="headline">' . $title . '</h1><time itemprop="datePublished" datetime="' . $date . '">' . $date . '</time><p>Full article for ' . $slug . '.</p></article></main></body></html>';
        $files['images/' . $slug . '.png'] = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
    }
    $files['blog/index.html'] = '<main><h1>Blog</h1><section class="cards">' . $cards . '</section></main>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    return array_column((new WordPressSitePlan())->fromCompilerResult($result)['pages'], null, 'source_path');
};

$pages = $compile(array('#836', '#837'));
$markup = $pages['blog/index.html']['canonical_block_markup'];
$assert('post' === $pages['blog/one/index.html']['post_type'] && 'post' === $pages['blog/two/index.html']['post_type'], 'both articles are posts');
$assert(!str_contains($markup, 'wp:post-content'), 'the badge never becomes post-content');
$assert(!str_contains($markup, 'wp:query'), 'an unbound per-post badge declines the query projection');
foreach (array('#836', '#837', 'Post one', 'Post two', 'Summary of post one.', 'Summary of post two.', '2026-10-01', '2026-10-02') as $text) {
    $assert(str_contains($markup, $text), 'the captured listing retains ' . $text);
}
$assert(2 === substr_count($markup, '<img '), 'both captured card images remain');

$shared = $compile(array('Read article', 'Read article'))['blog/index.html']['canonical_block_markup'];
$assert(str_contains($shared, 'wp:query'), 'identical card text remains chrome in a native loop');
$assert(str_contains($shared, 'Read article') && !str_contains($shared, 'wp:post-content'), 'shared chrome is retained without rendering the body');
echo "listing card text badge: ok\n";
