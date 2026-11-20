<?php
declare(strict_types=1);

// ADR 0005 slice 5: a box whose only load-bearing signal besides foldable
// declarations is a captured viewport entrance (a Wix rich-text wrapper that
// fades in) folds into its sole text child. The entrance is a runtime handle,
// not geometry: the child's block takes the entrance and the box's id, so the
// replayed animation and the state rules keyed on that id stay on the element
// that occupies the box's border box. A child with an id of its own cannot
// take the box's, so that box keeps its Group.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$base = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$initial = array(
    'overflow-x' => 'visible', 'overflow-y' => 'visible', 'mix-blend-mode' => 'normal',
    'padding-top' => '0px', 'padding-right' => '0px', 'padding-bottom' => '0px', 'padding-left' => '0px',
    'margin-top' => '0px', 'margin-right' => '0px', 'margin-bottom' => '0px', 'margin-left' => '0px',
    'row-gap' => 'normal', 'column-gap' => 'normal', 'flex-direction' => 'row', 'flex-wrap' => 'nowrap',
    'justify-content' => 'normal', 'align-items' => 'normal', 'align-content' => 'normal',
    'flex-grow' => '0', 'flex-shrink' => '1', 'flex-basis' => 'auto', 'align-self' => 'auto', 'order' => '0',
    'grid-template-columns' => 'none', 'grid-template-rows' => 'none', 'grid-auto-columns' => 'auto', 'grid-auto-rows' => 'auto',
    'grid-auto-flow' => 'row', 'grid-column-start' => 'auto', 'grid-column-end' => 'auto', 'grid-row-start' => 'auto',
    'grid-row-end' => 'auto', 'justify-items' => 'normal', 'justify-self' => 'auto',
    'border-top' => '0px none rgb(0, 0, 0)', 'border-right' => '0px none rgb(0, 0, 0)', 'border-bottom' => '0px none rgb(0, 0, 0)',
    'border-left' => '0px none rgb(0, 0, 0)', 'border-radius' => '0px', 'outline' => 'rgb(0, 0, 0) none 0px',
    'min-width' => 'auto', 'min-height' => 'auto', 'max-width' => 'none', 'max-height' => 'none',
);

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$entrance = '{"attributes":{"data-interact-enter":{"before":null,"after":"done"}},"rootMargin":"0px 0px 0px 0px","threshold":[0.15],"repeat":false}';
$compile = static function (string $childId, string $boxStyle = '', string $childStyle = '', string $tag = 'p', array $boxSizing = array(), array $childSizing = array()) use ($base, $initial, $entrance): array {
    $html = '<!doctype html><html data-blocks-engine-node-id="html"><head data-blocks-engine-node-id="head"><title data-blocks-engine-node-id="title">Entrance</title>'
        . '<style>#rich-text:not([data-interact-enter]){visibility:hidden}'
        . ($boxSizing ? '#rich-text{aspect-ratio:4/1}@media(max-width:1023px){#rich-text{aspect-ratio:1/1.32304}}' : '') . '</style></head>'
        . '<body data-blocks-engine-node-id="body"><main class="fold-parent" data-blocks-engine-node-id="parent">'
        . '<div id="rich-text" class="rich-text-box" style="' . htmlspecialchars($boxStyle, ENT_QUOTES) . '" data-interact-enter="done" data-blocks-engine-capture-viewport-entrance="' . htmlspecialchars($entrance, ENT_QUOTES) . '" data-blocks-engine-node-id="box">'
        . '<' . $tag . ('' !== $childId ? ' id="' . $childId . '"' : '') . ' style="' . htmlspecialchars($childStyle, ENT_QUOTES) . '" data-blocks-engine-node-id="child">' . ('figure' === $tag ? '<img src="card.jpg" alt="Card">' : 'Revealed text') . '</' . $tag . '></div>'
        . '<p data-blocks-engine-node-id="sibling">Sibling</p></main></body></html>';
    $tree = $base;
    $tree['source_hash'] = hash('sha256', $html);
    $tree['nodes'] = array();
    $layout = array('html' => array('head', 'body'), 'head' => array('title'), 'title' => array(), 'body' => array('parent'),
        'parent' => array('box', 'sibling'), 'box' => array('child'), 'child' => array(), 'sibling' => array());
    foreach ($layout as $id => $children) {
        $node = array('children' => $children, 'viewports' => array());
        foreach ($base['nodes']['n1']['viewports'] as $viewport => $observation) {
            $observation['used_styles'] = array_replace($observation['used_styles'], $initial, match ($id) {
                'parent' => array('display' => 'grid', 'grid-template-columns' => '400px'),
                'box' => array_replace(array('grid-row-start' => '1', 'grid-row-end' => '2', 'grid-column-start' => '1', 'grid-column-end' => '2', 'justify-self' => 'start'), $boxSizing),
                'child' => $childSizing,
                default => array(),
            });
            $observation['border_box'] = 'sibling' === $id
                ? array('x' => 0, 'y' => 100, 'width' => 400, 'height' => 20)
                : array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 100);
            $node['viewports'][$viewport] = $observation;
        }
        $tree['nodes'][$id] = $node;
    }
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
};

$result = $compile('');
$node = $result['source_reports']['box_tree']['index.html']['classification']['nodes']['box'];
$markup = $result['serialized_blocks'];
$assert('folded' === $node['disposition'] && in_array('viewport-entrance', $node['fold'] ?? array(), true), 'an entrance-only text wrapper is classified folded.');
$assert(!str_contains($markup, 'rich-text-box'), 'the wrapper emits no Group.');
$assert(1 === substr_count($markup, '<!-- wp:group '), 'only the parent remains a group.');
$assert(1 === preg_match('/<!-- wp:paragraph (\{[^\n]*?\}) -->\s*<p id="rich-text"[^>]*>Revealed text/', $markup, $match), 'the paragraph takes the box\'s place and id.');
$attrs = json_decode($match[1], true);
$assert('rich-text' === ($attrs['anchor'] ?? null), 'the paragraph keeps the id as its anchor.');
$assert(json_decode((string) ($attrs['metadata']['blocksEngineRuntimeAttributes']['data-blocks-engine-capture-viewport-entrance'] ?? 'null'), true) === json_decode($entrance, true), 'the paragraph carries the replayed entrance.');

$motion = '--motion-origin-x: 0%;--motion-origin-y: -50%;--motion-rotate-x: -90deg;--motion-rotate-y: 0deg';
foreach (array(
    'motion heading' => array($motion, '', 'h2', true),
    'clip paragraph' => array('--motion-clip-start: inset(0 100% 0 0)', '', 'p', true),
    'mixed case motion' => array('--MOTION-translate-x: 20px', '', 'h2', true),
    'child non-motion style' => array($motion, 'color: red', 'h2', true),
    'non-motion declaration' => array($motion . ';color: red', '', 'h2', false),
    'other custom property' => array('--other: 1', '', 'h2', false),
    'child motion conflict' => array($motion, '--motion-origin-x: 50%', 'h2', false),
    'child distinct motion' => array($motion, '--MOTION-clip-start: none', 'h2', false),
) as $label => [$boxStyle, $childStyle, $tag, $folds]) {
    $result = $compile('', $boxStyle, $childStyle, $tag);
    $node = $result['source_reports']['box_tree']['index.html']['classification']['nodes']['box'];
    $markup = $result['serialized_blocks'];
    $assert(($folds ? 'folded' : 'load-bearing') === $node['disposition'], "$label: expected disposition.");
    if (!$folds) {
        $assert('capture-marker' === $node['criterion'] && str_contains($markup, 'rich-text-box'), "$label: keeps its Group.");
        continue;
    }
    $block = 'p' === $tag ? 'paragraph' : 'heading';
    $assert(!str_contains($markup, 'rich-text-box') && 1 === substr_count($markup, '<!-- wp:group '), "$label: emits no wrapper Group.");
    $assert(1 === preg_match('/<!-- wp:' . $block . ' (\\{[^\\n]*?\\}) -->\\s*<' . $tag . ' id="rich-text"[^>]*>Revealed text/', $markup, $match), "$label: text block takes the id.");
    $attrs = json_decode($match[1], true);
    $assert(json_decode((string) ($attrs['metadata']['blocksEngineRuntimeAttributes']['data-blocks-engine-capture-viewport-entrance'] ?? 'null'), true) === json_decode($entrance, true), "$label: text block carries the entrance.");
    $css = implode("\n", array_column($result['assets'], 'content'));
    foreach (\Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer::declarationList($boxStyle) as $declaration) {
        $assert(1 === preg_match('/\\.([\\w-]+)\\{[^}]*' . preg_quote($declaration['name'], '/') . ':' . preg_quote($declaration['value'], '/') . '(?:[;!} ]|$)/', $css, $carrier)
            && in_array($carrier[1], explode(' ', $attrs['className'] ?? ''), true), "$label: text block carries " . $declaration['name']);
    }
}

// Non-text block roots take the same handoff, including author sizing bands.
foreach (array(
    'ratio frame' => array('div', array('position' => 'relative', 'aspect-ratio' => '4 / 1', 'height' => 'auto'), array('position' => 'absolute', 'height' => '100px'), true),
    'image root' => array('figure', array('aspect-ratio' => '4 / 1', 'height' => 'auto'), array('height' => 'auto'), true),
    'auto-height group' => array('div', array('aspect-ratio' => '4 / 1', 'height' => 'auto'), array('height' => 'auto'), true),
    'independent height' => array('div', array('aspect-ratio' => '4 / 1', 'height' => 'auto'), array('height' => '100px'), false),
) as $label => [$tag, $boxSizing, $childSizing, $folds]) {
    $result = $compile('', $motion, '', $tag, $boxSizing, $childSizing);
    $node = $result['source_reports']['box_tree']['index.html']['classification']['nodes']['box'];
    $markup = $result['serialized_blocks'];
    $assert(($folds ? 'folded' : 'load-bearing') === $node['disposition'], "$label: expected disposition.");
    if (!$folds) {
        $assert(str_contains($markup, 'rich-text-box'), "$label: keeps its Group.");
        continue;
    }
    $assert(!str_contains($markup, 'rich-text-box') && str_contains($markup, 'id="rich-text"'), "$label: child takes the id without the wrapper.");
    $assert(str_contains($markup, 'data-blocks-engine-capture-viewport-entrance'), "$label: child carries the entrance.");
    $css = implode("\n", array_column($result['assets'], 'content'));
    $assert(str_contains($css, 'aspect-ratio:1/1.32304'), "$label: child keeps the uncaptured-width ratio band.");
    $assert(str_contains($css, '--motion-origin-x:0%'), "$label: child keeps the motion properties.");
}

$result = $compile('own-id');
$node = $result['source_reports']['box_tree']['index.html']['classification']['nodes']['box'];
$markup = $result['serialized_blocks'];
$assert('load-bearing' === $node['disposition'] && 'capture-marker' === $node['criterion'], 'a child with its own id cannot take the box\'s entrance.');
$assert(str_contains($markup, 'rich-text-box') && str_contains($markup, 'id="own-id"'), 'that box keeps its Group around the child.');
