<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<link rel="stylesheet" href="site.css"><main class="md:grid w-[50%]">Home</main>',
    'site.css' => '.md\\:grid{display:grid}.w-\\[50\\%\\]{width:50%}.md\\:absent{color:salmon}.w-\\[123px\\]{width:123px}',
)))->toArray();
$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null))));
foreach (array('display:grid' => true, 'width:50%' => true, 'salmon' => false, '123px' => false) as $needle => $expected) {
    if (str_contains($css, $needle) !== $expected) throw new RuntimeException('Unexpected escaped selector reachability: ' . $needle);
}
echo "Escaped selector pruning passed\n";
