<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// A header whose current link is marked only by a class swap: no
// aria-current and no current/active token. The highlight class moves to
// the link naming the served route; everything else is identical.
$page = static function (string $title, bool $about): string {
    [$home, $other] = $about ? array('k1', 'k2') : array('k2', 'k1');
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/style.css"></head><body>'
        . '<header id="top" class="bar"><a href="/" class="k1">Acme</a><nav><a href="/" class="' . $home . '">Home</a><a href="/about/" class="' . $other . '">About</a></nav></header>'
        . '<main class="content"><h1>' . $title . '</h1><p>' . $title . ' body text.</p></main>'
        . '<footer class="foot"><p>Copyright 2026 Acme. All rights reserved.</p></footer></body></html>';
};
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Welcome home', false),
    'about/index.html' => $page('About us', true),
    'style.css' => '.bar{display:flex;padding:16px;background:#222;color:#fff}.k1{color:#fff}.k2{color:#ff0;font-weight:700}',
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Routes differing only in which link carries the highlight class share one header part, got ' . count($headers));
foreach ($plan['pages'] as $routePage) $assert(!str_contains($routePage['canonical_block_markup'], 'wp:navigation'), "{$routePage['source_path']} keeps no inline header.");

// No route's selection is baked into the shared menu: every link rests.
$menus = array_column($plan['menus'], 'block_markup');
$assert(1 === count($menus), 'One navigation entity serves every route, got ' . count($menus));
preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/s', $menus[0], $links);
foreach ($links[1] as $json) {
    $attrs = json_decode($json, true);
    $classes = preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array();
    $assert(in_array('k1', $classes, true) && !in_array('k2', $classes, true) && !isset($attrs['style']), "{$attrs['label']} carries its resting presentation: {$json}");
}

// The current link's colour follows the request: the navigation marks it on
// WordPress's current menu item.
$assert(1 === preg_match('/<!-- wp:navigation \{[^}]*"className":"[^"]*(blocks-engine-navigation-current-color-[a-f0-9]{64})/', $headers[0]['canonical_block_markup'], $marker), 'The shared navigation carries its current-item colour marker.');
$css = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null))));
$assert(1 === preg_match('/\.' . $marker[1] . '[^{]*\.current-menu-item[^{]*\{color:[^}]*#ff0/', $css), 'The highlight colour paints the current menu item.');
echo "ok\n";
