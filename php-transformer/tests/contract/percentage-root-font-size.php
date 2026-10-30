<?php
declare(strict_types=1);

// A percentage root font size (`html{font-size:93.75%}`) is the root that
// every `rem` in the source resolves against. Design tokens derived from rem
// lengths must use it, not the 16px initial value (#3286; px precedent #3065).

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$compile = static function (string $html): array {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $theme = array();
    foreach ($plan['writes'] ?? array() as $write) {
        if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    }
    $css = '';
    foreach ($plan['assets'] ?? array() as $asset) {
        if ('css' === ($asset['kind'] ?? null) && 'editor' !== ($asset['stylesheet_target'] ?? 'both')) $css .= $asset['content'];
    }
    $root = $result['source_reports']['compiled_site']['pages'][0]['design_usage']['root_font_size_px'] ?? null;
    return array($root, $theme, $css, $plan);
};

// Computed px of a length under the given root: px literal or rem.
$computed = static function (string $length, float $root): ?float {
    if (1 !== preg_match('/^(\d*\.?\d+)(px|rem)$/', trim($length), $m)) return null;
    return 'rem' === $m[2] ? (float) $m[1] * $root : (float) $m[1];
};
$near = static fn(?float $actual, float $expected): bool => null !== $actual && abs($actual - $expected) < 0.01;

// Root resolution: relative units on the root use the 16px initial value.
$assert(15.0 === StyleResolver::rootFontSizePx('93.75%'), '93.75% resolves to 15px');
$assert(10.0 === StyleResolver::rootFontSizePx('62.5%'), '62.5% resolves to 10px');
$assert(10.0 === StyleResolver::rootFontSizePx('10px'), 'px root keeps its value');
$assert(20.0 === StyleResolver::rootFontSizePx('1.25rem') && 20.0 === StyleResolver::rootFontSizePx('1.25em'), 'em/rem root resolves against 16px');
$assert(16.0 === StyleResolver::rootFontSizePx(''), 'no root declaration is the 16px initial value');
$assert(null === StyleResolver::rootFontSizePx('larger') && null === StyleResolver::rootFontSizePx('0%'), 'unresolvable roots stay unknown');

$fixture = '<style>html{font-size:93.75%}body{font-size:1rem}nav a{font-size:1.13333rem}</style>'
    . '<body><nav><a href="/">Home</a><a href="/about/">About</a></nav><p>Body copy</p></body>';

foreach (array('bare fixture' => $fixture, 'full document' => '<!doctype html><html lang="en"><head><title>Root</title>' . substr($fixture, 0, strpos($fixture, '<body>')) . '</head>' . substr($fixture, strpos($fixture, '<body>')) . '</html>') as $label => $html) {
    [$root, $theme, $css, $plan] = $compile($html);
    $assert(15.0 === (float) $root, "$label: the derived root font size is 15px (got " . json_encode($root) . ')');
    $assert(str_contains($css, 'html{font-size:93.75%}'), "$label: the carried stylesheet keeps the authored root so rem presets compute against it");

    $presets = array_column($theme['settings']['typography']['fontSizes'] ?? array(), 'size', 'slug');
    $presetPx = array_map(static fn(string $size): ?float => $computed($size, 15.0), $presets);
    $assert(2 === count($presetPx), "$label: two font-size presets (" . json_encode($presets) . ')');
    $assert(array() !== array_filter($presetPx, static fn(?float $px): bool => $near($px, 15.0)), "$label: a preset computes to 15px (" . json_encode($presets) . ')');
    $assert(array() !== array_filter($presetPx, static fn(?float $px): bool => $near($px, 17.0)), "$label: a preset computes to 17px (" . json_encode($presets) . ')');

    // Body: the theme root and the carried body rule read a 15px preset.
    $assert(1 === preg_match('/^var:preset\|font-size\|([a-z0-9-]+)$/', (string) ($theme['styles']['typography']['fontSize'] ?? ''), $bodyRoot), "$label: theme body font size is a preset");
    $assert($near($presetPx[$bodyRoot[1]] ?? null, 15.0), "$label: body computes 15px through the theme root preset");
    $assert(1 === preg_match('/(?:^|\})body\{font-size:var\(--wp--preset--font-size--([a-z0-9-]+),1rem\)\}/', $css, $bodyRule), "$label: body rule binds a preset with its rem fallback");
    $assert($near($presetPx[$bodyRule[1]] ?? null, 15.0) && $near($computed('1rem', 15.0), 15.0), "$label: body rule computes 15px");

    // Link: the carried nav link rule reads a 17px preset.
    $assert(1 === preg_match('/ a\{font-size:var\(--wp--preset--font-size--([a-z0-9-]+),1\.13333rem\)\}/', $css, $linkRule), "$label: nav link rule binds a preset with its rem fallback");
    $assert($near($presetPx[$linkRule[1]] ?? null, 17.0), "$label: link computes 17px through its preset");
}

// #3065 precedent: a px root on an attribute-less html element.
[$pxRoot] = $compile('<style>html{font-size:10px}body{font-size:1.5rem}</style><body><p>Body copy</p></body>');
$assert(10.0 === (float) $pxRoot, 'a px root is derived as the document root (got ' . json_encode($pxRoot) . ')');

fwrite(STDOUT, "Percentage root font size contract passed ({$assertions} assertions)\n");
