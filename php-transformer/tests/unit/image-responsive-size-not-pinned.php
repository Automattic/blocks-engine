<?php
declare(strict_types=1);

// An image a media query resizes keeps its size in projected CSS, never as the
// desktop value pinned inline (or as an !important geometry carrier) over that
// media rule. Native listing slots copy this decision, so a card image stays
// responsive as core/post-featured-image too (#2928).

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message, string $context = ''): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n{$context}\n"); exit(1); }
};
$css = static fn(array $out): string => implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter((array) ($out['assets'] ?? array()), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''))));

// 1. Static core/image: the desktop size is not pinned over the phone rule.
$responsive = (new HtmlTransformer())->transform('<style>.thumb img{width:140px;height:100px;object-fit:cover}@media (max-width:640px){.thumb img{width:100%;height:180px}}</style><div class="thumb"><img src="a.png" alt="A"></div>')->toArray();
$markup = (string) $responsive['serialized_blocks'];
$assert(1 === preg_match('/<!-- wp:image (\{.*?\}) -->/', $markup, $image), 'the image stays core/image', $markup);
$attrs = json_decode($image[1], true);
$assert(!isset($attrs['width']) && !isset($attrs['height']), 'a size a media query changes is not a fixed width/height attribute', $image[1]);
$assert(!str_contains($markup, 'width:140px') && !str_contains($markup, 'height:100px'), 'no inline desktop size on the image', $markup);
$assert(1 !== preg_match('/\{[^}]*(?:width:140px|height:100px)!important/', $css($responsive)), 'no !important geometry carrier pins the desktop size', $css($responsive));

// 2. A size no media query changes is still carried natively (unchanged).
$fixed = (string) (new HtmlTransformer())->transform('<style>.thumb img{width:140px;height:100px;object-fit:cover}</style><div class="thumb"><img src="a.png" alt="A"></div>')->toArray()['serialized_blocks'];
$assert(str_contains($fixed, '"width":"140px"') && str_contains($fixed, '"height":"100px"'), 'an unconditional size stays a native width/height', $fixed);

// 3. A media rule that leaves the size alone does not drop it.
$unrelated = (string) (new HtmlTransformer())->transform('<style>.thumb img{width:140px;height:100px}@media (max-width:640px){.thumb img{opacity:.9}}</style><div class="thumb"><img src="a.png" alt="A"></div>')->toArray()['serialized_blocks'];
$assert(str_contains($unrelated, '"width":"140px"') && str_contains($unrelated, '"height":"100px"'), 'a media rule on another property keeps the native size', $unrelated);

// 4. Listing card: the featured-image slot does not pin the desktop size.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
$card = static fn(string $slug, string $datetime): string => '<a class="card" href="/blog/' . $slug . '/" itemscope itemtype="https://schema.org/BlogPosting"><img class="card-image" itemprop="image" src="/images/' . $slug . '.png" alt="' . $slug . '"><div><h2 itemprop="headline">Post ' . $slug . '</h2><p itemprop="description">Summary ' . $slug . '.</p><time itemprop="datePublished" datetime="' . $datetime . '">' . $datetime . '</time></div></a>';
$article = static fn(string $slug, string $datetime): string => '<main><article itemscope itemtype="https://schema.org/BlogPosting"><h1 itemprop="headline">Post ' . $slug . '</h1><time itemprop="datePublished" datetime="' . $datetime . '">' . $datetime . '</time><p>Body.</p></article></main>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1><a href="/blog/">Blog</a></main>',
    'blog/index.html' => '<style>.card{display:flex;gap:16px}.card img{width:140px;height:100px;object-fit:cover}@media (max-width:640px){.card{display:block}.card img{width:100%;height:180px}}</style><main><h1>Blog</h1><section>' . $card('one', '2026-10-03T02:10:54+00:00') . $card('two', '2026-10-01T02:11:07+00:00') . '</section></main>',
    'blog/one/index.html' => $article('one', '2026-10-03T02:10:54+00:00'),
    'blog/two/index.html' => $article('two', '2026-10-01T02:11:07+00:00'),
    'images/one.png' => $png,
    'images/two.png' => $png,
)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$listing = (string) (array_column($plan['pages'], null, 'source_path')['blog/index.html']['canonical_block_markup'] ?? '');
$assert(1 === preg_match('/<!-- wp:post-featured-image (\{.*?\}) \/-->/', $listing, $featured), 'the card image is the native featured-image slot', $listing);
$featuredAttrs = json_decode($featured[1], true);
$assert(!isset($featuredAttrs['width']) && !isset($featuredAttrs['height']), 'the featured-image slot leaves a responsive size to the projected media rule', $featured[1]);
$assert(str_contains($css($result), '@media (max-width:640px){.card{display:block}.card img{width:100%;height:180px}'), 'the phone size rule is projected');

fwrite(STDOUT, "image responsive size not pinned tests: passed\n");
