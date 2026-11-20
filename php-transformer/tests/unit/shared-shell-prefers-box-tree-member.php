<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Identical markup does not mean identical delivery: measured routes retire
// author CSS, while unmeasured routes still need their CSS-backed shell.
$fixture = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$files = array();
foreach (array('index.html', 'legacy/index.html', 'help/index.html', 'contact/index.html') as $index => $path) {
    $html = '<!doctype html><html><head><title>Page ' . $index . '</title></head><body><header data-blocks-engine-node-id="header"><p>Shared brand</p></header><main><h1>Page ' . $index . '</h1></main></body></html>';
    $files[$path] = $html;
    if ($index < 2) continue;
    $observations = $fixture['nodes']['n1']['viewports'];
    foreach ($observations as &$observation) {
        $observation['used_styles']['padding-top'] = '23px';
        $observation['used_styles']['background-color'] = 'rgb(12, 34, 56)';
    }
    unset($observation);
    $files['box-tree/route-' . $index . '.json'] = json_encode(array(
        'schema' => $fixture['schema'], 'source_path' => $path, 'source_hash' => hash('sha256', $html),
        'viewports' => $fixture['viewports'],
        'nodes' => array('header' => array('children' => array(), 'viewports' => $observations)),
    ), JSON_THROW_ON_ERROR);
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files));
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
if (2 !== count($headers)) throw new RuntimeException('Expected separate measured and CSS-backed shared headers.');
foreach ($headers as $header) {
    $members = array_keys($header['provenance']['sources'] ?? array());
    $measured = in_array('help/index.html', $members, true);
    $expected = $measured ? array('help/index.html', 'contact/index.html') : array('index.html', 'legacy/index.html');
    sort($members);
    sort($expected);
    if ($members !== $expected) throw new RuntimeException('Shared header crosses stylesheet delivery modes.');
    $markup = $header['canonical_block_markup'];
    if ($measured !== str_contains($markup, 'padding-top:23px')) {
        throw new RuntimeException('Measured padding leaked or was lost.');
    }
    if ($measured !== str_contains($markup, 'has-background')) {
        throw new RuntimeException('Measured paint leaked or was lost.');
    }
}
echo "Shared shells isolate box-tree delivery: passed\n";
