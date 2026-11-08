<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;

$html = '<button type="button" id="open" aria-controls="data-blocks-engine-capture-dialog-0" data-blocks-engine-capture-dialog-trigger="data-blocks-engine-capture-dialog-0">Share your reflection</button>'
    . '<dialog id="share" aria-labelledby="share-title" data-blocks-engine-capture-dialog-panel="data-blocks-engine-capture-dialog-0" data-blocks-engine-capture-dialog-panel-state="{&quot;opened&quot;:{&quot;html&quot;:&quot;&lt;h2 id=\\&quot;share-title\\&quot;&gt;Share your reflection&lt;/h2&gt;&lt;p&gt;Write in your own voice.&lt;/p&gt;&quot;}}"><h2 id="share-title">Share your reflection</h2><p>Write in your own voice.</p><button type="button" data-blocks-engine-capture-dialog-close="data-blocks-engine-capture-dialog-0">Close</button></dialog>';
$result = (new ArtifactCompiler())->compile(array('files' => array('index.html' => $html)))->toArray();
$json = json_encode($result, JSON_UNESCAPED_SLASHES) ?: '';
if (str_contains($json, 'html_unsupported_element') || str_contains($result['serialized_blocks'] ?? '', 'wp:html')) {
    throw new RuntimeException('Captured native dialog content must not fall back to unsupported HTML: ' . $json);
}
$markup = (string) ($result['serialized_blocks'] ?? '');
if (!str_contains($markup, 'Share your reflection') || !str_contains($markup, 'Write in your own voice.') || !str_contains($markup, '"blocksEngineDialog"')) {
    throw new RuntimeException('Captured native dialog content must project into editable core Group dialog blocks: ' . $markup);
}
// Both document and fragment inputs must adopt without an interaction report.
// In particular, fragment parsing must not nest a sibling dialog in its button.
foreach (array($html, '<html><body><main><section>' . $html . '</section></main></body></html>') as $source) {
    $projection = (new CapturedDialogProjector())->project(array(array('path' => 'index.html', 'content' => $source)));
    if (1 !== $projection['projected_count'] || 1 !== substr_count($projection['files'][0]['content'], '<dialog')) {
        throw new RuntimeException('A marked native panel with its trigger must be adopted exactly once.');
    }
}

$formHtml = str_replace('<p>Write in your own voice.</p>', '<form action="/share" method="post"><label>Your reflection<input name="reflection"></label><button type="submit">Send</button></form>', $html);
$formResult = (new ArtifactCompiler())->compile(array('files' => array('index.html' => $formHtml)))->toArray();
$formMarkup = (string) ($formResult['serialized_blocks'] ?? '');
if (!str_contains($formMarkup, '"blocksEngineDialog"') || !str_contains($formMarkup, 'Your reflection')
    || str_contains(json_encode($formResult) ?: '', 'html_unsupported_element')) {
    throw new RuntimeException('A captured native dialog containing a form must remain editable.');
}

// This fallback must not broaden report-free adoption to unrelated panels.
foreach (array(
    str_replace(array('<dialog ', '</dialog>'), array('<div ', '</div>'), $html),
    str_replace('data-blocks-engine-capture-dialog-trigger="data-blocks-engine-capture-dialog-0"', 'data-blocks-engine-capture-dialog-trigger="unmatched"', $html),
    str_replace('data-blocks-engine-capture-dialog-panel="data-blocks-engine-capture-dialog-0"', '', $html),
) as $source) {
    $projection = (new CapturedDialogProjector())->project(array(array('path' => 'index.html', 'content' => $source)));
    if (0 !== $projection['projected_count'] || $source !== $projection['files'][0]['content']) {
        throw new RuntimeException('Report-free fallback must require a native marked panel and matching trigger.');
    }
}
echo "captured-native-dialog-content-panel: ok\n";
