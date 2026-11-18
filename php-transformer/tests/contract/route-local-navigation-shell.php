<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$files = array();
foreach (array('index.html', 'booking.html', 'services.html', 'about.html') as $route) {
    $links = '';
    foreach (array('index.html' => 'Home', 'about.html' => 'About', 'contact.html' => 'Contact') as $href => $label) {
        $links .= '<a class="' . ($href === $route && 'index.html' !== $route ? 'text-primary' : 'text-foreground/70') . '" href="' . $href . '">' . $label . '</a>';
    }
    $files[$route] = '<html><head><style>.masthead{background:#eee}.row{display:flex;justify-content:space-between}.logo,.cta{display:inline-flex;padding:10px}.text-primary{color:#a00}.text-foreground\\/70{color:#333}</style></head><body>'
        . '<div><header class="masthead"><div class="row"><a class="logo" href="index.html">Brand</a><nav style="display:flex;gap:20px">' . $links . '</nav><a class="cta" href="booking.html">Book now</a></div></header><main><h1>' . $route . '</h1><p>Route body</p></main></div></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']);
foreach ($plan['pages'] as $page) {
    if (str_contains($page['canonical_block_markup'], '<header')) {
        throw new RuntimeException('Navigation-state variants must not leave the masthead in page content: ' . $page['source_path']);
    }
}
if (count($headers) !== 2) throw new RuntimeException('The current-item variant must have its own header part');
$variant = false;
foreach ($headers as $part) {
    $variant = $variant || str_contains($part['canonical_block_markup'], 'text-primary');
    if (!str_contains($part['canonical_block_markup'], 'text-foreground/70')) {
        throw new RuntimeException('Moving a header must preserve its active and inactive menu classes');
    }
}
if (!$variant) throw new RuntimeException('The route-local current-item class must survive extraction');
echo "route-local-navigation-shell: ok\n";
