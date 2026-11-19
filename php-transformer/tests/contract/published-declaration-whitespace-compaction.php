<?php
declare(strict_types=1);
// Exercise the public plugin bootstrap, including checkouts without Composer.
require dirname(__DIR__, 2) . '/php-transformer.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssDeclarationWhitespaceCompactor;

$source = '.paint{ outline-offset :  3px ;content: "a; b" ;width:calc(100% - 2px) }';
$expected = '.paint{outline-offset:3px;content:"a; b";width:calc(100% - 2px)}';
if ($expected !== CssDeclarationWhitespaceCompactor::stylesheet($source)) throw new RuntimeException('Values and custom-property whitespace must survive.');
foreach (array('.x{color: /* boundary */ red}', '.x{--rules:{color:red};color: red}', '.x{content:"\\61 ";color: red}') as $opaque) {
    if ($opaque !== CssDeclarationWhitespaceCompactor::stylesheet($opaque)) throw new RuntimeException('Opaque declaration blocks must survive.');
}
$mixed = '.paint{ --tokens:  red  ;outline-offset:  3px ;--empty: ;--quoted:"a;b";outline-style: dotted }';
if ('.paint{ --tokens:  red  ;outline-offset:3px;--empty: ;--quoted:"a;b";outline-style:dotted}' !== CssDeclarationWhitespaceCompactor::stylesheet($mixed)) {
    throw new RuntimeException('Custom properties must retain their exact token streams while ordinary declarations compact.');
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body><main><p class="paint">Paint</p></main></body></html>',
    'style.css' => '.paint:hover{--captured-tokens:  red  ; outline-offset :  3px ;outline-style: dotted }',
)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$styles = implode("\n", array_map(static fn (array $write): string => 'theme_asset' === $write['kind'] ? ($write['payload']['data'] ?? '') : '', $plan['writes']));
if (!str_contains($styles, 'outline-offset:3px') || preg_match('/outline-offset\s*:\s+3px/', $styles)) throw new RuntimeException('Published state declarations must not repeat delimiter whitespace.');
$evidence = implode("\n", array_column($plan['assets'], 'content'));
if (!str_contains($evidence, 'outline-offset :3px')) {
    throw new RuntimeException('Transport compaction must not change the source evidence consumed by semantic passes.');
}
// Publication compacts delimiters, not authored colour evidence or the
// frontend-only document declarations replayed with inline priority.
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html style="position: static !important;overflow: hidden auto;height: auto !important"><head><style>.paint:hover{color:rgb(201, 169, 106)}</style></head><body><p class="paint">Paint</p><table><tr bgcolor="#ff0000"><td>Hint</td></tr></table></body></html>',
)))->toArray()['source_reports']['wordpress_site_plan'];
$published = implode("\n", array_column($plan['assets'], 'content'));
if (!str_contains($published, 'color:rgb(201, 169, 106)') || !str_contains($published, 'background-color:#ff0000')) {
    throw new RuntimeException('Publication must preserve authored and presentational colour spellings.');
}
$frontend = array_filter($plan['assets'], static fn (array $asset): bool => 'frontend' === ($asset['stylesheet_target'] ?? 'both'));
$root = implode("\n", array_column($frontend, 'content'));
if (!str_contains($root, 'position: static !important') || !str_contains($root, 'overflow: hidden auto')) {
    throw new RuntimeException('Frontend document declarations must retain their inline source spelling.');
}
echo "Published declaration whitespace compaction: ok\n";
