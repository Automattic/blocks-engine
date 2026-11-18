<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
foreach (array(
    'inline opacity' => array('opacity:0', ''),
    'inline visibility' => array('visibility:hidden', ''),
    'source opacity' => array('', '.video img{opacity:0}'),
    'source visibility' => array('', '.video img{visibility:hidden}'),
) as $case => [$hiddenStyle, $css]) {
    $card = static fn(string $slug, string $image, string $style): string => '<a class="card ' . $slug . '" href="/blog/' . $slug . '/"><div class="thumb" style="background-image:url(/media/video.jpg)"><img src="/media/' . $image . '" style="' . $style . '"></div><h2>Post ' . $slug . '</h2><time datetime="2026-10-01">1 October 2026</time></a>';
    $article = static fn(string $slug): string => '<main><article itemscope itemtype="https://schema.org/BlogPosting"><h1 itemprop="headline">Post ' . $slug . '</h1><time itemprop="datePublished" datetime="2026-10-01">1 October 2026</time><p>Full article body for ' . $slug . '.</p></article></main>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => '<main><h1>Home</h1><a href="/blog/">Blog</a></main>',
        'blog/index.html' => '<style>.card{display:block}.thumb{width:300px;height:160px}.thumb img{width:100%;height:100%}' . $css . '</style><main><h1>Blog</h1><section>' . $card('visible', 'visible.png', '') . $card('video', 'placeholder.png', $hiddenStyle) . '</section></main>',
        'blog/visible/index.html' => $article('visible'),
        'blog/video/index.html' => $article('video'),
        'media/visible.png' => $png,
        'media/placeholder.png' => $png,
        'media/video.jpg' => $png,
    )))->toArray();
    $plan = (new WordPressSitePlan())->fromCompilerResult($result);
    $pages = array_column($plan['pages'], null, 'source_path');
    $assert(str_contains($pages['blog/index.html']['canonical_block_markup'], '<!-- wp:query '), $case . ': listing becomes a query');
    foreach (array('visible' => 'visible.png', 'video' => 'video.jpg') as $slug => $expected) {
        $image = $pages['blog/' . $slug . '/index.html']['metadata']['featured_image'] ?? array();
        $assert(str_ends_with((string) ($image['target_path'] ?? ''), $expected), $case . ': ' . $slug . ' uses its painted image');
        $assets = array_column($plan['assets'], null, 'token');
        $asset = $assets[$image['asset_token'] ?? ''] ?? array();
        $assert(($asset['target_path'] ?? null) === $image['target_path'], $case . ': image resolves through the asset token');
    }
}
echo "listing card hidden placeholder: ok\n";
