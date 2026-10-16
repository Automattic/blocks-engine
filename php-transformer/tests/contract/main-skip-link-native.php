<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Every route opens with the author's skip link to its <main>. A WordPress
// block template renders its own skip link to the template's first <main>,
// reusing that element's id, so the authored link would be a second one.
// It is not compiled, the <main> keeps its id, and the header that follows
// the skip link becomes the routes' shared part. Fragment links that do not
// skip to that <main> stay where the author put them.
$routes = array('index' => 'Home', 'about' => 'About', 'visit' => 'Visit');
$page = static function (string $current) use ($routes): string {
    $menu = '';
    foreach ($routes as $slug => $label) $menu .= '<li><a href="' . $slug . '.html">' . $label . '</a></li>';
    return '<!doctype html><html><head><title>' . $routes[$current] . '</title><style>.skip-link{position:absolute;top:-40px}.skip-link:focus{top:0}.primary a{color:#123456}</style></head><body>'
        . '<a class="skip-link" href="#main">Skip to content</a>'
        . '<header><a class="brand" href="index.html">Harbor</a><nav class="primary"><ul>' . $menu . '</ul></nav></header>'
        . '<main id="main"><h1>' . $routes[$current] . '</h1><p>' . $routes[$current] . ' body copy.</p><p><a href="#hours">Hours</a></p><h2 id="hours">Hours</h2><p><a href="#main">Back to top</a></p></main></body></html>';
};
$files = array();
foreach (array_keys($routes) as $slug) $files['website/' . $slug . '.html'] = $page($slug);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];

$headers = array_values(array_filter($plan['template_parts'] ?? array(), static fn (array $part): bool => 'header' === ($part['area'] ?? '')));
$assert(1 === count($headers), 'The header after the skip link is one shared part: ' . json_encode(array_column($plan['template_parts'] ?? array(), 'slug')));
$compiled = (string) ($headers[0]['canonical_block_markup'] ?? $headers[0]['block_markup'] ?? '');
foreach ($plan['pages'] as $document) {
    $markup = (string) $document['canonical_block_markup'];
    $compiled .= $markup;
    $assert(1 === preg_match('/<main\b[^>]*\bid="main"/', $markup), 'The skip target keeps its id in ' . $document['source_path']);
    $assert(str_contains($markup, 'href="#hours"') && str_contains($markup, 'Back to top</a>'), 'Fragment links inside main stay in ' . $document['source_path']);
}
$assert(! str_contains($compiled, 'Skip to content'), 'The authored skip link is left to the block template.');

// A single-page site's logo links to the top of <main> from inside its
// header: that is the brand, and it stays.
$brand = '<!doctype html><html><head><title>Solo</title></head><body><header><a class="brand" href="#top">Ledger</a><nav><a href="#services">Services</a></nav></header><main id="top"><h1>Ledger</h1><h2 id="services">Services</h2><p>Books balanced.</p></main></body></html>';
$single = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array('website/index.html' => $brand)))->toWordPressSitePlanView()['wordpress_site_plan'];
$compiled = (string) $single['pages'][0]['canonical_block_markup'] . implode('', array_column($single['template_parts'] ?? array(), 'canonical_block_markup'));
$assert(str_contains($compiled, 'Ledger</a>'), 'A header logo linking to the top of main stays.');

// A link ahead of <main> to some other fragment is not a skip link.
$other = '<!doctype html><html><head><title>Solo</title></head><body><a href="#hours">Jump to hours</a><main id="main"><h1>Solo</h1><h2 id="hours">Hours</h2><p>Open daily.</p></main></body></html>';
$single = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array('website/index.html' => $other)))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(str_contains((string) $single['pages'][0]['canonical_block_markup'], 'Jump to hours</a>'), 'A fragment link to another target stays.');
