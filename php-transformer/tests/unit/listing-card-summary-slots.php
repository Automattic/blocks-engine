<?php
declare(strict_types=1);

// A card that summarises a post (image, headline, description, date) becomes
// native post slots and never renders the whole article (#2900).

require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
$card = static fn(string $slug, string $title, string $summary, string $datetime, string $date): string => '<a class="card" href="/blog/' . $slug . '/" itemscope itemtype="https://schema.org/BlogPosting"><img class="card-image" itemprop="image" src="/images/' . $slug . '.png" alt="' . $title . ' cover"><div class="card-content"><h2 itemprop="headline">' . $title . '</h2><p itemprop="description">' . $summary . '</p><time itemprop="datePublished" datetime="' . $datetime . '">' . $date . '</time></div></a>';
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
$assert(!str_contains($markup, 'href="/blog/one/"') && !str_contains($markup, '"tagName":"a"'), 'native query slots replace the static card wrapper destination');
$assert(!str_contains($markup, 'wp:post-content'), 'a summary card never renders the whole article');
$assert(str_contains($markup, 'wp:post-title') && str_contains($markup, 'wp:post-excerpt'), 'headline and description stay native title and excerpt slots');
$assert(str_contains($markup, 'wp:post-date'), 'a time whose datetime proves the publication timestamp is the date slot, though its text only names the day');
$assert(1 === preg_match('/<!-- wp:post-featured-image (\{.*?\}) \/-->/', $markup, $featured), 'the card image is the native featured-image slot');
$attrs = json_decode($featured[1], true);
$assert(true === ($attrs['isLink'] ?? null) && '100%' === ($attrs['width'] ?? null) && '160px' === ($attrs['height'] ?? null) && 'cover' === ($attrs['scale'] ?? null) && str_contains((string) ($attrs['className'] ?? ''), 'card-image'), 'the featured-image slot keeps the source size, fit, link and class');
$writes = array_column(array_filter($plan['writes'], static fn(array $write): bool => str_ends_with((string) ($write['target_path'] ?? ''), '.png')), 'target_path');
foreach (array('one', 'two') as $slug) {
    $image = $pages['blog/' . $slug . '/index.html']['metadata']['featured_image'] ?? null;
    $assert(is_array($image) && 'Post ' . $slug . ' cover' === ($image['alt'] ?? null), "post {$slug} owns its own card image and alt text");
    $assert(str_ends_with((string) ($image['target_path'] ?? ''), $slug . '.png') && in_array($image['target_path'], $writes, true), "post {$slug} names the theme write that carries its image");
}
$assert(str_contains($pages['blog/one/index.html']['canonical_block_markup'], 'FULL ARTICLE BODY'), 'the article body stays on its own post');
echo "listing card summary slots: ok\n";
