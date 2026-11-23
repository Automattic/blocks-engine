<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\RenderEquivalentMarkup;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// A server writes the full number it computed; a page whose header the client
// rendered reads back what the browser serialized, six significant digits.
// The two copies render the same chrome and must share the part.
$header = static fn (string $style): string => '<header id="top"><div class="bar"><a href="#welcome" class="logo">Studio</a><nav><a href="index.html">Home</a><a href="about.html">About</a><a href="team.html">Team</a></nav><div class="icon" style="' . $style . '"><svg viewBox="0 0 1 1"><path d="M0 0h1v1z"/></svg></div></div></header>';
$page = static fn (string $header, string $title): string => '<!doctype html><html><head><style>.bar{gap:8px}.icon{width:24px}</style></head><body>'
    . $header . '<main><h1>' . $title . '</h1><p>Body for ' . $title . '</p></main><footer><p>© Studio</p></footer></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page($header('padding-bottom: 119.526%'), 'Home'),
    'about.html' => $page($header('padding-bottom:119.5260663507109%'), 'About'),
    'team.html' => $page($header('padding-bottom:119.5260663507109%'), 'Team'),
)))->toWordPressSitePlanView()['wordpress_site_plan'];

$part = array_values(array_filter($plan['template_parts'], static fn (array $row): bool => 'header' === ($row['area'] ?? null)))[0] ?? null;
$assert(is_array($part) && 'shared_shell' === ($part['placement']['kind'] ?? null), 'The header extracts as a shared part.');
$assert(in_array('front-page', $part['placement']['template_slugs'] ?? array(), true), 'The browser-serialized home header joins the shared part: ' . json_encode($part['placement'] ?? null));

// Only digits a browser drops are ignored; a different value stays different.
$assert(RenderEquivalentMarkup::canonicalStyle('padding-bottom:119.5%') !== RenderEquivalentMarkup::canonicalStyle('padding-bottom:119.526%'), 'A different value keeps its identity.');
$assert('--ratio:1.4163311322274117' === RenderEquivalentMarkup::canonicalStyle('--ratio:1.4163311322274117'), 'Custom properties keep their tokens verbatim.');

echo "Render-equivalent chrome number precision contract passed.\n";
