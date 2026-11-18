<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TemplateSlugs;

$files = array(
    'website/style.css' => '.navbar-dark { color: white; background: black; } .navbar-light { color: black; background: white; } .start-page-nav { padding-top: 24px; }',
);
foreach (array('index', 'about', 'contact', 'events') as $route) {
    $path = 'index' === $route ? 'website/index.html' : 'website/' . $route . '/index.html';
    $classes = 'index' === $route ? 'navbar start-page-nav navbar-dark' : 'navbar navbar-light';
    $files[$path] = '<!doctype html><html><head><title>' . $route . '</title><link rel="stylesheet" href="/style.css"></head><body>'
        . '<header><nav class="' . $classes . '" aria-label="Main menu"><a href="/">Medeon</a><a href="/about/">About</a><a href="/contact/">Contact</a><a href="/events/">Events</a></nav></header>'
        . '<main><h1>Welcome</h1><p>Route content.</p></main><footer><p>Shared footer</p></footer></body></html>';
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$templates = ThemeTemplates::rendered($plan);
$parts = array_column($plan['template_parts'], null, 'slug');
$headerRefs = static function (string $markup) use ($parts): array {
    preg_match_all('/<!-- wp:template-part (\{.*?\}) \/-->/', $markup, $matches);
    return array_values(array_filter(array_map(static fn(string $json): array => json_decode($json, true), $matches[1]), static fn(array $ref): bool => 'header' === ($parts[$ref['slug']]['area'] ?? null)));
};
$frontHeaders = $headerRefs($templates['front-page']);
if (1 !== count($frontHeaders)) throw new RuntimeException('front-page must reference a header template part.');
if (!str_starts_with($frontHeaders[0]['slug'], 'header-')) throw new RuntimeException('The unique home header must use a variant slug.');
foreach ($plan['pages'] as $page) {
    if (str_contains($page['canonical_block_markup'], '<header') || str_contains($page['canonical_block_markup'], '"tagName":"header"')) {
        throw new RuntimeException($page['source_path'] . ' retains its header landmark in post content.');
    }
    if ('website/index.html' === $page['source_path']) continue;
    $refs = $headerRefs($templates[TemplateSlugs::forPage($page)] ?? $templates['page']);
    if (1 !== count($refs) || 'header' !== $refs[0]['slug']) throw new RuntimeException($page['source_path'] . ' must reference the shared header part.');
}
if (!str_contains($parts[$frontHeaders[0]['slug']]['canonical_block_markup'], 'start-page-nav') || !str_contains($parts['header']['canonical_block_markup'], 'navbar-light')) {
    throw new RuntimeException('Header parts must retain their distinct authored classes.');
}
echo "route-unique-header-part: passed\n";
