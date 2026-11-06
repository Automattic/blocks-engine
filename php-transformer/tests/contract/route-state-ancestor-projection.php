<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Two routes are sufficient for the editor leak. Also exercise the large
// route-set compaction path used by the original 33-route import. Isolate
// desktop-only prefixes, device copies, and distinct inline landmark groups.
// Neither device copies nor distinct groups alone reproduce #4671: two
// members of each inline header group plus device copies do. The last case
// mirrors its eight landing routes out of 35.
foreach (array(
    array(0, false, false, false),
    array(31, false, false, false),
    array(0, false, true, false),
    array(0, true, true, false),
    array(0, true, true, true),
    array(2, false, true, true),
    array(2, true, true, true),
    array(33, true, true, true),
) as [$extraRoutes, $deviceCopies, $desktopOnly, $inlineGroups]) {
$files = array(
    'capture.css' => str_replace(
        '[data-blocks-engine-capture-device-document="desktop"]',
        $desktopOnly ? '[data-blocks-engine-capture-device-document="desktop"]' : '[data-blocks-engine-capture-device-document="desktop"],[data-blocks-engine-capture-device-document="tablet"]',
        (string) file_get_contents(__DIR__ . '/fixtures/route-state-ancestor-projection/capture.css')
    ),
    'parts/header.html' => '<header id="SITE_HEADER"><nav><a href="/index.html">Home</a><a href="/landing.html">Landing</a></nav></header>',
);
$routes = array('index.html' => '', 'landing.html' => 'landingPage');
for ($i = 0; $i < $extraRoutes; ++$i) $routes['route-' . $i . '.html'] = $inlineGroups ? ($i < (2 === $extraRoutes ? 1 : 7) ? 'landingPage' : '') : ($i < 15 ? '' : 'landingPage');
foreach ($routes as $path => $state) {
    $body = '';
    foreach ($deviceCopies ? array('desktop', 'tablet', 'mobile') : array('desktop') as $device) {
        $header = $inlineGroups ? str_replace('<header ', '<header class="' . ('' === $state ? 'full' : 'minimal') . '" ', $files['parts/header.html']) : '<!--#include virtual="/parts/header.html" -->';
        $body .= '<div ' . ($deviceCopies ? 'class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-document-scope="" ' : '') . 'data-blocks-engine-capture-device-document="' . $device . '"><div id="masterPage" class="' . $state . '">' . $header . '<main><h1>' . $path . '</h1><p>Page content</p></main></div></div>';
    }
    $files[$path] = '<!doctype html><html><head><link rel="stylesheet" href="/capture.css"></head><body>' . $body . '</body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => $inlineGroups ? 'landing.html' : 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$cssAssets = array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null));
// Delivery scopes are real route gates too (notably for editor assets).
// Check their membership before representing them as selector gates below.
$scopedContent = static function (array $asset) use ($plan, $routes): string {
    if (array_filter($asset['scopes'] ?? array(), static fn(array $scope): bool => 'global' === $scope['kind'])) return $asset['content'];
    $pages = array_column($plan['pages'], null, 'source_path');
    $classes = array();
    foreach ($asset['scopes'] ?? array() as $scope) {
        $source = $scope['source_path'] ?? '';
        if (str_contains($asset['content'], 'display: none') && '' === ($routes[$source] ?? '')) throw new RuntimeException('Hiding asset delivered to non-landing route: ' . $source);
        $root = $pages[$source]['document_metadata']['root_attributes']['class'] ?? '';
        foreach (explode(' ', $root) as $class) if (str_starts_with($class, 'be-r-')) $classes[] = '.' . $class;
    }
    if (!$classes) return $asset['content'];
    return (new CssStylesheetTransformer())->transform($asset['content'], static fn(string $selector): string => ':where(:root:is(' . implode(',', $classes) . ')) ' . $selector);
};
$css = implode("\n", array_map($scopedContent, $cssAssets));
// Every flattened front-end and editor arm needs both gates, independently
// of whether compaction places the route predicate before or on the subject.
$flattened = 0;
preg_match_all('/([^{}]+)\\{[^{}]*display:\\s*none[^{}]*\\}/', $css, $rules, PREG_SET_ORDER);
foreach ($rules as $rule) {
    foreach (CssStylesheetTransformer::splitSelectorList($rule[1]) ?? array() as $arm) {
        if (str_contains($arm, 'landingPage')) continue;
        ++$flattened;
        if (!str_contains($arm, ':root') || !str_contains($arm, 'be-r') || !str_contains($arm, 'desktop') || ($desktopOnly ? str_contains($arm, 'tablet') || str_contains($arm, 'mobile') : !str_contains($arm, 'tablet'))) {
            throw new RuntimeException('Flattened header projections require route and complete device gates (extra routes=' . $extraRoutes . ', device copies=' . (int) $deviceCopies . ', desktop only=' . (int) $desktopOnly . ', inline groups=' . (int) $inlineGroups . '): ' . $arm);
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
                $renderCss = implode("\n", array_column(array_filter($cssAssets, static fn(array $asset): bool => ($editor || 'editor' !== ($asset['stylesheet_target'] ?? 'both')) && array_filter($asset['scopes'] ?? array(), static fn(array $scope): bool => 'global' === $scope['kind'] || $path === ($scope['source_path'] ?? null))), 'content'));
                $html = '<!doctype html><html class="' . $rootClass . '"><head><style>' . $renderCss . '</style></head><body><div data-be-d="' . $device . '" data-blocks-engine-capture-device-document="' . $device . '"><header ' . ($editor ? 'class="blocks-engine-editor-anchor-SITE_HEADER"' : 'id="SITE_HEADER"') . '>Header</header></div><script>document.body.dataset.display=getComputedStyle(document.querySelector("header")).display</script></body></html>';
                $base = tempnam(sys_get_temp_dir(), 'route-header-');
                $temp = $base . '.html';
                unlink($base);
                file_put_contents($temp, $html);
                try {
                    $output = (string) shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --dump-dom ' . escapeshellarg('file://' . $temp) . ' 2>/dev/null');
                    $display = 'mobile' === $device || ($desktopOnly && 'tablet' === $device) ? 'block' : $expected;
                    if (!str_contains($output, 'data-display="' . $display . '"')) throw new RuntimeException('Wrong header display for ' . $path . ' ' . $device . ' editor=' . (int) $editor);
                } finally { unlink($temp); }
            }
        }
    }
}
}
fwrite(STDOUT, "route-state ancestor projection contract passed\n");
