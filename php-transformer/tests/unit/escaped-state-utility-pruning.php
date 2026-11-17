<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// State words inside an escaped utility are not runtime route-state classes.
$artifact = array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="present">Home</p><p class="hover:active">Used</p></main></body></html>',
        'site.css' => '.absent\\:selected{color:salmon}.\\[\\&\\:has\\(\\[aria-selected\\]\\)\\]\\:bg-accent:has([aria-selected]){color:orchid}.hover\\:active{color:teal}.menu-item--selected{color:purple}.present:hover{color:gold}',
    ),
);
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$pages = $compiler->preparePages($artifact, $shared);
$receipts = $compiler->compilePreparedPages($shared, $pages);
foreach (array($compiler->compile($artifact)->toArray(), $compiler->compose($shared, array_values($receipts))->toArray()) as $result) {
    $css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
    foreach (array('salmon' => false, 'orchid' => false, 'teal' => true, 'purple' => true, 'gold' => true) as $needle => $expected) {
        if (str_contains($css, $needle) !== $expected) throw new RuntimeException('Escaped state utility pruning: ' . $needle);
    }
}
echo "Escaped utility identities do not inherit route-state exemptions\n";
