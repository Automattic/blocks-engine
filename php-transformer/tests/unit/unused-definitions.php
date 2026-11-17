<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$compile = static function (array $files): string {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    return implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
};

$css = $compile(array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"><link rel="stylesheet" href="other.css">'
        . '<style>@font-face{font-family:Unused-Embedded-Face;font-weight:600;src:url(data:font/woff2;base64,AAAA) format("woff2")}@font-face{font-family:"Inline Used Face";src:url(data:font/woff2;base64,BBBB) format("woff2")}</style>'
        . '</head><body><main><p class="live">Hello</p><p style="font-family:\'Inline Used Face\'">Inline</p></main></body></html>',
    'site.css' => '@keyframes unused-motion{from{opacity:.123}to{opacity:.456}}@media(min-width:600px){@keyframes cross-sheet-motion{from{opacity:.234}to{opacity:.567}}@-webkit-keyframes unused-prefixed-motion{from{opacity:.321}to{opacity:.654}}@keyframes state-motion{from{opacity:.345}to{opacity:.678}}}.absent{animation:unused-motion 1s}'
        . '@font-face{font-family:"Sheet Unused Face";src:url(data:font/woff2;base64,CCCC) format("woff2")}@font-face{font-family:Cross-Sheet-Face;src:url(data:font/woff2;base64,DDDD) format("woff2")}',
    'other.css' => '.live{animation:cross-sheet-motion 1s;font-family:cross-sheet-face,sans-serif}@media(min-width:800px){.live:hover{animation:state-motion 1s}}',
));
if (str_contains($css, '@keyframes unused-motion')) throw new RuntimeException('Unreferenced animation inventory must not ship');
if (!str_contains($css, '@keyframes cross-sheet-motion')) throw new RuntimeException('Cross-sheet animation references must remain');
if (str_contains($css, '@-webkit-keyframes unused-prefixed-motion')) throw new RuntimeException('Nested prefixed animation inventory must not ship');
if (!str_contains($css, '@keyframes state-motion')) throw new RuntimeException('Responsive state animation references must remain');
if (str_contains($css, 'Unused-Embedded-Face') || str_contains($css, 'AAAA')) throw new RuntimeException('Unreferenced embedded font face must not ship');
if (str_contains($css, 'Sheet Unused Face') || str_contains($css, 'CCCC')) throw new RuntimeException('Unreferenced stylesheet font face must not ship');
if (!str_contains($css, 'DDDD')) throw new RuntimeException('Font faces used by another stylesheet must remain');
if (!str_contains($css, 'BBBB')) throw new RuntimeException('Font faces used by inline markup styles must remain');

// A runtime that loads faces itself may name them dynamically: keep them.
$css = $compile(array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"><script>document.fonts.load("1em " + window.face)</script></head><body><main><p>Hello</p></main></body></html>',
    'site.css' => '@font-face{font-family:Runtime-Face;src:url(data:font/woff2;base64,EEEE) format("woff2")}p{color:red}',
));
if (!str_contains($css, 'EEEE')) throw new RuntimeException('Font faces a runtime may load dynamically must remain');
echo "Unused keyframes and font faces are pruned across published stylesheets\n";
