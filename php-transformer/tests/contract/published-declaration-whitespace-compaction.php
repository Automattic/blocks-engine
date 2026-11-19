<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssDeclarationWhitespaceCompactor;

$source = '.paint{ outline-offset :  3px ;content: "a; b" ;width:calc(100% - 2px) }';
$expected = '.paint{outline-offset:3px;content:"a; b";width:calc(100% - 2px)}';
if ($expected !== CssDeclarationWhitespaceCompactor::stylesheet($source)) throw new RuntimeException('Values and custom-property whitespace must survive.');
foreach (array('.x{ --tokens:  red  ;color: red}', '.x{color: /* boundary */ red}', '.x{--rules:{color:red};color: red}', '.x{content:"\\61 ";color: red}') as $opaque) {
    if ($opaque !== CssDeclarationWhitespaceCompactor::stylesheet($opaque)) throw new RuntimeException('Opaque declaration blocks must survive.');
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body><main><p class="paint">Paint</p></main></body></html>',
    'style.css' => '.paint:hover{ outline-offset :  3px ;outline-style: dotted }',
)))->toArray();
$styles = implode("\n", array_column($result['source_reports']['wordpress_site_plan']['assets'], 'content'));
if (!str_contains($styles, 'outline-offset:3px') || preg_match('/outline-offset\s*:\s+3px/', $styles)) throw new RuntimeException('Published state declarations must not repeat delimiter whitespace.');
echo "Published declaration whitespace compaction: ok\n";
