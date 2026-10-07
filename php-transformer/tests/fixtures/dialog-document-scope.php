<?php
declare(strict_types=1);

return static function (string $variant = 'desktop', bool $application = false): array {
    $key = 'gallery-' . $variant;
    $trigger = '<button id="open-' . $key . '" type="button" data-dla-dialog-trigger="' . $key . '" data-dla-dialog-ancestor-unverified="ancestor-limit" aria-controls="' . $key . '" aria-expanded="false" aria-haspopup="dialog">Open gallery</button>';
    for ($depth = 0; $depth < 16; ++$depth) $trigger = '<div class="gallery-level">' . $trigger . '</div>';
    $widget = $application ? '<div id="workspace"><section data-compute="pending"><canvas id="paint"></canvas></section><section data-compute="pending"><input id="gain"><button id="run">Draw</button></section></div>' : '';
    $observer = <<<'JS'
document.querySelectorAll('[data-dla-native-node]').forEach(function(target){
    var scope=target.closest('[data-dla-document-scope]');
    scope.querySelectorAll('[data-dla-native-node]').forEach(function(node){node.setAttribute('data-ready','true');});
});
JS;
    $script = <<<'JS'
document.querySelectorAll('[data-dla-dialog-trigger]').forEach(function(trigger){
    var scope=trigger.closest('[data-dla-document-scope]');
    var panel=scope.querySelector('[data-dla-dialog-panel]');
    trigger.addEventListener('click',function(){panel.hidden=false;trigger.setAttribute('aria-expanded','true');});
    panel.querySelector('button').addEventListener('click',function(){panel.hidden=true;trigger.setAttribute('aria-expanded','false');});
    document.addEventListener('keydown',function(event){if(event.key==='Escape'){panel.hidden=true;trigger.setAttribute('aria-expanded','false');}});
});
JS;
    $applicationScript = $application ? '<script>document.querySelectorAll("[data-compute]").forEach(function(el){el.setAttribute("data-compute","ready");});var canvas=document.getElementById("paint");document.getElementById("run").addEventListener("click",function(){canvas.getContext("2d").fillRect(0,0,20,20);});</script>' : '';
    $panel = '<div id="' . $key . '" data-dla-dialog-panel="' . $key . '" role="dialog" aria-label="Image gallery" hidden><h2>Image gallery</h2><p>Captured image caption.</p><button type="button" data-dla-dialog-close="' . $key . '" aria-label="Close gallery">Close</button></div>';
    $html = '<!doctype html><html><head><title>Scoped gallery</title><style>.gallery-level{display:block}dialog{max-width:30rem}</style></head><body>'
        . '<div class="data-liberation-' . $variant . '-document" data-dla-document-scope="">'
        . '<main><h1>Unrelated editable heading</h1><section data-dla-native-node="copy"><p>Unrelated main copy.</p></section>' . $trigger . $widget . '</main>'
        . '<footer><p>Unrelated editable footer.</p></footer>'
        . $panel . '</div><script data-dla-native-view-timeline-runtime="true">' . $observer . '</script><script data-dla-disclosure-runtime="true">' . $script . '</script>' . $applicationScript . '</body></html>';
    $state = array(
        'kind' => 'dialog', 'status' => 'captured',
        'trigger' => array('selector' => '#open-' . $key, 'tag' => 'button', 'label' => 'Open gallery'),
        'dialog' => array('selector' => '#' . $key, 'html' => $panel, 'htmlTruncated' => false, 'ancestorState' => array('status' => 'incomplete', 'reason' => 'ancestor-limit')),
        'closed' => array('verified' => true),
    );
    $interactions = array('schema' => 'data-liberation/captured-interactions/v1', 'pages' => array(array('sourceUrl' => 'https://fixture.test/gallery', 'states' => array($state))));
    return array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => $html,
        'capture-receipt.json' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => array(array('url' => 'https://fixture.test/gallery', 'path' => 'index.html'))), JSON_THROW_ON_ERROR),
        'interaction-states.json' => json_encode($interactions, JSON_THROW_ON_ERROR),
    ));
};
