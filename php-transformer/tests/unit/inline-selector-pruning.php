<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Author selectors also define the specificity ceiling for projected inline
// document styles. An unmatched selector cannot simply be erased before that
// ceiling is computed, even though it does not currently match the document.
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html style="overflow:auto"><head><style>#absent #also-absent{color:salmon}</style></head><body><main>Home</main></body></html>',
)))->toArray();
$frontend = '';
foreach ($compiled['source_reports']['wordpress_site_plan']['assets'] as $asset) {
    if ('frontend' === ($asset['stylesheet_target'] ?? 'both')) $frontend .= $asset['content'] ?? '';
}
if (!preg_match('/,(#[a-zA-Z0-9_-]+)\1\1\)/', $frontend)) {
    throw new RuntimeException('Inline root carrier must retain the author specificity ceiling, including unmatched author IDs');
}
// Inline selectors remain author evidence: incomplete markup and responsive
// variants must not cause authored rules to disappear.
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<link rel="stylesheet" href="site.css"><style>.unused-inline{color:salmon}.other-route{color:teal}.dynamic-item{color:purple}</style><main>Home</main><script>window.state="dynamic-" + "item";</script>',
    'other.html' => '<main class="other-route">Other</main>',
    'site.css' => '.unused-inline{background:salmon}.other-route{background:teal}',
)))->toArray();
$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($compiled['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null))));
foreach (array('salmon' => true, 'teal' => true, 'purple' => true) as $needle => $expected) {
    if (str_contains($css, $needle) !== $expected) throw new RuntimeException('Unexpected inline selector reachability for ' . $needle);
}
echo "Inline selector pruning and specificity preservation passed\n";
