<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// The entry page has no measured styles. Its otherwise identical header must
// not seed a CSS-only shell for routes whose author stylesheets are retired.
$fixture = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$files = array();
foreach (array('index.html', 'help/index.html', 'contact/index.html') as $index => $path) {
    $html = '<!doctype html><html><head><title>Page ' . $index . '</title></head><body><header data-blocks-engine-node-id="header"><p>Shared brand</p></header><main><h1>Page ' . $index . '</h1></main></body></html>';
    $files[$path] = $html;
    if (0 === $index) continue;
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
if (1 !== count($headers)) throw new RuntimeException('Expected one shared header.');
if (!str_contains($headers[0]['canonical_block_markup'], 'padding-top:23px')) {
    throw new RuntimeException('Shared header lost the measured member padding.');
}
if (!str_contains($headers[0]['canonical_block_markup'], 'has-background')) {
    throw new RuntimeException('Shared header lost the measured member paint.');
}
$members = array_keys($headers[0]['provenance']['sources'] ?? array());
foreach (array('index.html', 'help/index.html', 'contact/index.html') as $path) {
    if (!in_array($path, $members, true)) throw new RuntimeException('Header no longer shared by ' . $path);
}
echo "Shared shell prefers a box-tree member: passed\n";
