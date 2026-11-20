<?php
declare(strict_types=1);

// Related entries' dates must not cancel this article's sibling date slot (#7388).
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message, mixed $context = null): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n" . json_encode($context, JSON_UNESCAPED_SLASHES) . "\n");
        exit(1);
    }
};
$related = '<aside><article class="item"><time datetime="2026-01-01T00:00:00Z">2026.01.01</time><a href="/posts/7/index.html">Post 7</a></article>'
    . '<article class="item"><time datetime="2026-02-02T00:00:00Z">2026.02.02</time><a href="/posts/8/index.html">Post 8</a></article></aside>';
$linked = '<aside><a href="/posts/7/index.html"><time datetime="2026-01-01T00:00:00Z">2026.01.01</time> Post 7</a></aside>'
    . '<ul class="pager"><li><a href="/posts/8/index.html"><time datetime="2026-02-02T00:00:00Z">2026.02.02</time> Post 8</a></li></ul>';
$shapes = array(
    // Related entries as dated article cards beside the article.
    'related cards' => static fn(string $entry): string => '<article class="entry">' . $entry . '</article>' . $related,
    // Related links and a pager inside the article, captured twice as responsive copies.
    'linked dates inside responsive copies' => static fn(string $entry): string => str_repeat('<article class="entry">' . $entry . $linked . '</article>', 2),
);
foreach ($shapes as $shape => $markup) {
    $files = array('index.html' => '<main><h1>Home</h1></main>');
    foreach (array(1 => '2026-10-16T14:30:19Z', 2 => '2026-10-17T14:30:19Z') as $id => $date) {
        $entry = '<time datetime="' . $date . '">2026.10.' . (15 + $id) . '</time><h1>Post ' . $id . '</h1><p>Body.</p>';
        $files["posts/{$id}/index.html"] = '<meta property="og:type" content="article"><main>' . $markup($entry) . '</main>';
    }
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    $plan = (new WordPressSitePlan())->fromCompilerResult($result);
    $pages = array_column($plan['pages'], null, 'source_path');
    foreach (array(1 => '2026-10-16T14:30:19Z', 2 => '2026-10-17T14:30:19Z') as $id => $date) {
        $page = $pages["posts/{$id}/index.html"];
        $decision = $page['content_decision'];
        $assert('post' === $page['post_type'] && 'inferred' === $decision['state'], "{$shape}: post {$id} remains a post", $decision);
        $assert(array('html:visible-date', 'listing:parent') === array_column($decision['evidence'], 'source'), "{$shape}: post {$id} retains sibling evidence", $decision);
        $assert($date === ($page['publication_timestamp'] ?? null), "{$shape}: post {$id} uses its own full instant", $page);
    }
    WordPressSitePlan::assertValid($plan);
}
fwrite(STDOUT, "article date with related cards tests: passed\n");
