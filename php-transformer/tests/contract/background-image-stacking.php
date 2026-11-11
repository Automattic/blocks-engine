<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$css = 'div,img{position:relative}.item{width:300px;height:200px}.lazy{background-size:cover;width:100%;height:100%}.lazy img{z-index:9}.details{position:absolute;top:80px;left:0;right:0}';
$html = '<!doctype html><html><head><style>' . $css . '</style></head><body><main><a href="/t"><div class="item"><div class="lazy" style="background-image:url(x.jpg)"></div><div class="details"><span>Tavistock</span></div></div></a></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('files' => array(
    array('path' => 'index.html', 'kind' => 'html', 'content' => $html),
    array('path' => 'x.jpg', 'kind' => 'image', 'content' => base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSFw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==')),
)))->toArray();
$compiledCss = '';
foreach ($result['assets'] as $asset) {
    if (str_ends_with($asset['path'] ?? '', '.css')) {
        $compiledCss .= $asset['content'] . "\n";
    }
}
echo json_encode(array('source' => $html, 'native' => '<style>body{margin:0}' . $compiledCss . '</style>' . $result['serialized_blocks']));
