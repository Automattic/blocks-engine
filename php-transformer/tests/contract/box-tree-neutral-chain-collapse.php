<?php
declare(strict_types=1);

// ADR 0005 slice 4: on an evidence route, a box the classifier marks neutral
// emits no block and its child takes its place. A painting sibling is kept.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><title data-blocks-engine-node-id="n3">Neutral chain</title></head>'
    . '<body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="n5">'
    . '<div class="outer-box" data-blocks-engine-node-id="outer"><div class="inner-box" data-blocks-engine-node-id="inner"><p data-blocks-engine-node-id="n6">Child survives</p></div></div>'
    . '<div class="paint-box" data-blocks-engine-node-id="paint"><p data-blocks-engine-node-id="n7">Painting sibling</p></div>'
    . '</main></body></html>';
$tree = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n5']['children'] = array('outer', 'paint');
foreach (array('outer' => 'inner', 'inner' => 'n6', 'paint' => 'n7') as $id => $child) {
    $tree['nodes'][$id] = array('children' => array($child), 'viewports' => $tree['nodes'][$child]['viewports'] ?? $tree['nodes']['n6']['viewports']);
}
foreach ($tree['nodes'] as $id => &$node) {
    foreach ($node['viewports'] as &$observation) {
        $observation['used_styles']['mix-blend-mode'] = 'normal';
        if ('paint' === $id) $observation['used_styles']['background-color'] = 'rgb(255, 0, 0)';
        // The surviving child keeps its own captured rule.
        if ('n6' === $id) $observation['used_styles']['color'] = 'rgb(0, 0, 255)';
    }
    unset($observation);
}
unset($node);

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$compile = static function (bool $evidence) use ($html, $tree): array {
    $files = array('index.html' => $html);
    if ($evidence) $files['box-tree/index.json'] = json_encode($tree);
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
};
$css = static fn (array $result): string => implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));

$result = $compile(true);
$classification = $result['source_reports']['box_tree']['index.html']['classification']['nodes'];
$assert('neutral' === $classification['outer']['disposition'] && 'neutral' === $classification['inner']['disposition'], 'The wrapper chain is classified neutral.');
$assert('paints' === $classification['paint']['criterion'], 'The sibling is classified as painting.');

$markup = $result['serialized_blocks'];
$assert(2 === substr_count($markup, '<!-- wp:group '), 'The neutral chain emits no group; only main and the painting sibling are groups.');
$assert(!str_contains($markup, 'outer-box') && !str_contains($markup, 'inner-box'), 'Neither neutral box survives as a block identity.');
$assert(str_contains($markup, 'paint-box'), 'The painting sibling keeps its identity.');
$assert(1 === preg_match('/<!-- wp:paragraph (\{[^\n]*?\}) -->\s*<p[^>]*>Child survives/', $markup, $match), 'The neutral chain child takes the chain\'s place.');
$first = strpos($markup, 'Child survives');
$assert(false !== $first && $first < strpos($markup, 'paint-box') && $first < strpos($markup, 'Painting sibling'), 'Source order is preserved.');
$childAttrs = json_decode($match[1], true);
$carrierCss = $css($result);
$carriers = array_filter(preg_split('/\s+/', (string) ($childAttrs['className'] ?? '')) ?: array(), static fn (string $class): bool => '' !== $class && str_contains($carrierCss, '.' . $class . '.' . $class));
$assert(array() !== $carriers && 'rgb(0, 0, 255)' === ($childAttrs['style']['color']['text'] ?? null), 'The child keeps its own engine rule and captured styles.');
$assert(str_contains($carrierCss . $markup, 'rgb(255, 0, 0)'), 'The painting sibling keeps its captured paint.');
