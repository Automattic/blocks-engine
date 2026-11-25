<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Most routes share one header. Two routes present the same header with its
// dropdown panel open; their headers are identical to each other. Each of
// them renders the site header in its own presentation, so both reference one
// variant part rather than a copy each.
$css = '*{box-sizing:border-box;margin:0}body{font-family:sans-serif}header{background:#123;color:#fff}.bar{display:flex;gap:24px;align-items:center;max-width:1100px;margin:0 auto;padding:16px 24px}.bar a{color:#fff}.panel{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;max-width:1100px;margin:0 auto;padding:24px;background:#234}main{max-width:900px;margin:0 auto;padding:48px 24px}';
$bar = '<div class="bar"><a class="brand" href="/index.html">Science Park</a><nav aria-label="Main"><a href="/about/index.html">About</a> <a href="/events/index.html">Events</a> <a href="/contact/index.html">Contact</a></nav></div>';
$panel = '<div class="panel"><a href="/accelerator/index.html">Accelerator</a><a href="/incubator/index.html">Incubator</a><a href="/labs/index.html">Labs</a></div>';
$page = static fn(string $title, string $header): string => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/site.css"></head><body><a class="skip" href="#content">Skip to content</a><header>' . $header . '</header><main id="content"><h1>' . $title . '</h1><p>' . $title . ' takes place at the science park.</p><p>Registration is open.</p></main></body></html>';
$files = array('website/site.css' => $css, 'website/index.html' => $page('Science Park', $bar));
foreach (array('about' => 'About us', 'events' => 'Events', 'contact' => 'Contact') as $slug => $title) $files["website/$slug/index.html"] = $page($title, $bar);
foreach (array('summit' => 'Ystad Summit', 'breakfast' => 'Partner Breakfast') as $slug => $title) $files["website/$slug/index.html"] = $page($title, $bar . $panel);

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$pages = array_column($plan['pages'], null, 'source_path');
$variants = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area'] && str_contains((string) $part['canonical_block_markup'], 'Accelerator')));
$assert(1 === count($variants), 'The two identical route headers render from one variant part: ' . json_encode(array_column($variants, 'slug')));
$assert(array('website/breakfast/index.html', 'website/summit/index.html') === array_values(array_unique(array_merge(...array_map(static fn(array $part): array => $part['placement']['source_paths'] ?? array_keys($part['provenance']['sources'] ?? array()), $variants)))), 'The variant part names both routes as its sources.');
foreach (array('website/summit/index.html', 'website/breakfast/index.html') as $source) {
    $template = (string) ($templates[$pages[$source]['route_template'] ?? ''] ?? '');
    $assert(str_contains($template, '"slug":"' . $variants[0]['slug'] . '"') && !str_contains($pages[$source]['canonical_block_markup'], '"tagName":"header"'), "{$source} renders the shared variant part from its template: {$template}");
}
$assert($pages['website/summit/index.html']['route_template'] === $pages['website/breakfast/index.html']['route_template'], 'Both routes render through one template.');
echo "route-variant-twin-parts: ok\n";
