<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$chrome = static fn(string $main, string $footerExtra = ''): string => '<div id="secondary-nav" class="utility-bar"><a href="tel:+15550100">555-0100</a> <a href="/contact/">Contact</a></div>'
    . '<header class="site-header"><a href="/" class="logo">Logo</a><nav><a href="/news/">News</a><a href="/parent/">Our Parent Section</a><a href="/parent/child/">Child</a></nav></header>'
    . '<main>' . $main . '</main><footer class="site-footer"><div id="translate-widget">' . $footerExtra . '</div><p>© Neutral</p></footer>';
$dual = static fn(string $main, string $fx = ''): string => '<!doctype html><html><head><link rel="stylesheet" href="/site.css"></head><body>'
    . '<div class="data-blocks-engine-capture-desktop-document" data-blocks-engine-capture-device-document="desktop" data-blocks-engine-capture-document-scope="">' . $chrome($main, $fx) . '</div>'
    . '<div class="data-blocks-engine-capture-mobile-document" data-blocks-engine-capture-device-document="mobile" data-blocks-engine-capture-document-scope="">' . $chrome($main, $fx) . '</div></body></html>';
$plain = static fn(string $main): string => '<!doctype html><html><head><link rel="stylesheet" href="/site.css"></head><body>' . $chrome($main) . '</body></html>';
$files = array(
    'site.css' => '.data-blocks-engine-capture-mobile-document{display:none}@media (max-width:600px){.data-blocks-engine-capture-mobile-document{display:block}.data-blocks-engine-capture-desktop-document{display:none}}.utility-bar{background:#eee}',
    'index.html' => $dual('<h1>Home</h1><p>Welcome.</p>'),
    'news/index.html' => $dual('<h1>News</h1><p>Updates.</p>'),
    'odd-news/index.html' => $dual('<h1>Odd news</h1><p>Odd.</p>', '<span>Select Language</span>'),
    'parent/child/index.html' => $plain('<h1>Child</h1><p>Child page.</p>'),
);
$plan = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray());
$parts = array_column($plan['template_parts'], 'canonical_block_markup', 'slug');
$header = $parts['header'] ?? '';
foreach (array('desktop', 'mobile') as $device) {
    // Native navigation stores its anchor in block attributes until render.
    $html = preg_replace_callback('/<!--\s*wp:navigation\s+(\{.*?\})\s*\/-->/s', static function (array $match): string {
        $attrs = json_decode($match[1], true);
        return '<nav id="' . ($attrs['anchor'] ?? '') . '"></nav>';
    }, $header) ?? '';
    $html = preg_replace('/<!--.*?-->/s', '', $html) ?? '';
    if (1 !== preg_match('/<div[^>]*class="[^"]*data-blocks-engine-capture-' . $device . '-document[^"]*"[^>]*>(.*?)(?=<div[^>]*class="[^"]*data-blocks-engine-capture-|$)/s', $html, $variant)) {
        throw new RuntimeException('Missing header capture variant: ' . $device);
    }
    if (1 !== substr_count($variant[1], 'id="secondary-nav') || 1 !== substr_count($variant[1], '<header') || strpos($variant[1], 'id="secondary-nav') > strpos($variant[1], '<header')) {
        throw new RuntimeException('Leading utility bar must precede the header once in each capture variant: ' . $device);
    }
}
foreach ($plan['pages'] as $page) {
    if (str_contains($page['canonical_block_markup'] ?? '', 'secondary-nav')) throw new RuntimeException('Utility bar remains in page content: ' . $page['source_path']);
}

echo "capture-scope-leading-chrome-header: passed\n";
