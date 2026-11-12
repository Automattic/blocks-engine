<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Reduced fixture from #4308: identical flex headers, only Home's ancestors differ.
$css = 'body{margin:0;font-family:sans-serif}.pin{position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;display:grid}header{pointer-events:auto;height:80px;background:#eee;display:flex;align-items:center;justify-content:space-between;padding:0 20px}main{padding:120px 20px 40px}.page{display:grid}';
$header = '<header><a href="/">Acme</a><nav><a href="/">Home</a> <a href="/about/">About</a></nav></header>';
$document = static fn (string $title, string $body): string => '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/style.css"></head><body>' . $body . '<footer><p>Footer text</p></footer></body></html>';
$home = '<main><h1>Welcome home</h1><p>Home page body text.</p></main>';
$about = $document('About', $header . '<main><h1>About us</h1><p>About page body text.</p></main>');
$parts = array();
foreach (array(false, true) as $wrapped) {
    $variant = $wrapped ? 'wrapped' : 'control';
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'style.css' => $css,
        'index.html' => $document('Home', $wrapped ? '<div class="page"><div class="inner"><div class="pin">' . $header . '</div>' . $home . '</div></div>' : $header . $home),
        'about/index.html' => $about,
    )))->toWordPressSitePlanView()['wordpress_site_plan'];
    WordPressSitePlan::assertValid($plan);
    $headers = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'header' === ($part['area'] ?? null)));
    $assert(1 === count($headers), $variant . ': one shared header part exists');
    $part = $headers[0];
    $parts[$variant] = $part['canonical_block_markup'];
    if ($wrapped) $assert(2 === count($part['placement']['source_paths'] ?? array()), 'Both routes share the nested header');
    $assert(str_contains($parts[$variant], '"type":"flex"') && !str_contains($parts[$variant], 'blocks-engine-css-owned-layout'), $variant . ': the header retains native flex layout');
    $templates = ThemeTemplates::rendered($plan);
    foreach (array('front-page', 'page', 'index', 'archive', 'search', '404', 'single') as $slug) {
        $assert(1 === substr_count($templates[$slug] ?? '', '"slug":"' . $part['slug'] . '"'), $variant . ': ' . $slug . ' references the shared header once');
    }
    foreach ($plan['pages'] as $page) {
        $assert(!str_contains($page['canonical_block_markup'], 'Acme') && !str_contains($page['canonical_block_markup'], '"tagName":"header"') && !str_contains($page['canonical_block_markup'], 'wp:template-part'), $variant . ': content contains no header copy or reference');
        if (!empty($page['entrypoint'])) $assert(str_contains($page['canonical_block_markup'], 'Home page body text.'), $variant . ': Home body is preserved');
    }
    if ($wrapped) {
        $assert(str_contains($templates['front-page'], 'pin'), 'Home retains its pinned wrapper in its own template');
        foreach (array('index', 'archive', 'search', '404', 'single') as $slug) {
            $assert(!str_contains($templates[$slug], 'pin') && !str_contains($templates[$slug], '"className":"page"'), $slug . ': fallback uses the shared page cluster, not the pinned front-page frame');
        }
    }
}
// Geometry tokens are document-seeded; compare the blocks without those IDs.
$normalize = static fn (string $markup): string => preg_replace('/be-inline-geometry-[a-f0-9]+/', 'be-inline-geometry', $markup) ?? $markup;
$assert($normalize($parts['wrapped']) === $normalize($parts['control']), 'Plain wrapper ancestors do not change the shared header blocks');

echo "wrapped-flex-header-shell: ok (control and wrapped routes share equivalent flex headers; all templates reference header; fallbacks use shared frame)\n";
