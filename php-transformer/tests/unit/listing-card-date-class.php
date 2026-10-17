<?php
declare(strict_types=1);

// A card date whose author class sits on the <time> inside the engine's
// synthetic paragraph wrapper keeps that class on core/post-date (#3002).

require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
$card = static fn(string $slug, string $title, string $summary, string $datetime, string $date): string => '<a class="card" href="/blog/' . $slug . '/" itemscope itemtype="https://schema.org/BlogPosting"><img class="card-image" itemprop="image" src="/images/' . $slug . '.png" alt="' . $title . ' cover"><div class="card-content"><h2 class="card-title" itemprop="headline">' . $title . '</h2><p class="card-excerpt" itemprop="description">' . $summary . '</p><time class="card-date" datetime="' . $datetime . '" itemprop="datePublished">' . $date . '</time></div></a>';
$article = static fn(string $title, string $summary, string $datetime, string $date): string => '<html><head><title>' . $title . '</title><meta name="description" content="' . $summary . '"></head><body><main><article itemscope itemtype="https://schema.org/BlogPosting"><h1 itemprop="headline">' . $title . '</h1><time itemprop="datePublished" datetime="' . $datetime . '">' . $date . '</time><p>FULL ARTICLE BODY for ' . $title . '.</p><p>Second paragraph.</p></article></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1><a href="/blog/">Blog</a></main>',
    'blog/index.html' => '<style>.card{display:block}.card img{width:100%;height:160px;object-fit:cover}</style><main><h1>Blog</h1><section class="list">'
        . $card('one', 'Post one', 'Summary of post one.', '2026-10-03T02:10:54.919+00:00', '3 October 2026')
        . $card('two', 'Post two', 'Summary of post two.', '2026-10-01T02:11:07.115+00:00', '1 October 2026')
        . '</section></main>',
    'blog/one/index.html' => $article('Post one', 'Summary of post one.', '2026-10-03T02:10:54.919+00:00', '3 October 2026'),
    'blog/two/index.html' => $article('Post two', 'Summary of post two.', '2026-10-01T02:11:07.115+00:00', '1 October 2026'),
    'images/one.png' => $png,
    'images/two.png' => $png,
)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$pages = array_column($plan['pages'], null, 'source_path');
$markup = $pages['blog/index.html']['canonical_block_markup'];

$assert(str_contains($markup, '<!-- wp:query '), 'the listing becomes a native query loop');
$assert(1 === preg_match('/<!-- wp:post-date (\{.*?\}) \/-->/', $markup, $date), 'the card date is the native post-date slot');
$dateAttrs = json_decode($date[1], true);
$assert(is_array($dateAttrs) && str_contains((string) ($dateAttrs['className'] ?? ''), 'card-date'), 'the post-date slot keeps the authored card-date class despite the synthetic paragraph wrapper');
$assert(1 === preg_match('/<!-- wp:post-title (\{.*?\}) \/-->/', $markup, $title), 'the card headline is the native post-title slot');
$titleAttrs = json_decode($title[1], true);
$assert(is_array($titleAttrs) && str_contains((string) ($titleAttrs['className'] ?? ''), 'card-title'), 'the post-title slot still keeps the authored card-title class');
echo "listing card date class: ok\n";
