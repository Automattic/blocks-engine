<?php
declare(strict_types=1);

// ADR 0005: a custom element captured as `display: contents` generates no box
// at any viewport, so its child already takes the parent's layout. On an
// evidence route it emits no block, rather than a boxless Group that adds a
// List View level (and pushes descendant wrapper chains into generated
// layout shells). A boxless element that still passes an inherited value to
// its child is kept. The browser resolved the child's `min-width: auto` to
// `0px` against the boxless DOM parent, so it is still pruned as initial.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><title data-blocks-engine-node-id="n3">Boxless hosts</title>'
    . '<style>interact-element{display:contents}.card{background:#eee}[data-interact-key="k1"] :is(.card){transform:none}</style></head>'
    . '<body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="n5">'
    . '<interact-element data-interact-key="k1" data-blocks-engine-node-id="host"><div class="card card-one" data-blocks-engine-node-id="card"><p data-blocks-engine-node-id="n6">Card copy</p></div></interact-element>'
    . '<interact-element data-interact-key="k2" data-blocks-engine-node-id="tinted"><div class="card card-two" data-blocks-engine-node-id="card2"><p data-blocks-engine-node-id="n7">Tinted copy</p></div></interact-element>'
    . '</main></body></html>';
$tree = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n5']['children'] = array('host', 'tinted');
foreach (array('host' => 'card', 'card' => 'n6', 'tinted' => 'card2', 'card2' => 'n7') as $id => $child) {
    $tree['nodes'][$id] = array('children' => array($child), 'viewports' => $tree['nodes']['n5']['viewports']);
}
foreach ($tree['nodes'] as $id => &$node) {
    foreach ($node['viewports'] as &$observation) {
        $observation['used_styles']['mix-blend-mode'] = 'normal';
        if ('n5' === $id) $observation['used_styles']['display'] = 'grid';
        if (in_array($id, array('host', 'tinted'), true)) {
            $observation['used_styles']['display'] = 'contents';
            $observation['border_box'] = array('x' => 0, 'y' => 0, 'width' => 0, 'height' => 0);
        }
        if (in_array($id, array('card', 'card2'), true)) {
            $observation['used_styles']['background-color'] = 'rgb(238, 238, 238)';
            $observation['used_styles']['min-width'] = '0px';
        }
        if (in_array($id, array('tinted', 'card2', 'n7'), true)) $observation['used_styles']['color'] = 'rgb(255, 0, 0)';
    }
    unset($observation);
}
unset($node);

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'box-tree/index.json' => json_encode($tree),
)))->toArray();
$classification = $result['source_reports']['box_tree']['index.html']['classification']['nodes'];
$assert('neutral' === $classification['host']['disposition'] && 'no-box' === $classification['host']['criterion'], 'A boxless custom element is classified neutral.');
$assert('load-bearing' === $classification['tinted']['disposition'] && 'inherits' === $classification['tinted']['criterion'], 'A boxless element that passes an inherited value stays load-bearing.');

$markup = $result['serialized_blocks'];
$hosts = substr_count($markup, 'source-interact-element');
$assert(1 === preg_match_all('/<!-- wp:group \{[^\n]*?source-interact-element/', $markup), 'Only the inheriting host emits a Group (' . $hosts . ' host markers).');
$assert(1 === preg_match('/<main[^>]*>\s*<!-- wp:group \{[^\n]*?card-one/', $markup), 'The boxless host\'s child takes its place directly inside main.');
$carrierCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
preg_match('/<!-- wp:group (\{"className":"card card-one[^\n]*?\}) -->/', $markup, $card);
$cardRules = '';
foreach (preg_split('/\s+/', (string) (json_decode($card[1] ?? '{}', true)['className'] ?? '')) ?: array() as $class) {
    if ('' !== $class && preg_match_all('/\.' . preg_quote($class, '/') . '\.' . preg_quote($class, '/') . '\{[^}]*\}/', $carrierCss, $rules)) $cardRules .= implode('', $rules[0]);
}
$assert('' !== $cardRules, 'The promoted child keeps its own engine rule.');
$assert(!str_contains($cardRules, 'min-width'), 'A min-width resolved against the boxless DOM parent is not declared on the grid item.');
$assert(str_contains($markup, 'Card copy') && str_contains($markup, 'Tinted copy'), 'Both cards keep their content.');
foreach ($result['blocks'] as $block) {
    $assert(str_starts_with((string) ($block['blockName'] ?? 'core/'), 'core/'), 'Every top-level block is native.');
}
