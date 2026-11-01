<?php
declare(strict_types=1);

require_once __DIR__ . '/../fixtures/captured-components.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CaptureReceiptRoutes;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
// The small trigger + observed dialog pattern used by captured-navigation-runtime.
$dialog = '<p>Captured notes.</p>';
$receipt = array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'websiteRoot' => 'website', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html')));
$report = array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => captured_components(array(array(
    'status' => 'captured', 'kind' => 'dialog',
    'trigger' => array('selector' => '#open', 'tag' => 'button', 'label' => 'Notes', 'ariaHaspopup' => 'dialog', 'dataBindings' => array()),
    'dialog' => array('html' => $dialog, 'htmlBytes' => strlen($dialog), 'htmlTruncated' => false),
))))));
$expected = null;
foreach (array('capture-root' => 'website/index.html', 'website-root' => 'index.html') as $layout => $path) {
    $files = array(
        array('path' => $path, 'content' => '<html><body><main><h1>Example</h1><button id="open" aria-haspopup="dialog">Notes</button></main></body></html>'),
        array('path' => 'capture-receipt.json', 'content' => json_encode($receipt)),
        array('path' => 'interaction-states.json', 'content' => json_encode($report)),
    );
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => $path, 'files' => $files))->toArray();
    $markup = $result['serialized_blocks'] ?? '';
    $codes = array_column($result['diagnostics'] ?? array(), 'code');
    echo $layout . ': source_unmatched=' . (in_array('captured_interaction_source_unmatched', $codes, true) ? 'yes' : 'no') . ', native_dialog=' . (str_contains($markup, '<!-- wp:custom/captured-dialog') ? 'yes' : 'no') . "\n";
    $assert(!in_array('captured_interaction_source_unmatched', $codes, true), $layout . ' resolves its receipt route');
    $assert(str_contains($markup, '<!-- wp:custom/captured-dialog') && str_contains($markup, 'Captured notes.'), $layout . ' projects the observed native dialog');
    // Generated dialog IDs include the artifact path; compare everything else.
    $normalized = preg_replace('/blocks-engine-dialog-[a-f0-9]{16}/', 'dialog-id', $markup);
    $expected ??= $normalized;
    $assert($expected === $normalized, $layout . ' produces identical native blocks apart from path-derived IDs');
}

$files = array(array('path' => 'index.html', 'content' => '<html></html>'), array('path' => 'website/index.html', 'content' => '<html></html>'));
foreach (array('' , '.', '..', '/website', 'C:\\website', '../website', 'a/../website') as $root) {
    $assert(array() === CaptureReceiptRoutes::resolve($files, array_replace($receipt, array('websiteRoot' => $root))), 'unsafe root is rejected: ' . $root);
}
$assert(array('https://example.test' => 'website/index.html') === CaptureReceiptRoutes::resolve($files, $receipt), 'exact path wins when both exist');
foreach (array(null, 'public') as $root) {
    $row = $receipt;
    if (null === $root) unset($row['websiteRoot']);
    else { $row['websiteRoot'] = $root; $row['routes'][0]['path'] = 'public/index.html'; }
    $row['routes'][0]['url'] = ' https://example.test/ ';
    $assert(array('https://example.test' => 'index.html') === CaptureReceiptRoutes::resolve(array($files[0]), $row), 'default/custom prefix and URL normalization');
}
$assert(array() === CaptureReceiptRoutes::resolve(array(), $receipt), 'missing HTML is not mapped');
echo "capture receipt routes passed\n";
