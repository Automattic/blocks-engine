<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A framework's root inventory declares font stacks, gradients and `r,g,b`
// triples whatever the site reads. Once projection binds the consumers to
// presets, nothing reads most of them, yet each shipped in the front-end and
// editor-canvas copies of the inventory. A compound value no stylesheet,
// markup or script reads computes nothing, exactly like a simple one.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="lead">Hello</p></main></body></html>',
    'site.css' => ':root{--fw-secondary-rgb:108,117,125;--fw-font-sans-serif:system-ui,-apple-system,"Segoe UI",Arial,sans-serif;--fw-gradient:linear-gradient(180deg,rgba(255,255,255,.15),rgba(255,255,255,0));--fw-primary-rgb:255,200,0;--fw-shadow:0 0 0 1px red}'
        . '.lead{color:rgba(var(--fw-primary-rgb),1);box-shadow:var(--fw-shadow)}',
)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
foreach (array('--fw-secondary-rgb', '--fw-font-sans-serif', '--fw-gradient') as $name) {
    if (str_contains($css, $name)) throw new RuntimeException('Unread compound inventory property must not ship: ' . $name . "\n" . $css);
}
foreach (array('--fw-primary-rgb:255,200,0', '--fw-shadow:0 0 0 1px red') as $declaration) {
    if (!str_contains($css, $declaration)) throw new RuntimeException('Read compound inventory property must survive: ' . $declaration . "\n" . $css);
}
echo "Unread compound inventory properties are pruned\n";
