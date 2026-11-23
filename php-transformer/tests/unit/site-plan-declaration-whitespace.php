<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><head><link rel="stylesheet" href="site.css"></head><body><main><h1 class="title">Title</h1></main></body></html>',
    'site.css' => '.title{ text-shadow :  1px  2px 3px #123456 ; --space: ; --text: "a ; b"; outline-offset :  13px ! important ; }',
)))->toArray();
$css = '';
foreach ($result['source_reports']['wordpress_site_plan']['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null)) $css .= $asset['content'] . "\n";
}
if (!str_contains($css, 'text-shadow:1px 2px 3px #123456')) throw new RuntimeException("Declaration separators should be compact, value tokens intact:\n" . $css);
if (!str_contains($css, 'outline-offset:13px!important')) throw new RuntimeException("Declaration formatting still ships:\n" . $css);
if (!str_contains($css, '--space: ') || !str_contains($css, '"a ; b"')) throw new RuntimeException("Custom-property whitespace and quoted delimiters must survive:\n" . $css);
echo "Final stylesheet declaration formatting is compact without changing tokens\n";
