<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssColorValueCompactor;

$css = '.paint{color:rgb(17, 34, 51);border-color:#aabbcc!important;--paint:rgb(17, 34, 51);content:"#aabbcc";background-image:url("#aabbcc")}@media(min-width:700px){.paint{outline-color:rgb(255, 255, 255)}}';
$expected = '.paint{color:#123;border-color:#abc!important;--paint:rgb(17, 34, 51);content:"#aabbcc";background-image:url("#aabbcc")}@media(min-width:700px){.paint{outline-color:#fff}}';
if ($expected !== CssColorValueCompactor::stylesheet($css)) throw new RuntimeException('Only literal colour values may shrink.');
$invalid = '.paint{color:rgb(256,0,0);color:var(--paint);--paint:#aabbcc}';
if ($invalid !== CssColorValueCompactor::stylesheet($invalid)) throw new RuntimeException('Invalid colours and custom properties must remain unchanged.');

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body><main><p class="paint">Paint</p></main></body></html>',
    'style.css' => '.paint:hover{outline-color:rgb(17, 34, 51)}',
)))->toArray();
$style = implode("\n", array_column($result['source_reports']['wordpress_site_plan']['assets'], 'content'));
if (!str_contains($style, 'outline-color:#123') || str_contains($style, 'outline-color:rgb(17')) throw new RuntimeException('Published CSS must compact colour literals, including state rules.');
echo "Published colour literal compaction: ok\n";
