<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// The brand's media-query visibility belongs to its anchor. Promoting it
// to a dynamic site title loses both that visibility and its captured label.
$files = array();
foreach (array('index' => 'Home', 'about' => 'About') as $slug => $label) {
    $files[$slug . '.html'] = '<!doctype html><html><head><title>' . $label . '</title><style>'
        . '.brand{display:inline-block;font-size:20px}@media(min-width:992px){.brand{display:none!important}}'
        . '</style></head><body><header><a class="brand" href="index.html">Small Brand</a>'
        . '<nav><ul><li><a href="index.html">Home</a></li><li><a href="about.html">About</a></li></ul></nav>'
        . '</header><main><h1>' . $label . '</h1><p>Page content</p></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
if (1 !== count($headers)) throw new RuntimeException('Responsive branding still shares one header.');
$markup = $headers[0]['canonical_block_markup'];
if (!str_contains($markup, 'Small Brand') || str_contains($markup, 'wp:site-title ')) {
    throw new RuntimeException('A conditionally visible anchor must retain its label and source presentation instead of becoming a site title.');
}
echo "Shared responsive wordmark: passed\n";
