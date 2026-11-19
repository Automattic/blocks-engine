<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A router swaps arbitrary utility paint, with no aria-current or active
// class. Observe the same item at rest on other routes, not its siblings.
$document = static function (string $current, bool $variant = false): string {
    $links = '';
    foreach (array('index.html' => 'Home', 'about.html' => 'About', 'contact.html' => 'Contact') as $path => $label) {
        $links .= '<a class="nav-link hover:text-primary ' . ($path === $current ? 'text-primary' : 'text-foreground/70') . '" href="/' . $path . '">' . $label . '</a>';
    }
    return '<!doctype html><html><head><title>' . $current . '</title><link rel="stylesheet" href="site.css"></head><body><header' . ($variant ? ' class="alternate"' : '') . '><nav>' . $links . '</nav></header><main><h1>' . $current . '</h1></main><footer><p>Contact us</p></footer></body></html>';
};
$compile = static function (bool $variant = false, string $prefix = '') use ($document): array {
    $files = array($prefix . 'site.css' => '.nav-link{font-weight:500}.text-primary{color:#aa8800}.text-foreground\\/70{color:#223344}.alternate{padding:40px}');
    foreach (array('index.html', 'about.html', 'contact.html') as $path) $files[$prefix . $path] = $document($path, $variant && 'about.html' === $path);
    return (new ArtifactCompiler())->compile(array('entrypoint' => $prefix . 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
};
$headers = static fn(array $plan): array => array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$sources = array();
foreach (array('index.html', 'about.html', 'contact.html') as $path) $sources[] = array('kind' => 'html', 'path' => $path, 'content' => $document($path));
$projected = (new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RouteStateProjector())->project($sources, 'index.html')['files'];
foreach ($projected as $file) {
    if (str_contains($file['content'], 'aria-current=')) throw new RuntimeException('Inferred utility paint must not introduce authored aria-current semantics.');
    if (3 !== substr_count($file['content'], 'data-blocks-engine-current-sources=')) throw new RuntimeException('Every peer must carry the observed route selection.');
}
$plan = $compile();
if (1 !== count($headers($plan))) throw new RuntimeException('Self-link utility paint must not fragment a shared header.');
foreach ($plan['pages'] as $page) {
    if (str_contains($page['canonical_block_markup'], '<header')) throw new RuntimeException('Every route must use the shared header.');
}
if (1 === count($headers($compile(true)))) throw new RuntimeException('An authored header layout variant must remain distinct.');
$nestedSources = array();
foreach ($sources as $source) {
    $source['path'] = 'website/' . $source['path'];
    $nestedSources[] = $source;
}
$nested = (new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RouteStateProjector())->project($nestedSources, 'website/index.html')['files'];
foreach ($nested as $file) {
    if (str_contains($file['content'], 'aria-current=')) throw new RuntimeException('Nested imports must not introduce authored aria-current semantics.');
    if (3 !== substr_count($file['content'], 'data-blocks-engine-current-sources=')) throw new RuntimeException('Root-relative self links must be resolved inside the entry artifact directory.');
}
$nestedPlan = $compile(false, 'website/');
if (1 !== count($headers($nestedPlan))) throw new RuntimeException('An import directory must not fragment shared utility-painted navigation.');
$cssBytes = static function (array $plan): int {
    $bytes = 0;
    foreach ($plan['assets'] ?? array() as $asset) {
        if (str_ends_with((string) ($asset['path'] ?? ''), '.css')) $bytes += strlen((string) ($asset['content'] ?? ''));
    }
    return $bytes;
};
if ($cssBytes($nestedPlan) > $cssBytes($plan)) throw new RuntimeException('An import directory must not grow the stylesheet payload for shared chrome.');
echo "Shared header self-link utility state: passed\n";
