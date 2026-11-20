<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #7146: a post whose own template has a wider content slot than its layout's
// (a photo ahead of the details) still renders its own hero image.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$files = array('index.html' => '<!doctype html><html><head><title>Home</title></head><body><h1>Home</h1></body></html>');
foreach (array('alpha' => 'plain', 'beta' => 'sidebar', 'gamma' => 'photo') as $slug => $shape) {
    $title = ucfirst($slug) . ' event';
    $date = '2026-10-0' . strlen($slug);
    $files['media/' . $slug . '.png'] = $png . $slug;
    $files['media/' . $slug . '-photo.png'] = $png . $slug . '-photo';
    $details = '<h2>' . $title . '</h2><p><strong>From:</strong> ' . $date . '</p><p>' . $title . ' details.</p>';
    $body = match ($shape) {
        'plain' => '<div class="col-12">' . $details . '</div>',
        'photo' => '<div class="col-12"><img class="event-img" src="/media/' . $slug . '-photo.png" alt="">' . $details . '</div>',
        'sidebar' => '<div class="col-md-8 col-12">' . $details . '</div><div class="col-md-4 col-12"><button>Register</button><form action="/register/"><input name="email"><button>Send</button></form></div>',
    };
    $files['events/' . $slug . '/index.html'] = '<!doctype html><html><head><title>' . $title . '</title><script type="application/ld+json">{"@context":"https://schema.org","@type":"BlogPosting","headline":"' . $title . '","datePublished":"' . $date . '"}</script></head><body><header class="site-header"><p>Shared header</p></header><main><section id="header-container" class="hero" style="background-image:url(/media/' . $slug . '.png)"><div class="container"><h1>' . $title . '</h1></div></section><section class="container-y"><div class="row">' . $body . '<ul class="more-events-list"><li><a href="/events/alpha/">Alpha event</a></li><li><a href="/events/beta/">Beta event</a></li></ul></div></section></main><footer class="site-footer"><p>Footer text</p></footer></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$stylesheets = json_encode($plan, JSON_UNESCAPED_SLASHES);
$failures = array();
foreach ($plan['pages'] as $page) {
    if ('post' !== ($page['post_type'] ?? null)) continue;
    $slug = basename(dirname($page['source_path']));
    // The post renders its content inside its template; the hero is in one.
    $rendered = $page['canonical_block_markup'] . ($templates[$page['route_template'] ?? 'single'] ?? '');
    preg_match('/<section id="header-container" class="[^"]*\b(be-inline-geometry-[0-9a-f]+)/', $rendered, $hero);
    if (!str_contains($stylesheets, '.' . ($hero[1] ?? '-') . '{background-image:url(media/' . $slug . '.png)')) $failures[] = $slug . ' renders its own hero image';
}
if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "post chrome own template values: passed\n";
