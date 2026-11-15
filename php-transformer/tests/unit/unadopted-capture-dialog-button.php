<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

$runtime = trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-disclosure-runtime.js'));
if (CaptureEvidence::DISCLOSURE_RUNTIME_SHA256 !== hash('sha256', $runtime)) exit(1);
$key = 'data-blocks-engine-capture-dialog-2';
$cases = array(
    'absent panel' => array('', ''),
    'refused panel' => array(
        ' aria-controls="' . $key . '" aria-expanded="false" aria-haspopup="dialog" data-blocks-engine-capture-dialog-ancestor-unverified="ancestor-limit" data-blocks-engine-capture-dialog-panel-state="{}"',
        '<section><div class="slide active data-blocks-engine-capture-dropdown" id="' . $key . '" data-blocks-engine-capture-dialog-panel="' . $key . '"><p>A review</p><a href="/p">Product</a></div></section>',
    ),
);
$failures = 0;
foreach ($cases as $name => [$attributes, $panel]) {
    $html = '<!doctype html><html><head><script data-blocks-engine-capture-disclosure-runtime>' . $runtime . '</script></head><body><main><form><input type="hidden"><button type="submit" data-blocks-engine-capture-dialog-trigger="' . $key . '"' . $attributes . '><span>Add to cart</span></button></form>' . $panel . '</main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $html))))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $checks = array(
        'ordinary authored button retains its label' => str_contains($markup, '<!-- wp:custom/authored-button ') && str_contains($markup, 'Add to cart'),
        'no HTML block' => !str_contains($markup, '<!-- wp:html'),
        'no fallback' => 0 === ($result['metrics']['fallback_count'] ?? -1),
        'orphan capture binding is removed' => !str_contains($markup, CaptureEvidence::DIALOG_TRIGGER) && !str_contains($markup, CaptureEvidence::DIALOG_PANEL_STATE) && !str_contains($markup, CaptureEvidence::DIALOG_ANCESTOR_UNVERIFIED),
        'orphan ARIA binding is removed' => !str_contains($markup, 'aria-controls') && !str_contains($markup, 'aria-expanded'),
    );
    foreach ($checks as $message => $ok) {
        if ($ok) continue;
        ++$failures;
        fwrite(STDERR, "FAIL: {$name}: {$message}\n{$markup}\n");
    }
}
if ($failures) exit(1);
echo "unadopted-capture-dialog-button: ok\n";
