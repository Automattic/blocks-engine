<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="sample" style="outline-color:#aabbcc">Hello</p></main></body></html>',
    'site.css' => '.sample:not([style*="#aabbcc"]){outline-color:#aabbcc;stroke:#11223344;fill:#123456;--literal:#aabbcc;content:"#aabbcc";background-image:url(image-aabbcc.png)}',
)))->toArray();
$css = '';
foreach ($result['source_reports']['wordpress_site_plan']['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null)) $css .= $asset['content'] . "\n";
}
foreach (array('outline-color:#abc', 'stroke:#1234', 'fill:#123456', '[style*="#aabbcc"]', '--literal:#aabbcc', '"#aabbcc"', 'image-aabbcc.png') as $expected) {
    if (!str_contains($css, $expected)) throw new RuntimeException('Literal color delivery must preserve value and guard semantics: ' . $expected . "\n" . $css);
}
echo "Literal color delivery retains guards and non-color token streams\n";
