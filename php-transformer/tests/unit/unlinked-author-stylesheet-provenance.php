<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\BootstrapStyles;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$document = static fn(string $prefix): string => '<!doctype html><html><head><link rel="stylesheet" href="' . $prefix . 'assets/css/linked.css"><style>main p{font-size:20px}</style></head><body><main><p style="height:3000px">Tall page</p></main></body></html>';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $document(''),
        'about/index.html' => $document('../'),
        'assets/css/linked.css' => '@import "imported.css"; main{color:blue}',
        'assets/css/imported.css' => '@import url("linked.css"); p{line-height:1.5}',
        'assets/css/orphan.css' => 'html, body { margin: 0; overflow: hidden; }',
    ),
))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$bootstrap = '';
foreach ($plan['writes'] as $write) {
    if ('functions.php' === ($write['target_path'] ?? null)) $bootstrap = BootstrapStyles::expand((string) $write['payload']['data']);
}
foreach (array('is_front_page()', "'about' === \$blocks_engine_page_uri") as $route) {
    $inRoute = false;
    $linked = false;
    foreach (explode("\n", $bootstrap) as $line) {
        if (str_starts_with($line, '    if ( ') && str_ends_with($line, ' ) {') && str_contains($line, $route)) $inRoute = true;
        if ($inRoute && '    }' === $line) $inRoute = false;
        if ($inRoute && str_contains($line, 'wp_enqueue_style(') && str_contains($line, '/linked.css')) $linked = true;
    }
    $assert($linked, $route . ': linked stylesheet remains in the route sequence');
}
$assert(!str_contains($bootstrap, '/orphan.css'), 'Unlinked author CSS must not be enqueued');
$renderedCss = '';
$imported = false;
$orphan = null;
foreach ($plan['assets'] as $asset) {
    if (str_ends_with($asset['target_path'], '/orphan.css')) $orphan = $asset;
    if ('css' !== ($asset['kind'] ?? null)) continue;
    $renderedCss .= $asset['content'] ?? '';
    if (str_ends_with($asset['target_path'], '/imported.css')) $imported = true;
}
$assert(!str_contains($renderedCss, 'overflow: hidden'), 'Orphan rules must not reach rendered CSS');
$assert($imported, 'A transitive import, including a cycle, remains a stylesheet');
$assert(is_array($orphan) && 'asset' === $orphan['kind'] && 'source-provenance' === $orphan['role'] && 'evidence' === $orphan['intent'], 'Orphan file is retained only as source provenance');
if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Unlinked author stylesheet provenance passed\n");
