<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// A captured fluid length arrives spelled the way the source runtime resolved
// it. Every emitted stylesheet carries it at its smallest equivalent spelling;
// strings and custom properties keep their bytes.
$fluid = 'max(0.5px, 0.0109375 * (clamp(0px, 100 * 1vw * 1, min(9999px, 1920px)) - 0px))';
$css = '.lede{font-size:' . $fluid . ';--lede-size:calc(2 * 1px)}.lede::after{content:"calc(1 * 2px)"}'
    . '@media (max-width:600px){.lede{margin-top:calc(100% - 0px)}}';
$page = static fn (string $title): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="site.css"></head><body>'
    . '<main><h1>' . $title . '</h1><p class="lede">Lede copy for ' . $title . '.</p></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('site.css' => $css, 'index.html' => $page('Home'), 'about.html' => $page('About')),
))->toWordPressSitePlanView()['wordpress_site_plan'];

$emitted = '';
foreach ($plan['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? null)) $emitted .= (string) ($asset['content'] ?? '');
}
$assert(str_contains($emitted, 'max(0.5px,0.0109375*clamp(0px,100vw,1920px))'), 'The fluid length ships at its smallest equivalent spelling.');
$assert(! str_contains($emitted, '100 * 1vw * 1') && ! str_contains($emitted, '1vw*1'), 'No emitted stylesheet repeats the runtime spelling.');
$assert(str_contains($emitted, 'calc(100%)') && ! str_contains($emitted, '100% - 0px'), 'A trailing zero-length term is dropped.');
$assert(str_contains($emitted, '--lede-size:calc(2 * 1px)'), 'A custom property keeps its token stream.');
$assert(str_contains($emitted, '"calc(1 * 2px)"'), 'A string keeps its bytes.');

echo "math-value-compaction: ok\n";
