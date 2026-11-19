<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TemplateSlugs;

/*
 * Only the home nav carries start-page classes (#6723). The home route binds
 * its own header variant part; the header never stays in post content. The
 * second case has an identical skip link before the header and route-current
 * nav state, so the shared header comes from landmark clustering, not from
 * the route edge.
 */
$routes = array('index', 'about', 'contact', 'events');
foreach (array('plain' => false, 'skip link and current nav' => true) as $case => $realistic) {
    $files = array(
        'website/style.css' => '.navbar-dark { color: white; background: black; } .navbar-light { color: black; background: white; } .start-page-nav { padding-top: 24px; } .active { font-weight: 700; }',
    );
    foreach ($routes as $route) {
        $links = '';
        foreach ($routes as $link) {
            $current = $realistic && $link === $route ? ' class="active" aria-current="page"' : '';
            $links .= '<a href="' . ('index' === $link ? '/' : '/' . $link . '/') . '"' . $current . '>' . ('index' === $link ? 'Medeon' : ucfirst($link)) . '</a>';
        }
        $classes = 'index' === $route ? 'navbar start-page-nav navbar-dark' : 'navbar navbar-light';
        $files['index' === $route ? 'website/index.html' : 'website/' . $route . '/index.html'] = '<!doctype html><html><head><title>' . $route . '</title><link rel="stylesheet" href="/style.css"></head><body>'
            . ($realistic ? '<a class="skip" href="#main">Skip to content</a>' : '')
            . '<header><nav class="' . $classes . '" aria-label="Main menu">' . $links . '</nav></header>'
            . '<main id="main"><h1>Welcome</h1><p>Route content.</p></main><footer><p>Shared footer</p></footer></body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    $templates = ThemeTemplates::rendered($plan);
    $parts = array_column($plan['template_parts'], null, 'slug');
    $headerRefs = static function (string $markup) use ($parts): array {
        preg_match_all('/<!-- wp:template-part (\{.*?\}) \/-->/', $markup, $matches);
        return array_values(array_filter(array_map(static fn(string $json): string => (string) json_decode($json, true)['slug'], $matches[1]), static fn(string $slug): bool => 'header' === ($parts[$slug]['area'] ?? null)));
    };
    $frontHeaders = $headerRefs($templates['front-page']);
    if (1 !== count($frontHeaders) || !str_starts_with($frontHeaders[0], 'header-')) throw new RuntimeException("{$case}: front-page must reference its own header variant part.");
    foreach ($plan['pages'] as $page) {
        if (str_contains($page['canonical_block_markup'], '"tagName":"header"')) throw new RuntimeException("{$case}: {$page['source_path']} retains its header landmark in post content.");
        if ('website/index.html' === $page['source_path']) continue;
        if (array('header') !== $headerRefs($templates[TemplateSlugs::forPage($page)] ?? $templates['page'])) throw new RuntimeException("{$case}: {$page['source_path']} must reference the shared header part.");
    }
    if (!str_contains($parts[$frontHeaders[0]]['canonical_block_markup'], 'start-page-nav') || !str_contains($parts['header']['canonical_block_markup'], 'navbar-light')) {
        throw new RuntimeException("{$case}: header parts must retain their distinct authored classes.");
    }
}
echo "route-unique-header-part: passed\n";
