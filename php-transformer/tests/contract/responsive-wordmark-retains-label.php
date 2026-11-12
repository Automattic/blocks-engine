<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// The mobile brand is not the document title. In particular its visibility
// selector lives on the link, not the paragraph hosting that link.
$files = array();
foreach (array('index', 'about') as $route) {
    $files[$route . '.html'] = '<!doctype html><html><head><title>Document title</title><style>.mobile-brand{display:block}@media(min-width:900px){.mobile-brand{display:none}}</style></head><body><header><a class="mobile-brand" href="index.html">Short Brand</a><nav><ul><li><a href="index.html">Home</a></li><li><a href="about.html">About</a></li></ul></nav></header><main><h1>' . $route . '</h1></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']);
$markup = implode('', array_column($headers, 'canonical_block_markup'));
if (!str_contains($markup, 'Short Brand') || !str_contains($markup, 'mobile-brand') || str_contains($markup, 'wp:site-title')) {
    throw new RuntimeException('Device-specific branding must retain its authored text and visibility host: ' . $markup);
}
echo "responsive-wordmark-retains-label: ok\n";
