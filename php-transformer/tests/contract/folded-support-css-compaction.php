<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A route's geometry is allocated around geometry every route shares, then
// its own pieces fold back into one route-owned resource. Formatting between
// those contributions must not become theme payload. Exercise the public
// compiler, including final asset writes.
$files = array();
$box = static fn(int $width): string => '<div class="box" style="width:' . $width . 'px;height:200px"><p>Copy ' . $width . '</p></div>';
foreach (array('index.html', 'about.html', 'contact.html') as $i => $path) {
    $files[$path] = '<!doctype html><html><head><style>.box{width:301px;padding:13px}</style></head><body><main>'
        . $box(302 + $i) . $box(900) . (0 === $i ? $box(305) : '')
        . '</main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$writes = array_column($plan['writes'], null, 'target_path');
$folded = 0;
foreach ($plan['assets'] as $asset) {
    if ('engine-support' !== ($asset['source'] ?? '') || !str_contains($asset['content'] ?? '', 'width:305px')) continue;
    ++$folded;
    $css = $asset['content'];
    if (array(array('kind' => 'page', 'source_path' => 'index.html')) !== array_map(static fn(array $scope): array => array('kind' => $scope['kind'], 'source_path' => $scope['source_path']), $asset['scopes'])) throw new RuntimeException('Folded route geometry stays on its route');
    if ($css !== CssSyntaxScanner::withoutRedundantWhitespace($css)) throw new RuntimeException('Folded support must not ship redundant whitespace');
    if ($css !== $writes[$asset['target_path']]['payload']['data']) throw new RuntimeException('Written support must equal its compact payload');
    if (!str_contains($css, 'width:302px') || str_contains($css, 'width:900px') || str_contains($css, 'width:303px')) throw new RuntimeException('Document geometry must survive compaction on its own route only');
}
if (1 !== $folded) throw new RuntimeException('Fixture must fold the route geometry into one stylesheet');
echo "Folded support CSS compaction passed\n";
