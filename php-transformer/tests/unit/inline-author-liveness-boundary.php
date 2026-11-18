<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Partial documents do not prove inline author rules dead. Linked framework
// rules without any site evidence can still be removed independently.
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<link rel="stylesheet" href="framework.css"><style>.partial-label{color:salmon}</style><main>Partial document</main>',
        'framework.css' => '.partial-label{background:teal}.absent-widget{color:purple}',
    ),
))->toArray();
$css = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null))
));
foreach (array('salmon' => true, 'teal' => true, 'purple' => false) as $value => $expected) {
    if (str_contains($css, $value) !== $expected) {
        throw new RuntimeException('Inline author liveness boundary: ' . $value);
    }
}
echo "Inline author liveness boundary passed\n";
