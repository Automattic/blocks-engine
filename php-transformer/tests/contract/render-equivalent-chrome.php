<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Two pages carry the server-rendered header and footer. The home page's copy
// was reserialized by the browser: attribute and declaration order, spacing,
// `grid-area` for `grid-row`/`grid-column`, a `calc(0px)` zero, and a capture
// diagnostic on one link. It renders the same chrome and must share the parts.
$serverHeader = '<header id="top"><div style="display:grid;grid-template-columns:repeat(2, 1fr)" class="bar"><a href="#welcome" class="logo">Studio</a><nav><a href="index.html">Home</a><a href="about.html">About</a><a href="team.html">Team</a></nav></div></header>';
$browserHeader = '<header id="top"><div class="bar" style="display: grid; grid-template-columns: repeat(2, 1fr);"><a class="logo" href="#welcome" data-dla-anchor-unresolved="runtime scroll did not move before timeout">Studio</a><nav><a href="index.html">Home</a><a href="about.html">About</a><a href="team.html">Team</a></nav></div></header>';
$serverFooter = '<footer id="foot"><div style="grid-row:1 / span 1;grid-column:1 / span 2;margin:0" class="cell"><p>© Studio</p></div></footer>';
$browserFooter = '<footer id="foot"><div class="cell" style="grid-area: 1 / 1 / span 1 / span 2; margin: calc(0px);"><p>© Studio</p></div></footer>';
$page = static fn (string $header, string $title, string $footer): string => '<!doctype html><html><head><style>.bar{gap:8px}#foot{display:grid}</style></head><body>'
    . $header . '<main><h1>' . $title . '</h1><p>Body for ' . $title . '</p></main>' . $footer . '</body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page($browserHeader, 'Home', $browserFooter),
    'about.html' => $page($serverHeader, 'About', $serverFooter),
    'team.html' => $page($serverHeader, 'Team', $serverFooter),
)))->toWordPressSitePlanView()['wordpress_site_plan'];

foreach (array('header', 'footer') as $area) {
    $part = array_values(array_filter($plan['template_parts'], static fn (array $row): bool => $area === ($row['area'] ?? null)))[0] ?? null;
    $assert(is_array($part) && 'shared_shell' === ($part['placement']['kind'] ?? null), "The {$area} extracts as a shared part.");
    $assert(in_array('front-page', $part['placement']['template_slugs'] ?? array(), true), "The browser-reserialized home {$area} joins the shared part: " . json_encode($part['placement']));
}
foreach ($plan['pages'] as $row) $assert(!str_contains($row['canonical_block_markup'], '© Studio') && !str_contains($row['canonical_block_markup'], '"anchor":"top"'), $row['source_path'] . ' renders the shared chrome, not its own.');

echo "Render-equivalent chrome contract passed.\n";
