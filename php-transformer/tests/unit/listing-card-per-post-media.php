<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A listing card's photo strip belongs to its post. Only the first image is the
// post's featured image; a Query Loop must not repeat card 1's other images on
// every row, while an image that every card shares remains template chrome.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$compile = static function (callable $second) use ($png): array {
    $post = static fn(string $title, string $date, string $img): string => '<!doctype html><html><head><meta property="og:type" content="article"><meta property="article:published_time" content="' . $date . '"></head><body class="single"><main><article><h1>' . $title . '</h1><p>' . $title . ' body text.</p><img src="/media/' . $img . '-a.png" alt=""></article></main></body></html>';
    $card = static fn(string $slug, string $title, string $date, string $img): string => '<li class="wp-block-post"><div class="wp-block-post-date"><time datetime="' . $date . '">' . $date . '</time></div><div class="photo-strip-images"><div class="photo-strip-image"><a href="/' . $slug . '/"><img src="/media/' . $img . '-a.png" alt=""></a></div><div class="photo-strip-image"><a href="' . ('shared' === $second($img) ? '/about/' : '/' . $slug . '/') . '" tabindex="-1" aria-hidden="true"><img src="/media/' . $second($img) . '.png" alt=""></a></div></div><h2 class="wp-block-post-title"><a href="/' . $slug . '/">' . $title . '</a></h2></li>';
    $files = array(
        'index.html' => '<!doctype html><html><head></head><body class="home blog"><main><h1>Journal</h1><ul class="wp-block-post-template">' . $card('amsterdam', 'Amsterdam', '2026-05-29T11:11:39+00:00', 'ams') . $card('rotterdam', 'Rotterdam', '2026-04-16T03:38:34+00:00', 'rot') . '</ul></main></body></html>',
        'amsterdam/index.html' => $post('Amsterdam', '2026-05-29T11:11:39+00:00', 'ams'),
        'rotterdam/index.html' => $post('Rotterdam', '2026-04-16T03:38:34+00:00', 'rot'),
        'media/shared.png' => $png,
    );
    foreach (array('ams', 'rot') as $image) foreach (array('a', 'b') as $variant) $files["media/$image-$variant.png"] = $png;
    $plan = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray());
    $home = implode("\n", array_column(array_merge($plan['templates'], $plan['pages']), 'canonical_block_markup'));
    $tokens = array_column(array_filter($plan['assets'], static fn(array $asset): bool => isset($asset['token'])), 'token', 'target_path');
    return array('home' => $home, 'tokens' => $tokens);
};

$perPost = $compile(static fn(string $img): string => $img . '-b');
preg_match_all('/<!-- wp:post-template.*?<!-- \/wp:post-template -->/s', $perPost['home'], $templates);
$template = implode("\n", $templates[0]);
if (str_contains($template, $perPost['tokens']['assets/media/ams-b.png'])) throw new RuntimeException('The post template repeats the first card\'s own image on every row.');
foreach (array('ams-b', 'rot-b') as $image) if (!str_contains($perPost['home'], $perPost['tokens']["assets/media/$image.png"])) throw new RuntimeException("The listing keeps {$image} on its own card.");

$shared = $compile(static fn(string $img): string => 'shared');
if (!str_contains($shared['home'], '<!-- wp:post-template') || !str_contains($shared['home'], '<!-- wp:post-featured-image')) throw new RuntimeException('Card chrome that every card shares still materializes a Query Loop.');
echo "listing-card-per-post-media: passed\n";
