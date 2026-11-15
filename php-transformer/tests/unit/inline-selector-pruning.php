<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Inline CSS must not prove its own selectors live, nor keep the same
// absent selector alive in a linked sheet. Markup on another route and
// script-built classes still count as whole-site evidence.
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<link rel="stylesheet" href="site.css"><style>'
        . '.unused-inline{color:salmon}.other-route{color:teal}.dynamic-item{color:purple}'
        . '</style><main><p>Home</p></main><script>window.state="dynamic-" + "item";</script>',
    'other.html' => '<main><p class="other-route">Other route</p></main>',
    'site.css' => '.unused-inline{background:salmon}.other-route{background:teal}',
)))->toArray();
$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($compiled['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null))));
foreach (array('salmon' => false, 'teal' => true, 'purple' => true) as $needle => $expected) {
    if (str_contains($css, $needle) !== $expected) throw new RuntimeException('Unexpected inline selector reachability for ' . $needle);
}
echo "Inline selector pruning passed\n";
