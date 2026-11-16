<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Framework selectors unsupported by the matcher still have required source
// classes. Neither generated native identities nor state pseudos make those
// absent classes live. Malformed lists remain untouched.
$artifact = array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="present">Home</p><button>Action</button></main></body></html>',
        'other.html' => '<main class="other-route">Other</main>',
        'site.css' => '.absent:focus-within{color:salmon}.present:focus-within{color:teal}.other-route{color:purple}.unused,.broken[foo=bar baz]{color:orchid}',
    ),
);
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$pages = $compiler->preparePages($artifact, $shared);
$receipts = $compiler->compilePreparedPages($shared, $pages);
foreach (array(
    $compiler->compile($artifact)->toArray(),
    $compiler->compose($shared, array_values($receipts))->toArray(),
) as $result) {
    $css = implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null))
    ));
    foreach (array('salmon' => false, 'teal' => true, 'purple' => true, 'orchid' => true) as $needle => $expected) {
        if (str_contains($css, $needle) !== $expected) throw new RuntimeException('Source selector pruning: ' . $needle);
    }
}
echo "Source selector pruning before projection passed\n";
