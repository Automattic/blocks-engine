<?php
declare(strict_types=1);

require $argv[1] . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$css = 'a,span{margin:0;padding:0;border:0;font-size:100%}body{font:12px/1.5 "Helvetica Neue",Arial,sans-serif}'
    . '.icon-bar{border:2px solid rgba(0,0,0,.2);border-radius:4px;display:inline-block}.icon-bar a{background:#fff;border-bottom:1px solid #ccc;width:26px;height:26px;line-height:26px;display:block;text-align:center;text-decoration:none;color:black}'
    . '.zoom-in,.zoom-out{font:bold 18px "Lucida Console",Monaco,monospace;text-indent:1px}'
    . '.touch .icon-bar a{width:30px;height:30px;line-height:30px}.touch .zoom-in,.touch .zoom-out{font-size:22px}';
$html = '<!doctype html><html><head><style>' . $css . '</style></head><body><main><div class="touch"><div class="icon-bar">'
    . '<a class="zoom-in" href="#" role="button" aria-label="Zoom in"><span aria-hidden="true">+</span></a>'
    . '<a class="zoom-out" href="#" role="button" aria-label="Zoom out"><span aria-hidden="true">−</span></a>'
    . '</div></div></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $html))))->toArray();
$out = '';
foreach ($result['assets'] as $asset) {
    if (str_ends_with($asset['path'] ?? '', '.css')) {
        $out .= $asset['content'] . "\n";
    }
}
echo json_encode(array('src' => $html, 'html' => $result['serialized_blocks'], 'css' => $out));
