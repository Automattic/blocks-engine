<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Headings bound by class to the page's heading font restate what the
// theme.json heading element already gives them. Those declarations must
// yield to Global Styles (Styles > Typography > Headings); a heading set in a
// different font, and a non-heading using the heading font, keep theirs.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$html = '<!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body><header><nav><ul><li><a href="/a">Stories</a></li><li><a href="/b">About</a></li></ul></nav></header><main>'
    . '<section class="hero"><h1>Hero title</h1><p>Hero lede copy for the page.</p></section>'
    . '<h2 class="display">Display heading</h2><p>Body copy one for reading.</p>'
    . '<p class="display">A display paragraph set in the heading font.</p>'
    . '<div class="package"><h3>Package name</h3><p>Package details copy.</p></div>'
    . '<h3 class="mono">Different heading</h3><p>More body copy to read.</p>'
    . '</main></body></html>';
$css = 'body{font-family:Inter,sans-serif}'
    . '.display{font-family:var(--wp--preset--font-family--heading,"Playfair Display",serif);font-size:40px}'
    . '.hero h1{font-family:"Playfair Display",serif;font-size:56px}'
    . '.package h3{font-family:"Playfair Display",serif}'
    . '.mono{font-family:"Courier New",monospace}';

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'style.css' => $css)))->toArray()['source_reports']['wordpress_site_plan'];

$theme = array();
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
$families = array_column($theme['settings']['typography']['fontFamilies'] ?? array(), 'fontFamily', 'slug');
$headingFont = $theme['styles']['elements']['heading']['typography']['fontFamily'] ?? null;
$assert('var:preset|font-family|heading' === $headingFont, 'The theme.json heading element font is the heading preset.');
$assert('"Playfair Display",serif' === ($families['heading'] ?? null), 'The heading preset is the page heading stack.');

$authored = '';
foreach ($plan['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null)) $authored .= $asset['content'];
}
$rules = array();
preg_match_all('/([^{}]+)\{([^{}]*)\}/', $authored, $matches, PREG_SET_ORDER);
foreach ($matches as $match) $rules[] = array('selector' => trim($match[1]), 'body' => $match[2]);
$bodies = static fn(string $selector): array => array_column(array_filter($rules, static fn(array $rule): bool => $rule['selector'] === $selector), 'body');

foreach (array('.display', '.hero h1', '.package h3') as $selector) {
    foreach ($bodies($selector) as $body) $assert(!str_contains($body, 'font-family'), 'The default-font heading rule ' . $selector . ' no longer sets font-family.');
}
$assert(str_contains(implode(';', $bodies('.display')), 'font-size') && str_contains(implode(';', $bodies('.hero h1')), 'font-size'), 'Yielding the font keeps the rules\' other declarations.');
$nonHeading = implode(';', $bodies('.display:not(:where(h1,h2,h3,h4,h5,h6))'));
$assert(str_contains($nonHeading, 'font-family') && str_contains($nonHeading, 'Playfair Display'), 'Non-heading .display elements keep the author font.');
$mono = implode(';', $bodies('.mono'));
// A stack the theme holds as a preset is read through that preset alone.
$monoFamily = preg_match('/font-family:var\(--wp--preset--font-family--([a-z0-9-]+)\)/', $mono, $preset) ? ($families[$preset[1]] ?? '') : $mono;
$assert(str_contains($monoFamily, 'Courier New'), 'The different-font heading keeps its font-family declaration: ' . $mono);

echo "Heading font global style contract passed.\n";
