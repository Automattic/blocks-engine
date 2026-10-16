<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Every route's header links to the home page's donate section. The home
// page spells that link `#donate` because the section is on the same page;
// every other route spells it `index.html#donate`. The home page also keeps
// an author comment and its line breaks inside the header. The link still
// goes to one place and the header is still one header, so all routes share
// one header part. A `#top` link that no route names through another page
// still means each route's own top and stays bare.
$routes = array('index' => 'Home', 'about' => 'About', 'visit' => 'Visit');
$page = static function (string $current) use ($routes): string {
    $menu = '';
    foreach ($routes as $slug => $label) $menu .= '<li><a href="' . $slug . '.html">' . $label . '</a></li>';
    $donate = 'index' === $current ? '#donate' : 'index.html#donate';
    $svg = '<svg viewBox="0 0 32 32" width="32" height="32"><rect x="4" y="4" width="24" height="24" fill="#d9a23a"/></svg>';
    $brand = '<span class="mark" aria-hidden="true">' . ('index' === $current ? "\n          <!-- brand mark -->\n          " . $svg . "\n        " : "\n          " . $svg . "\n        ") . '</span><span>Harbor</span>';
    $body = 'index' === $current ? '<section id="donate"><h2>Donate</h2><p>Give today.</p></section>' : '';
    return '<!doctype html><html><head><title>' . $routes[$current] . '</title><style>.primary a{color:#123456}.cta{background:#b04a2a;color:#fff}</style></head><body>'
        . '<header><a class="brand" href="index.html">' . $brand . '</a><nav class="primary"><ul>' . $menu . '</ul></nav><a class="cta" href="' . $donate . '">Donate</a></header>'
        . '<main><h1>' . $routes[$current] . '</h1><p>' . $routes[$current] . ' body copy.</p>' . $body . '<p><a href="#top">Back to top</a></p></main></body></html>';
};
$files = array();
foreach (array_keys($routes) as $slug) $files['website/' . $slug . '.html'] = $page($slug);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];

$headers = array_values(array_filter($plan['template_parts'] ?? array(), static fn (array $part): bool => 'header' === ($part['area'] ?? '')));
$assert(1 === count($headers), 'One shared header part: ' . json_encode(array_column($plan['template_parts'] ?? array(), 'slug')));
$header = (string) ($headers[0]['canonical_block_markup'] ?? $headers[0]['block_markup'] ?? '');
$assert(str_contains($header, 'href="/#donate"'), 'The shared header links to the home page section: ' . $header);
foreach ($plan['pages'] as $document) {
    $markup = (string) $document['canonical_block_markup'];
    $assert(! str_contains($markup, 'Donate</a>'), 'The header renders from the shared part, not from ' . $document['source_path']);
    $assert(str_contains($markup, 'href="#top"'), 'A route-local fragment stays route-local in ' . $document['source_path']);
}
