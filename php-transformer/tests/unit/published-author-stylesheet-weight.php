<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A framework stylesheet ships with its author's indentation and styles
// element types the site never renders. Neither may reach the theme: a type
// named only inside a longer identifier (`pre` in `preview`, `sub` in
// `subheading`) names no element, and whitespace between tokens is weight.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="preview subheading">Hello</p><p class="a,">Kept</p><script>document.body.append(document.createElement("dt"));</script></main></body></html>',
    'site.css' => "pre {\n    font-family: monospace;\n    color: salmon;\n}\nsub,\nsup {\n    color: orchid;\n}\ndt {\n    color: navy;\n}\n.preview  .missing-child ,\n.preview {\n    color: rgb(1, 2, 3);\n    content: \"a  ,  b\";\n    --empty: ;\n}\n.a\\, p {\n    color: olive;\n}\n",
)))->toArray();
$assets = array_column($result['assets'], null, 'path');
$css = (string) ($assets['site.css']['content'] ?? '');
foreach (array('salmon', 'orchid') as $value) {
    if (str_contains($css, $value)) throw new RuntimeException('A type no evidence names must not ship: ' . $value . "\n" . $css);
}
if (!str_contains($css, 'navy')) throw new RuntimeException('A script-created type keeps its rule' . "\n" . $css);
if (1 === preg_match('/\n|\{\s|;\s/', $css)) throw new RuntimeException('Author indentation must not ship' . "\n" . $css);
foreach (array('.preview{color:rgb(1,2,3);content:"a  ,  b";--empty: }', '.a\\, ') as $rule) {
    if (!str_contains($css, $rule)) throw new RuntimeException('Tokens, strings, empty values and escaped delimiters must survive: ' . $rule . "\n" . $css);
}
echo "Published author stylesheets drop unnamed types and redundant whitespace\n";
