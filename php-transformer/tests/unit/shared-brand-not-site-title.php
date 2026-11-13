<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$files = array();
foreach (array('index', 'about') as $route) {
    $files[] = array('path' => 'website/' . $route . '.html', 'content' => '<html><head><title>Business Theme</title><style>.mobile-brand{display:block}@media(min-width:900px){.mobile-brand{display:none}}</style></head><body><header><a class="mobile-brand" href="index.html">Studio Brand</a><nav><ul><li><a href="index.html">Home</a></li><li><a href="about.html">About</a></li></ul></nav></header><main><h1>' . $route . '</h1><p>Page content.</p></main></body></html>');
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null));
$markup = implode('', array_column($headers, 'canonical_block_markup'));
if (empty($headers) || !str_contains($markup, 'Studio Brand') || !str_contains($markup, 'mobile-brand') || str_contains($markup, 'wp:site-title')) {
    fwrite(STDERR, "Shared branding must retain its authored label and responsive anchor, not render the document title.\n");
    exit(1);
}
echo "Shared brand label retained\n";
