<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A listing card's thumbnail becomes core/post-featured-image. Each post's own
// image must fill the box the source cards reserved, so a ratio every card
// image states with width/height carries as aspectRatio with scale cover.
// Cards that disagree keep today's featured image attributes.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$compile = static function (array $sizes) use ($png): array {
    $post = static fn(string $title, string $date, string $img): string => '<!doctype html><html><head><meta property="og:type" content="article"><meta property="article:published_time" content="' . $date . '"></head><body class="single"><main><article><h1>' . $title . '</h1><p>' . $title . ' body text.</p><img src="/media/' . $img . '.png" alt=""></article></main></body></html>';
    $card = static fn(string $slug, string $title, string $date, string $img, array $size): string => '<li class="wp-block-post"><div class="wp-block-post-date"><time datetime="' . $date . '">' . $date . '</time></div><a href="/' . $slug . '/"><div class="thumb"><img width="' . $size[0] . '" height="' . $size[1] . '" src="/media/' . $img . '.png" alt="preview"></div></a><h2 class="wp-block-post-title"><a href="/' . $slug . '/">' . $title . '</a></h2></li>';
    $files = array(
        'index.html' => '<!doctype html><html><head><style>.thumb img{width:100%;height:auto}</style></head><body class="home blog"><main><h1>Journal</h1><ul class="wp-block-post-template">' . $card('amsterdam', 'Amsterdam', '2026-05-29T11:11:39+00:00', 'ams', $sizes[0]) . $card('rotterdam', 'Rotterdam', '2026-04-16T03:38:34+00:00', 'rot', $sizes[1]) . '</ul></main></body></html>',
        'amsterdam/index.html' => $post('Amsterdam', '2026-05-29T11:11:39+00:00', 'ams'),
        'rotterdam/index.html' => $post('Rotterdam', '2026-04-16T03:38:34+00:00', 'rot'),
        'media/ams.png' => $png,
        'media/rot.png' => $png,
    );
    $plan = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray());
    $markup = implode("\n", array_column(array_merge($plan['templates'], $plan['pages']), 'canonical_block_markup'));
    if (!preg_match_all('/<!-- wp:post-template.*?<!-- \/wp:post-template -->/s', $markup, $templates)) throw new RuntimeException('The listing cards do not become a Query Loop.');
    if (!preg_match_all('/<!-- wp:post-featured-image (\{.*?\}) \/-->/s', implode("\n", $templates[0]), $images)) throw new RuntimeException('The card thumbnail does not become core/post-featured-image.');
    return array_map(static fn(string $json): array => json_decode($json, true), $images[1]);
};

foreach ($compile(array(array(510, 286), array(510, 286))) as $attrs) {
    if ('255/143' !== ($attrs['aspectRatio'] ?? null) || 'cover' !== ($attrs['scale'] ?? null)) {
        throw new RuntimeException('Shared card image ratio is not carried: ' . json_encode($attrs));
    }
}
foreach ($compile(array(array(510, 286), array(400, 400))) as $attrs) {
    if (isset($attrs['aspectRatio']) || isset($attrs['scale'])) {
        throw new RuntimeException('Mixed card image ratios must keep the featured image unchanged: ' . json_encode($attrs));
    }
}
echo "listing-card-featured-image-ratio: passed\n";
