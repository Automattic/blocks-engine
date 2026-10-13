<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Every route shares one header and links the same author stylesheets. A
// titled rel="stylesheet" is the document's preferred stylesheet set, so it
// applies on every route; an alternate set and a disabled sheet do not.
$page = static fn(string $title): string => '<!doctype html><html><head>'
    . '<link rel="stylesheet" title="theme" href="theme.css">'
    . '<link rel="alternate stylesheet" title="contrast" href="contrast.css">'
    . '<link rel="stylesheet" href="off.css" disabled>'
    . '</head><body><header class="masthead"><nav><a href="/index.html">Home</a><a href="/about.html">About</a></nav><span class="login">Log in</span></header><main><h1>' . $title . '</h1></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home'),
    'about.html' => $page('About'),
    'theme.css' => '.login{color:#c00}@media (max-width:600px){.login{display:none}}',
    'contrast.css' => '.login{color:#ff0}',
    'off.css' => '.login{color:#0f0}',
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$describe = static fn(): string => json_encode(array_map(static fn(array $asset): array => array($asset['path'] ?? null, $asset['source_path'] ?? null, $asset['stylesheet_activation'] ?? null), $plan['assets']));
$assert(1 === count(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null))), 'The header is shared.');
$shared = array_values(array_filter($plan['assets'], static fn(array $asset): bool => str_starts_with((string) ($asset['path'] ?? ''), 'assets/css/shared-chrome-')));
$theme = array_values(array_filter($shared, static fn(array $asset): bool => str_contains((string) ($asset['content'] ?? ''), 'display:none')));
$assert(1 === count($theme), 'The preferred titled stylesheet\'s chrome rules move into a site-wide shared-chrome stylesheet: ' . $describe());
$assert(array('kind' => 'global') === ($theme[0]['scopes'][0] ?? null) && str_contains((string) $theme[0]['content'], '#c00'), 'The shared-chrome stylesheet applies on every route.');
$assert('theme' === ($theme[0]['stylesheet_activation']['title'] ?? null) && true === ($theme[0]['stylesheet_activation']['active'] ?? null), 'The shared copy stays in its stylesheet set.');
$assert(array() === array_filter($shared, static fn(array $asset): bool => str_contains((string) ($asset['content'] ?? ''), '#ff0') || str_contains((string) ($asset['content'] ?? ''), '#0f0')), 'Alternate and disabled stylesheets are not promoted: ' . $describe());

echo "shared-chrome-titled-stylesheet contract passed\n";
