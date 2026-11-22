<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="copy">Copy</p></main></body></html>',
    'site.css' => '.copy{ text-indent:  13px ; word-spacing:  7px ! important ; content: "a ; b"; --author-space: ; }',
)))->toArray();
$css = '';
foreach ($result['source_reports']['wordpress_site_plan']['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null)) $css .= $asset['content'];
}
if (!str_contains($css, 'text-indent:13px;word-spacing:7px!important;')) throw new RuntimeException('Delivered declarations retain redundant boundary whitespace: ' . $css);
if (!str_contains($css, '"a ; b"')) throw new RuntimeException('Quoted value tokens changed.');
$tokens = '.copy{--author-space: ;content:"a ; b";text-indent: 13px }';
$expected = '.copy{--author-space: ;content:"a ; b";text-indent:13px}';
if ($expected !== \Automattic\BlocksEngine\PhpTransformer\Css\CssDeclarationBoundaryCompactor::stylesheet($tokens)) throw new RuntimeException('The compactor must preserve custom-property and quoted token streams.');
echo "Site plan compacts declaration boundaries without changing value tokens\n";
