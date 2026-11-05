<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<button type="button" id="open" aria-controls="data-blocks-engine-capture-dialog-0" data-blocks-engine-capture-dialog-trigger="data-blocks-engine-capture-dialog-0">Share your reflection</button>'
    . '<dialog id="share" aria-labelledby="share-title" data-blocks-engine-capture-dialog-panel="data-blocks-engine-capture-dialog-0" data-blocks-engine-capture-dialog-panel-state="{&quot;opened&quot;:{&quot;html&quot;:&quot;&lt;h2 id=\\&quot;share-title\\&quot;&gt;Share your reflection&lt;/h2&gt;&lt;p&gt;Write in your own voice.&lt;/p&gt;&quot;}}"><h2 id="share-title">Share your reflection</h2><p>Write in your own voice.</p><button type="button" data-blocks-engine-capture-dialog-close="data-blocks-engine-capture-dialog-0">Close</button></dialog>';
$result = (new ArtifactCompiler())->compile(array('files' => array('index.html' => $html)))->toArray();
$json = json_encode($result, JSON_UNESCAPED_SLASHES) ?: '';
if (str_contains($json, 'html_unsupported_element') || str_contains($result['serialized_blocks'] ?? '', 'wp:html')) {
    throw new RuntimeException('Captured native dialog content must not fall back to unsupported HTML: ' . $json);
}
$markup = (string) ($result['serialized_blocks'] ?? '');
if (!str_contains($markup, 'Share your reflection') || !str_contains($markup, 'Write in your own voice.') || !str_contains($markup, '/captured-dialog')) {
    throw new RuntimeException('Captured native dialog content must project into editable captured-dialog blocks: ' . $markup);
}
echo "captured-native-dialog-content-panel: ok\n";
