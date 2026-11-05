<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Two routes are sufficient for the editor leak. Also exercise the large
// route-set compaction path used by the original 33-route import.
foreach (array(0, 31) as $extraRoutes) {
$files = array(
    'capture.css' => (string) file_get_contents(__DIR__ . '/fixtures/route-state-ancestor-projection/capture.css'),
    'parts/header.html' => '<header id="SITE_HEADER"><nav><a href="/index.html">Home</a><a href="/landing.html">Landing</a></nav></header>',
);
$routes = array('index.html' => '', 'landing.html' => 'landingPage');
for ($i = 0; $i < $extraRoutes; ++$i) $routes['route-' . $i . '.html'] = $i < 15 ? '' : 'landingPage';
foreach ($routes as $path => $state) {
    $files[$path] = '<!doctype html><html><head><link rel="stylesheet" href="/capture.css"></head><body><div data-blocks-engine-capture-device-document="desktop"><div id="masterPage" class="' . $state . '"><!--#include virtual="/parts/header.html" --><main><h1>' . $path . '</h1><p>Page content</p></main></div></div></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
// Every flattened front-end and editor arm needs both gates, independently
// of whether compaction places the route predicate before or on the subject.
$flattened = 0;
preg_match_all('/([^{}]+)\\{[^{}]*display:\\s*none[^{}]*\\}/', $css, $rules, PREG_SET_ORDER);
foreach ($rules as $rule) {
    foreach (CssStylesheetTransformer::splitSelectorList($rule[1]) ?? array() as $arm) {
        if (str_contains($arm, 'landingPage')) continue;
        ++$flattened;
        if (!str_contains($arm, ':root') || !str_contains($arm, 'be-r') || !str_contains($arm, 'desktop') || !str_contains($arm, 'tablet')) {
            throw new RuntimeException('Flattened header projections require route and complete device gates: ' . $arm);
        }
    }
}
if (0 === $flattened) throw new RuntimeException('The fixture must exercise flattened projections.');

// Render only the projected subject, as a shared part does: the discarded
// masterPage ancestor cannot accidentally protect a leaked projection.
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome || true'));
if ('' !== $chrome) {
    $pages = array_column($plan['pages'], null, 'source_path');
    foreach (array('index.html' => 'block', 'landing.html' => 'none') as $path => $expected) {
        foreach (array('desktop', 'tablet', 'mobile') as $device) {
            foreach (array(false, true) as $editor) {
                $rootClass = $pages[$path]['document_metadata']['root_attributes']['class'];
                $renderCss = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && ($editor || 'editor' !== ($asset['stylesheet_target'] ?? 'both'))), 'content'));
                $html = '<!doctype html><html class="' . $rootClass . '"><head><style>' . $renderCss . '</style></head><body><div data-be-d="' . $device . '" data-blocks-engine-capture-device-document="' . $device . '"><header ' . ($editor ? 'class="blocks-engine-editor-anchor-SITE_HEADER"' : 'id="SITE_HEADER"') . '>Header</header></div><script>document.body.dataset.display=getComputedStyle(document.querySelector("header")).display</script></body></html>';
                $base = tempnam(sys_get_temp_dir(), 'route-header-');
                $temp = $base . '.html';
                unlink($base);
                file_put_contents($temp, $html);
                try {
                    $output = (string) shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --dump-dom ' . escapeshellarg('file://' . $temp) . ' 2>/dev/null');
                    $display = 'mobile' === $device ? 'block' : $expected;
                    if (!str_contains($output, 'data-display="' . $display . '"')) throw new RuntimeException('Wrong header display for ' . $path . ' ' . $device . ' editor=' . (int) $editor);
                } finally { unlink($temp); }
            }
        }
    }
}
}
fwrite(STDOUT, "route-state ancestor projection contract passed\n");
