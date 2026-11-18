<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\UnreachableSelectorPruner;

// A tag or attribute name in a complete document names an element type or an
// attribute, never a class: `<h2>` must not keep a framework's `.h2` alias,
// nor `placeholder=` its `.placeholder` utility. Names a script, handler,
// attribute value or comment spells stay live, and attribute subjects still
// read attribute names.
$document = '<!doctype html><html><head><script>el.classList.add("shrink"); if (a<b) { go(); }</script></head><body>'
    . '<!-- .commented --><h2 class="heading">Hi</h2><nav id="main"><input placeholder="Name" data-panel data-x=\'quoted-val\'></nav></body></html>';
$pruned = (new UnreachableSelectorPruner(array($document), true))->prune(
    'h2,.h2{color:navy}.h2{color:salmon}.placeholder{color:plum}.nav{color:khaki}'
    . '.heading{color:teal}.shrink{color:olive}.quoted-val{color:tan}#main{color:gold}.commented{color:lime}.Name{color:pink}[data-panel]{color:coral}nav{color:wheat}'
);
foreach (array('salmon' => false, 'plum' => false, 'khaki' => false, 'navy' => true, 'teal' => true, 'olive' => true, 'tan' => true, 'gold' => true, 'lime' => true, 'pink' => true, 'coral' => true, 'wheat' => true) as $needle => $expected) {
    if (str_contains($pruned, $needle) !== $expected) throw new RuntimeException('Markup name class evidence: ' . $needle . ' in ' . $pruned);
}
if (str_contains($pruned, '.h2')) throw new RuntimeException('A tag name kept the .h2 alias arm: ' . $pruned);

// The compiled theme drops the alias arm and keeps the element arm.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><h2 class="title">Title</h2></main></body></html>',
    'site.css' => 'h2,.h2{font-weight:700}.h2{color:salmon}.title{color:teal}',
)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
if (str_contains($css, 'salmon') || !str_contains($css, 'teal') || !str_contains($css, '700')) throw new RuntimeException('Compiled theme kept a markup-name class alias: ' . $css);
echo "Markup names are not class evidence\n";
