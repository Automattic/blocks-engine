<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Wix paints section dividers from custom properties holding ~100 KB SVG data
// URLs. The site plan's theme stylesheet must keep that declaration intact.
$svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 10'><path d='" . str_repeat('M1 1h1v1h-1z', 8000) . "'/></svg>";
$edge = '--edge:url("data:image/svg+xml,' . rawurlencode($svg) . '")';
$html = '<!doctype html><html><head><style>#a{' . $edge . ';--edge-color:#FFFFFF}#a::before{content:"";display:block;height:50px;background-image:var(--edge)}</style></head><body><section id="a"><p>A</p></section></body></html>';

$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$sheets = array_filter($compiled['source_reports']['wordpress_site_plan']['assets'] ?? array(), static fn(array $asset): bool => str_contains((string) ($asset['content'] ?? ''), '--edge-color'));
if (array() === $sheets) {
    throw new RuntimeException('site plan emitted no stylesheet for the divider rule');
}
foreach ($sheets as $sheet) {
    if (!str_contains($sheet['content'], $edge)) {
        throw new RuntimeException('site plan stylesheet dropped the large divider data URL');
    }
}

echo "Preset binding keeps large quoted declaration values passed\n";
