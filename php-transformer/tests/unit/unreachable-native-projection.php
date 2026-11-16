<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$artifact = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><button class="present">Action</button></main></body></html>',
    'other.html' => '<!doctype html><html><body><button class="other-route">Other</button></body></html>',
    'site.css' => '.absent:focus:not(:focus-visible){outline:7px solid salmon}.present{color:teal}.other-route{color:purple}',
));
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$pages = $compiler->preparePages($artifact, $shared);
foreach (array(
    $compiler->compile($artifact)->toArray(),
    $compiler->compose($shared, array_values($compiler->compilePreparedPages($shared, $pages)))->toArray(),
) as $result) {
    $css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
    foreach (array('salmon' => false, 'teal' => true, 'purple' => true) as $needle => $expected) {
        if (str_contains($css, $needle) !== $expected) throw new RuntimeException('Native projection reachability: ' . $needle);
    }
}
// Exercise the public projection boundary separately from final asset pruning:
// native aliases must never be minted for a provably absent source subject.
$projection = (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform(
    '<!doctype html><html><body><button class="present">Action</button></body></html>',
    array(
        'author_stylesheet_assets' => array(array('path' => 'site.css', 'content' => '.absent{color:salmon}.present{color:teal}')),
        'site_selector_pruner' => new \Automattic\BlocksEngine\PhpTransformer\Css\UnreachableSelectorPruner(array('<button class="present">Action</button>')),
    )
)->toArray();
$projected = json_encode($projection['source_reports']['author_stylesheet_projections'] ?? $projection['author_stylesheet_projections'] ?? array());
if (str_contains($projected, 'salmon') || !str_contains($projected, 'teal')) {
    throw new RuntimeException('Unreachable rules must be pruned at the projection boundary, not after native expansion');
}
// Route alignment mints classes after whole-site source evidence is captured.
// Those names are not absent source subjects and must survive projection.
$aligned = (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform(
    '<!doctype html><html><body><h3 class="route-class-123456789abc">Logo</h3></body></html>',
    array(
        'author_stylesheet_assets' => array(array('path' => 'aligned.css', 'content' => '.route-class-123456789abc{font-family:Georgia,serif}')),
        'site_selector_pruner' => new \Automattic\BlocksEngine\PhpTransformer\Css\UnreachableSelectorPruner(array('<h3 class="c1-1">Logo</h3>')),
    )
)->toArray();
$alignedProjection = json_encode($aligned['source_reports']['author_stylesheet_projections'] ?? $aligned['author_stylesheet_projections'] ?? array());
if (!str_contains($alignedProjection, 'Georgia')) {
    throw new RuntimeException('Route-aligned declarations must survive source-evidence pruning');
}
echo "Unreachable native projection preserves whole-site subject evidence\n";
