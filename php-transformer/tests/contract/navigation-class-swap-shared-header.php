<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// A header whose current link is marked only by a class swap: no
// aria-current and no current/active token. The highlight class moves to
// the link naming the served route; everything else is identical. A route
// beneath a link's URL (a post under its section) highlights that link as
// its section: that look is no evidence of the link's rest, so the routes
// the link does name still share the header.
$page = static function (string $title, bool $about, bool $contact = false): string {
    [$home, $other] = $about ? array('k1', 'k2') : array($contact ? 'k1' : 'k2', 'k1');
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/style.css"></head><body>'
        . '<header id="top" class="bar"><a href="/" class="k1">Acme</a><nav><a href="/" class="' . $home . '">Home</a><a href="/about/" class="' . $other . '">About</a><a href="/contact/" class="' . ($contact ? 'k2' : 'k1') . '">Contact</a></nav></header>'
        . '<main class="content"><h1>' . $title . '</h1><p>' . $title . ' body text.</p></main>'
        . '<footer class="foot"><p>Copyright 2026 Acme. All rights reserved.</p></footer></body></html>';
};
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Welcome home', false),
    'about/index.html' => $page('About us', true),
    'about/team/index.html' => $page('Our team', true),
    'contact/index.html' => $page('Contact us', false, true),
    'style.css' => '.bar{display:flex;padding:16px;background:#222;color:#fff}.k1{color:#fff}.k2{color:#ff0;font-weight:700}',
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Routes differing only in which link carries the highlight class share one header part, got ' . count($headers));
foreach ($plan['pages'] as $routePage) if ('about/team/index.html' !== $routePage['source_path']) $assert(!str_contains($routePage['canonical_block_markup'], 'wp:navigation'), "{$routePage['source_path']} keeps no inline header.");

// No route's selection is baked into the shared menu: every link rests.
$menus = array_column($plan['menus'], 'block_markup');
$assert(1 === count($menus), 'One navigation entity serves every route, got ' . count($menus));
preg_match_all('/"label":"([^"]+)"/', $menus[0], $labels);
preg_match('/<!-- wp:navigation (\{.*?\}) \/-->/s', $headers[0]['canonical_block_markup'], $navigation);
foreach (json_decode($navigation[1], true)['metadata']['blocksEngineNavigationItems'] as $index => $item) {
    $classes = preg_split('/\s+/', (string) ($item['className'] ?? '')) ?: array();
    $assert(in_array('k1', $classes, true) && !in_array('k2', $classes, true) && !isset($item['style']), "{$labels[1][$index]} carries its resting presentation in the shared header: " . json_encode($item));
}

// The current link's colour follows the request: the navigation marks it on
// WordPress's current menu item.
$assert(1 === preg_match('/<!-- wp:navigation \{[^}]*"className":"[^"]*(blocks-engine-navigation-current-color-[a-f0-9]{64})/', $headers[0]['canonical_block_markup'], $marker), 'The shared navigation carries its current-item colour marker.');
$css = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null))));
$assert(1 === preg_match('/\.' . $marker[1] . '[^{]*\.current-menu-item[^{]*\{color:[^}]*#ff0/', $css), 'The highlight colour paints the current menu item.');
echo "ok\n";
