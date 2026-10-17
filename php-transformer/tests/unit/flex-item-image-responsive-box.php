<?php
declare(strict_types=1);

// A flex-item image keeps its core/image figure in the DOM as
// `display:contents`, so an author class on that figure sizes nothing. The
// author's own item box (width, height, flex) must reach the <img> itself at
// each rule's cascade position, so a media rule still resizes it, both as
// core/image and as a listing card's core/post-featured-image (#2973).

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message, string $context = ''): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n{$context}\n"); exit(1); }
};
$css = static fn(array $out): string => implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter((array) ($out['assets'] ?? array()), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''))));
// Offset of the first rule whose selector list holds $selector with exactly $body.
$ruleAt = static function (string $sheet, string $selector, string $body): int|false {
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $sheet, $rules, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($rules as $rule) {
        if ($body === $rule[2][0] && in_array($selector, array_map('trim', explode(',', $rule[1][0])), true)) return $rule[0][1];
    }
    return false;
};
$styles = '<style>.card{display:flex;gap:16px}.thumb{width:140px;height:100px;object-fit:cover;flex-shrink:0}@media (max-width:640px){.card{flex-direction:column}.thumb{width:100%;height:180px}}</style>';
$flex = '.wp-block-image:where(.blocks-engine-synthetic-flex-image-figure) > a > img';
$featured = '.wp-block-post-featured-image:where(.blocks-engine-synthetic-flex-image-figure) > a > img';

// 1. Static core/image inside a flex card link.
$static = (new HtmlTransformer())->transform($styles . '<main><a class="card" href="/x"><img class="thumb" src="a.png" alt="A"><div><h2>Title</h2></div></a></main>')->toArray();
$markup = (string) $static['serialized_blocks'];
$sheet = $css($static);
$assert(str_contains($markup, 'blocks-engine-synthetic-flex-image-figure'), 'the image is a flex-item figure', $markup);
$desktop = $ruleAt($sheet, '.thumb' . $flex, 'width:140px;height:100px;flex-shrink:0');
$mobile = $ruleAt($sheet, '.thumb' . $flex, 'width:100%;height:180px');
$assert(false !== $desktop, 'the desktop item box reaches the image inside the display:contents figure', $sheet);
$assert(false !== $mobile && $mobile > $desktop, 'the phone item box follows it, inside its media rule', $sheet);
$media = strpos($sheet, '@media (max-width:640px){');
$assert(false !== $media && $mobile > $media && $desktop < $media, 'the phone box keeps its media condition', $sheet);

// 2. A non-flex image gets no flex-item box rule.
$block = $css((new HtmlTransformer())->transform('<style>.thumb{width:140px;height:100px}</style><main><div><img class="thumb" src="a.png" alt="A"></div></main>')->toArray());
$assert(!str_contains($block, 'synthetic-flex-image-figure) > img{'), 'a block-flow image is unchanged', $block);

// 3. Listing card: the featured-image slot receives the same box.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
$card = static fn(string $slug, string $datetime): string => '<a class="card" href="/blog/' . $slug . '/" itemscope itemtype="https://schema.org/BlogPosting"><img class="thumb" itemprop="image" src="/images/' . $slug . '.png" alt="' . $slug . '"><div><h2 itemprop="headline">Post ' . $slug . '</h2><time itemprop="datePublished" datetime="' . $datetime . '">' . $datetime . '</time></div></a>';
$article = static fn(string $slug, string $datetime): string => '<main><article itemscope itemtype="https://schema.org/BlogPosting"><h1 itemprop="headline">Post ' . $slug . '</h1><time itemprop="datePublished" datetime="' . $datetime . '">' . $datetime . '</time><p>Body.</p></article></main>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1><a href="/blog/">Blog</a></main>',
    'blog/index.html' => $styles . '<main><h1>Blog</h1><section>' . $card('one', '2026-10-03T02:10:54+00:00') . $card('two', '2026-10-01T02:11:07+00:00') . '</section></main>',
    'blog/one/index.html' => $article('one', '2026-10-03T02:10:54+00:00'),
    'blog/two/index.html' => $article('two', '2026-10-01T02:11:07+00:00'),
    'images/one.png' => $png,
    'images/two.png' => $png,
)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$listing = (string) (array_column($plan['pages'], null, 'source_path')['blog/index.html']['canonical_block_markup'] ?? '');
$assert(1 === preg_match('/<!-- wp:post-featured-image \{[^}]*blocks-engine-synthetic-flex-image-figure/', $listing), 'the card image is a flex-item featured-image slot', $listing);
$listingCss = $css($result);
$assert(false !== $ruleAt($listingCss, '.thumb' . $featured, 'width:140px;height:100px;flex-shrink:0') && false !== $ruleAt($listingCss, '.thumb' . $featured, 'width:100%;height:180px'), 'the featured-image slot receives the desktop and phone item box', $listingCss);

fwrite(STDOUT, "flex item image responsive box tests: passed\n");
