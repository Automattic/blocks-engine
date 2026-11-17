<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A framework stylesheet registers and resets every token its utilities read
// (Tailwind v4's `@layer properties` and `@property`), and defines keyframes
// its animation tokens name. Only the utilities the site's markup carries read
// anything: a registration names its property without reading it, and a
// keyframes name inside a longer identifier (`enter` in `center`) names no
// animation.
$css = '@layer properties{*,:before,:after,::backdrop{--fw-translate-x:0;--fw-shadow:0 0 #0000;--fw-border-style:solid;--fw-enter-opacity:1}}'
    . '@layer theme{:root,:host{--fw-spacing:.25rem;--fw-unused-size:.75rem}}'
    . '.border{border-style:var(--fw-border-style);border-width:1px}.p-1{padding:var(--fw-spacing)}.text-center{text-align:center}'
    . '.translate-x-1{--fw-translate-x:4px;translate:var(--fw-translate-x) 0}.shadow{box-shadow:var(--fw-shadow)}.animate-in{animation:enter .2s}'
    . '@property --fw-translate-x{syntax:"*";inherits:false;initial-value:0}@property --fw-shadow{syntax:"*";inherits:false;initial-value:0 0 #0000}'
    . '@property --fw-border-style{syntax:"*";inherits:false;initial-value:solid}@property --fw-enter-opacity{syntax:"*";inherits:false;initial-value:1}'
    . '@keyframes enter{from{opacity:var(--fw-enter-opacity,1)}}';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="border p-1 text-center">Hello</p></main></body></html>',
    'site.css' => $css,
)))->toArray();
$published = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
foreach (array('--fw-translate-x', '@property --fw-shadow', '--fw-enter-opacity', '--fw-unused-size', '@keyframes enter') as $name) {
    if (str_contains($published, $name)) throw new RuntimeException('Tokens and definitions only unreachable utilities read must not ship: ' . $name . "\n" . $published);
}
foreach (array('@property --fw-border-style', '--fw-border-style:solid', '--fw-spacing:.25rem') as $kept) {
    if (!str_contains($published, $kept)) throw new RuntimeException('Tokens a rendered utility reads must survive: ' . $kept . "\n" . $published);
}
echo "Property registrations and resets only unreachable utilities read are pruned\n";
