<?php
declare(strict_types=1);

require_once __DIR__ . '/captured-components.php';

return static function (bool $observed = false): array {
    $snapshot = htmlspecialchars(json_encode(array('resting' => array('class' => 'drawer', 'style' => null, 'hidden' => true), 'opened' => array('class' => 'drawer', 'style' => null))), ENT_QUOTES);
    $trigger = '<div class="bar"><button class="toggle" type="button" aria-label="Toggle menu" aria-haspopup="menu" aria-expanded="false" aria-controls="drawer" data-blocks-engine-capture-dialog-trigger="drawer">Menu</button></div>';
    $panel = '<nav id="drawer" class="drawer data-blocks-engine-capture-dropdown" hidden data-blocks-engine-capture-dialog-panel="drawer" ' . ($observed ? 'data-blocks-engine-capture-observed-placement="true"' : 'data-blocks-engine-capture-existing-panel="' . $snapshot . '"') . '><a href="/one">One</a><a href="/two">Two</a><a href="/three">Three</a><a href="/four">Four</a><a href="/five">Five</a></nav>';
    $script = file_get_contents(__DIR__ . '/capture-disclosure-runtime.js');
    $css = 'body{margin:0}.wide{display:none}.compact{position:relative;transform:translateZ(0);width:100%;min-height:844px}.bar{position:fixed;top:0;left:0;width:100%;height:50px;z-index:1010}.toggle{position:absolute;right:10px;top:5px;width:60px;height:40px}.drawer{position:absolute;top:0;left:0;width:100%;height:100vh;z-index:1009;background:white;padding:60px 20px;box-sizing:border-box}.drawer a{display:block;width:100%;height:40px}';
    $html = '<!doctype html><html><head><style>' . $css . '</style></head><body><div class="wide" data-blocks-engine-capture-device-document="desktop" data-blocks-engine-capture-document-scope="desktop">' . $trigger . '<nav class="desktop-links"><a href="/one">One</a><a href="/two">Two</a><a href="/three">Three</a><a href="/four">Four</a><a href="/five">Five</a></nav></div><div class="compact" data-blocks-engine-capture-device-document="mobile" data-blocks-engine-capture-document-scope="mobile">' . $trigger . $panel . '</div><main><h1>Example</h1><p>Editable copy.</p></main><script data-blocks-engine-capture-disclosure-runtime="true">' . $script . '</script></body></html>';
    $reportPanel = '<div><a href="/fallback">Fallback</a></div>';
    $components = captured_components(array(array('status' => 'captured', 'trigger' => array('selector' => '.toggle', 'tag' => 'button', 'ariaHaspopup' => 'menu', 'label' => 'Toggle menu', 'dataBindings' => array()), 'dialog' => array('html' => $reportPanel, 'htmlBytes' => strlen($reportPanel), 'htmlTruncated' => false, 'presentation' => 'dropdown'))));
    return array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => $html,
        'capture-receipt.json' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'index.html')))),
        'interaction-states.json' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => $components)))),
    ));
};
