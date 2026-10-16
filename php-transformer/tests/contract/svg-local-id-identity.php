<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Every route draws the same logo, but its author gave each route's copy its
// own gradient id. A materialized SVG is a standalone document whose ids name
// only its own definitions, so the routes share one logo asset and the header
// around it is one shared part.
$routes = array('index' => 'Home', 'about' => 'About', 'visit' => 'Visit');
$logo = static fn (string $id): string => '<svg viewBox="0 0 32 32" width="32" height="32" aria-hidden="true"><defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f5e8c4"/><stop offset="1" stop-color="#d9a23a"/></linearGradient></defs><rect x="4" y="4" width="24" height="24" rx="4" fill="url(#' . $id . ')"/></svg>';
$page = static function (string $current) use ($routes, $logo): string {
    $menu = '';
    foreach ($routes as $slug => $label) $menu .= '<li><a href="' . $slug . '.html">' . $label . '</a></li>';
    return '<!doctype html><html><head><title>' . $routes[$current] . '</title><style>.primary a{color:#123456}</style></head><body>'
        . '<header><a class="brand" href="index.html">' . $logo('mark-' . $current) . '<span>Harbor</span></a><nav class="primary"><ul>' . $menu . '</ul></nav></header>'
        . '<main><h1>' . $routes[$current] . '</h1><p>' . $routes[$current] . ' body copy.</p></main></body></html>';
};
$files = array();
foreach (array_keys($routes) as $slug) $files['website/' . $slug . '.html'] = $page($slug);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];

$svgAssets = array();
array_walk_recursive($plan, static function ($value) use (&$svgAssets): void {
    if (is_string($value) && preg_match_all('/inline-svg-[a-f0-9]{16}\.svg/', $value, $matches)) foreach ($matches[0] as $name) $svgAssets[$name] = true;
});
$assert(1 === count($svgAssets), 'Every route draws its logo from one asset: ' . json_encode(array_keys($svgAssets)));

$header = array_values(array_filter($plan['template_parts'] ?? array(), static fn (array $part): bool => 'header' === ($part['area'] ?? '') || 'header' === ($part['slug'] ?? '')));
$assert(1 === count($header), 'The header is one shared part: ' . json_encode(array_column($plan['template_parts'] ?? array(), 'slug')));
foreach ($plan['pages'] as $document) {
    $assert(! str_contains((string) $document['canonical_block_markup'], 'inline-svg-'), 'The logo renders from the shared header, not from ' . $document['source_path']);
}

// The asset stays a working picture: its definition and reference agree.
$asset = array_values(array_filter($plan['assets'] ?? array(), static fn (array $item): bool => str_contains((string) ($item['target_path'] ?? $item['path'] ?? ''), 'inline-svg-')));
$assert(1 === count($asset), 'One logo asset is materialized.');
$content = (string) $asset[0]['content'];
$assert(1 === preg_match('/\sid="([^"]+)"/', $content, $id) && str_contains($content, 'url(#' . $id[1] . ')'), 'The shared logo references its own gradient: ' . $content);

// Only id spelling is ignored: a logo whose gradient differs is a different
// picture, and two definitions in one picture stay two definitions.
$pair = static fn (string $a, string $b, string $stop): string => '<svg viewBox="0 0 32 32" width="32" height="32" aria-hidden="true"><defs><linearGradient id="' . $a . '"><stop offset="0" stop-color="' . $stop . '"/></linearGradient><clipPath id="' . $b . '"><circle cx="16" cy="16" r="12"/></clipPath></defs><rect width="32" height="32" fill="url(#' . $a . ')" clip-path="url(#' . $b . ')"/></svg>';
$distinct = array();
foreach (array(array('g-one', 'c-one', '#111111'), array('g-two', 'c-two', '#111111'), array('g-three', 'c-three', '#222222')) as $index => $ids) {
    $doc = '<!doctype html><html><head><title>P' . $index . '</title></head><body><main>' . $pair(...$ids) . '<p>Copy ' . $index . '.</p></main></body></html>';
    $single = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array('website/index.html' => $doc)))->toWordPressSitePlanView()['wordpress_site_plan'];
    foreach ($single['assets'] ?? array() as $item) {
        if (str_contains((string) ($item['target_path'] ?? ''), 'inline-svg-')) $distinct[] = basename((string) $item['target_path']);
    }
}
$assert(3 === count($distinct) && $distinct[0] === $distinct[1] && $distinct[0] !== $distinct[2], 'Renamed definitions address the same picture, a different paint a different one: ' . json_encode($distinct));

echo "svg local id identity contract passed\n";
