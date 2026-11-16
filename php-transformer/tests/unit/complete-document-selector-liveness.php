<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// The inline copy of a framework is inventory, not evidence that its unused
// components exist. Other routes and scripts still provide subject evidence.
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="framework.css"><style>.absent-widget{color:salmon}.other-route{color:teal}.dynamic-item{color:purple}.unused,.broken[foo=bar baz]{color:orchid}</style></head><body><main>Home</main><script>window.state="dynamic-" + "item";</script></body></html>',
        'other.html' => '<main class="other-route">Other</main>',
        'framework.css' => '.absent-widget{background:salmon}.other-route{background:teal}.dynamic-item{background:purple}',
    ),
))->toArray();
$css = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null))
));
foreach (array('salmon' => false, 'teal' => true, 'purple' => true, 'orchid' => true) as $value => $expected) {
    if (str_contains($css, $value) !== $expected) {
        throw new RuntimeException('Complete document selector liveness: ' . $value);
    }
}
echo "Complete document selector liveness passed\n";
