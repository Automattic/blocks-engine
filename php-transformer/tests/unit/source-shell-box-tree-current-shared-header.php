<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$fixture = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$files = array();
$paths = array('index.html', 'about/index.html', 'contact/index.html');
foreach ($paths as $route => $path) {
    $links = '';
    foreach ($paths as $i => $target) {
        $links .= '<div class="nav-item' . (0 === $i ? ' home-item' : '') . ($i === $route ? ' nav-item--active' : '') . '"><a href="/' . $target . '"' . ($i === $route ? ' aria-current="page"' : '') . '>Page ' . $i . '</a></div>';
    }
    $html = '<!doctype html><html><head><title>Page ' . $route . '</title><style>.header .header-skip-link{position:absolute;left:-10000px}</style></head><body><header class="header" data-blocks-engine-node-id="header"><a class="header-skip-link" href="#main">Skip to Content</a><nav>' . $links . '</nav></header><main><h1>Page ' . $route . '</h1><p>Route body.</p></main></body></html>';
    $tree = array('schema' => $fixture['schema'], 'source_path' => $path, 'source_hash' => hash('sha256', $html), 'viewports' => $fixture['viewports'], 'nodes' => array());
    $observations = $fixture['nodes']['n1']['viewports'];
    foreach ($observations as &$observation) {
        $observation['border_box']['width'] = 390;
        $observation['border_box']['height'] = 100 + $route;
        $observation['used_styles']['padding-top'] = (20 + $route) . 'px';
        $observation['used_styles']['overflow-x'] = 0 === $route ? 'hidden' : 'visible';
        $observation['used_styles']['overflow-y'] = 0 === $route ? 'hidden' : 'visible';
        $observation['used_styles']['background-color'] = 'rgb(12, 34, 56)';
    }
    unset($observation);
    $tree['nodes']['header'] = array('children' => array(), 'viewports' => $observations);
    $files[$path] = $html;
    $files['box-tree/route-' . $route . '.json'] = json_encode($tree, JSON_THROW_ON_ERROR);
}
$compiler = new ArtifactCompiler();
$result = $compiler->compile(array('entrypoint' => 'index.html', 'files' => $files));
$compiled = $result->toArray();
$assert(!array_filter($compiled['diagnostics'], static fn(array $d): bool => str_starts_with($d['code'], 'box_tree_')), 'The v2 sidecars are accepted.');
$shells = array_values(array_filter($compiled['source_reports']['compiled_site']['inline_shell_artifacts'], static fn(array $shell): bool => 'header' === $shell['area']));
$assert(1 === count($shells) && str_contains($shells[0]['block_markup'], 'padding-top:20px'), 'The isolated shared shell is restyled from canonical box-tree evidence.');
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'One shared header serves all three routes.');
$covered = $headers[0]['placement']['source_paths'] ?? array_keys($headers[0]['provenance']['sources'] ?? array());
sort($covered);
$expected = $paths;
sort($expected);
$assert($expected === $covered, 'Every route belongs to the shared header.');
foreach ($plan['pages'] as $page) {
    $rendered = $page['canonical_block_markup'] . implode('', ThemeTemplates::rendered($plan));
    $assert(str_contains($rendered, '"slug":"' . $headers[0]['slug'] . '"'), 'The route renders the shared header at its source position.');
}
$assert(str_contains($headers[0]['canonical_block_markup'], 'padding-top:20px'), 'The canonical route supplies observed header geometry.');
$assert(str_contains($headers[0]['canonical_block_markup'], 'has-background'), 'Observed header paint is retained.');
preg_match('/be-inline-geometry-[a-f0-9]+/', $headers[0]['canonical_block_markup'], $geometry);
$assert(isset($geometry[0]), 'The canonical header uses generated geometry support.');
$supportRows = array_values(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && (str_contains($asset['content'] ?? '', $geometry[0]) || str_contains($asset['content'] ?? '', '.header-skip-link'))));
$assert(array() !== $supportRows, 'The header retains compiled author support CSS.');
foreach ($supportRows as $asset) {
    $scopes = $asset['scopes'] ?? array();
    $sources = array_column($scopes, 'source_path');
    foreach ($covered as $path) {
        $assert(in_array(array('kind' => 'global'), $scopes, true) || in_array($path, $sources, true), 'Header support CSS loads on member ' . $path);
    }
}
// A real capture can emit exactly the same support bytes while compiling
// the evidence page and its isolated shell. Exercise that collision explicitly:
// the page occurrence must not swallow the shell's membership at composition.
$sharedSupport = current(array_filter($compiled['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && str_contains($asset['content'] ?? '', $geometry[0])));
$assert(is_array($sharedSupport), 'Compiled canonical geometry has a support asset.');
$sharedSupport['compilation'] = array('scope' => 'shared', 'source_paths' => $paths);
$pageSupport = $sharedSupport;
$pageSupport['compilation'] = array('scope' => 'page', 'id' => $paths[0]);
$merge = new ReflectionMethod(ArtifactCompiler::class, 'generatedAssetsForDocuments');
$merged = $merge->invoke($compiler, array($pageSupport), $pageSupport['compilation'], array(), array(), array($sharedSupport));
$assert(1 === count($merged), 'Identical page and shell support bytes produce one stylesheet.');
$members = $merged[0]['compilation']['source_paths'] ?? array();
sort($members);
$assert($expected === $members, 'Deduplicated support keeps every shell member, not only the evidence page.');
echo "Source shell box-tree current shared header: passed\n";
