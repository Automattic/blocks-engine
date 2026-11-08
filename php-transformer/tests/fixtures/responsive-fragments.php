<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$document = static function (string $route): string {
    $body = '';
    foreach (array('desktop', 'mobile') as $device) {
        $target = 'index' === $route ? '<h2 id="section' . ('mobile' === $device ? '--data-blocks-engine-capture-mobile' : '') . '" data-blocks-engine-capture-responsive-fragment="section">Section ' . $device . '</h2>' : '<h2>Other content</h2>';
        $body .= '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-document-scope="" data-blocks-engine-capture-device-document="' . $device . '"><header><p>Example</p><nav><a href="/index.html#section">Section</a></nav></header><main><h1>' . $route . '</h1><div style="height:1200px"></div>' . $target . '<p style="height:900px">End</p></main></div>';
    }
    return '<!doctype html><html><head><style>@media(max-width:768px){.data-blocks-engine-capture-desktop-document{display:none}}@media(min-width:769px){.data-blocks-engine-capture-mobile-document{display:none}}</style></head><body>' . $body . '</body></html>';
};
return array('schema' => ArtifactCompiler::INPUT_SCHEMA, 'entrypoint' => 'index.html', 'files' => array('index.html' => $document('index'), 'other.html' => $document('other')));
