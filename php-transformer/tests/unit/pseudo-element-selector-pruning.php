<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main class="present">Home</main></body></html>',
        'site.css' => '.absent::before{content:"unused-pseudo"}.present::before{content:"live-pseudo"}.unused,.broken[foo=bar baz]{color:orchid}',
    ),
))->toArray();
$css = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null))
));
foreach (array('unused-pseudo' => false, 'live-pseudo' => true, 'orchid' => true) as $needle => $expected) {
    if (str_contains($css, $needle) !== $expected) throw new RuntimeException('Pseudo-element selector liveness: ' . $needle);
}
echo "Pseudo-element selector pruning passed\n";
