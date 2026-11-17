<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Different document geometry is allocated to separate owner sets, then folded
// into one resource. Formatting between those contributions must not become
// theme payload. Exercise the public compiler, including final asset writes.
$files = array();
foreach (array('index.html', 'about.html', 'contact.html') as $i => $path) {
    $files[$path] = '<!doctype html><html><head><style>.box{width:301px;padding:13px}</style></head><body><main>'
        . '<div class="box" style="width:' . (302 + $i) . 'px;height:200px"><p>Copy ' . $i . '</p></div>'
        . '</main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$writes = array_column($plan['writes'], null, 'target_path');
$folded = 0;
foreach ($plan['assets'] as $asset) {
    if ('engine-support' !== ($asset['source'] ?? '') || !str_contains($asset['content'] ?? '', 'be-inline-geometry-')) continue;
    ++$folded;
    $css = $asset['content'];
    if ($css !== CssSyntaxScanner::withoutRedundantWhitespace($css)) throw new RuntimeException('Folded support must not ship redundant whitespace');
    if ($css !== $writes[$asset['target_path']]['payload']['data']) throw new RuntimeException('Written support must equal its compact payload');
    foreach (array(302, 303, 304) as $width) {
        if (!str_contains($css, 'width:' . $width . 'px')) throw new RuntimeException('Document geometry must survive compaction');
    }
}
if (1 !== $folded) throw new RuntimeException('Fixture must fold distinct document support into one stylesheet');
echo "Folded support CSS compaction passed\n";
