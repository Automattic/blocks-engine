<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace('</head>', '<link data-blocks-engine-node-id="styles" rel="stylesheet" href="author.css"></head>', $html);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n2']['children'][] = 'styles';
$tree['nodes']['styles'] = array('children' => array(), 'viewports' => $tree['nodes']['n3']['viewports']);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
foreach (array(false, true, 'v1') as $mode) {
    $captured = true === $mode;
    $files = array('index.html' => $html, 'author.css' => 'main{display:grid}h1{opacity:.25}');
    $observations = $tree;
    $observations['nodes']['n6']['viewports']['desktop']['used_styles']['opacity'] = '0.75';
    $observations['nodes']['n6']['viewports']['mobile']['used_styles']['opacity'] = '1';
    if ('v1' === $mode) $observations['schema'] = 'blocks-engine/box-tree/v1';
    if (false !== $mode) $files['box-tree/index.json'] = json_encode($observations);
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files));
    $compiled = $result->toArray();
    if ('v1' === $mode) $assert(in_array('box_tree_schema_invalid', array_column($compiled['diagnostics'], 'code'), true), 'v1 evidence is rejected and retains the author-CSS path.');
    $plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
    $css = implode("\n", array_column(array_filter($compiled['assets'], static fn(array $a): bool => 'css' === ($a['kind'] ?? null)), 'content'));
    $assert($captured === str_contains($css, 'opacity:0.75'), 'Per-node observed paint is emitted only with accepted evidence.');
    $assert($captured === str_contains($css, '@media (max-width:390px)'), 'Observed mobile viewport produces a delta rule.');
    if ($captured) {
        $assert(str_contains($css, 'opacity:1'), 'Mobile delta retains reset to the initial value.');
        $assert(!str_contains($css, '!important'), 'Engine box-tree rules remain overridable by editor styles.');
        $assert(1 === preg_match('/\.([a-zA-Z_][a-zA-Z0-9_-]*)\.\1\{/', $css), 'Engine selectors repeat their carrier to outrank core block and layout rules.');
        $assert(!str_contains($compiled['serialized_blocks'], 'data-blocks-engine-node-id'), 'Capture identity does not leak into blocks.');
        $assert(!empty($plan['pages'][0]['document_metadata']['engine_owned_styles']), 'Evidence ownership reaches canonical page metadata.');
        $assets = array_column($plan['assets'], null, 'target_path');
        $authorAssets = array_filter($plan['assets'], static fn(array $asset): bool => str_ends_with($asset['source_path'], 'author.css'));
        $assert(array() !== $authorAssets && !str_contains(implode('', array_column($authorAssets, 'content')), 'h1{opacity'), 'Theme inventory retires author rule bytes.');
        foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) {
            $asset = $assets[$row['target_path']];
            $assert(!str_contains((string) ($asset['content'] ?? ''), 'h1{opacity'), 'No author selector bytes are delivered on the evidence route.');
        }
        $bootstrap = array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];
        $assert(str_contains($bootstrap, '$blocks_engine_route_styles = array() !== $blocks_engine_style_routes;') && str_contains($bootstrap, '$blocks_engine_style_routes[] = ['), 'Evidence route publishes a route row that disables global author fallback.');
    } else {
        $assets = array_column($plan['assets'], null, 'target_path');
        $delivered = '';
        foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $delivered .= $assets[$row['target_path']]['content'] ?? '';
        $assert(str_contains($delivered, 'opacity'), 'Routes without evidence still deliver author CSS.');
    }
}
