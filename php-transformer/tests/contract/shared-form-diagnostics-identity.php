<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Every route ends with the same footer and the same newsletter form, but
// each route's stylesheet has its own rules the form graph cannot evaluate
// (pseudo-elements on that page's own content). The graph records those as
// conversion diagnostics; they describe the page's stylesheet, not the form,
// so the footer and its form become one shared part with one form binding.
$routes = array(
    'index' => array('Home', '.hero-mark::before{content:"";display:block}'),
    'pricing' => array('Pricing', '.price-features li::before{content:"✓"}.price-card:hover{transform:translateY(-2px)}'),
    'faq' => array('FAQ', '.faq-icon::before{content:"+"}.faq-icon::after{content:""}.faq-trigger:hover{color:#c4522a}'),
);
$page = static function (string $slug) use ($routes): string {
    [$title, $css] = $routes[$slug];
    $menu = '';
    foreach ($routes as $route => [$label]) $menu .= '<a href="' . $route . '.html">' . $label . '</a>';
    return '<!doctype html><html><head><title>' . $title . '</title><style>'
        . '.newsletter-form{display:flex;gap:.75rem;margin-top:2rem}.newsletter-form input{padding:.75rem 1rem;border:1px solid #ccc;border-radius:999px}.btn{background:#c4522a;color:#fff;border-radius:999px;padding:.75rem 1.5rem}'
        . $css . '</style></head><body>'
        . '<header class="site-header"><nav>' . $menu . '</nav></header>'
        . '<main><h1>' . $title . '</h1><p>' . $title . ' copy.</p></main>'
        . '<footer class="site-footer"><p>Weekly dinner plans.</p><form class="newsletter-form" aria-label="Newsletter signup"><input type="email" placeholder="your@email.com" aria-label="Email address" required><button type="submit" class="btn">Subscribe</button></form><p>© Pantry</p></footer>'
        . '</body></html>';
};
$files = array();
foreach (array_keys($routes) as $slug) $files['website/' . $slug . '.html'] = $page($slug);
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray();
$plan = $compiled['source_reports']['wordpress_site_plan'];

$footers = array_values(array_filter($plan['template_parts'] ?? array(), static fn (array $part): bool => 'footer' === ($part['area'] ?? '')));
$assert(1 === count($footers), 'The footer holding the same form on every route is one shared part: ' . json_encode(array_column($plan['diagnostics'] ?? array(), 'code')));
$assert(str_contains((string) ($footers[0]['canonical_block_markup'] ?? ''), 'Weekly dinner plans.'), 'The shared footer carries the footer copy.');
foreach ($plan['pages'] as $document) {
    $assert(! str_contains((string) $document['canonical_block_markup'], 'Weekly dinner plans.'), 'The footer leaves ' . $document['source_path']);
}

$forms = array();
foreach ($plan['runtime_declarations'] as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) {
    if ('form' === ($entity['bindings'][0]['role'] ?? null)) $forms[] = (string) $entity['bindings'][0]['source_path'];
}
$assert(array('wordpress-site-plan/shared/footer#footer') === $forms, 'One form binding moves into the shared footer: ' . json_encode($forms));
