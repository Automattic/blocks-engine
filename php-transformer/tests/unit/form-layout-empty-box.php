<?php
declare(strict_types=1);

/**
 * Empty in-flow boxes between form fields (a reserved validation slot) are
 * layout-graph nodes, so consumers can keep the space they occupy.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;

$failures = array();
$check = static function (bool $ok, string $message) use (&$failures): void {
    if ( ! $ok ) $failures[] = $message;
};

$fixture = '<style>.error{display:block;height:14px;font-size:12px;line-height:14px}</style>
<form>
  <div class="field"><label for="name">Name *</label><input id="name" name="name"></div><span class="error">&nbsp;</span>
  <div class="field"><label for="email">Email *</label><input id="email" name="email" type="email"></div><span class="error">&nbsp;</span>
  <input type="submit" value="Send">
</form>';

$compile = static function (string $html): array {
    $result = (new ArtifactCompiler())->compile(array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => '<main>' . $html . '</main>' ) ))->toArray();
    foreach ( $result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration ) {
        if ( 'forms' === ($declaration['type'] ?? null) ) return $declaration['payload']['entities'][0] ?? array();
    }
    return array();
};
$empties = static fn (array $graph): array => array_values(array_filter($graph['nodes'] ?? array(), static fn (array $node): bool => 'empty_box' === ($node['kind'] ?? null)));
$build = static function (string $html, string $css = ''): array {
    $document = new DOMDocument();
    $document->loadHTML('<html><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
    return (new FormLayoutGraphBuilder())->build($document->getElementsByTagName('form')->item(0), array(), $css);
};

// The issue fixture yields two empty boxes between the field rows.
$entity = $compile($fixture);
$graph = $entity['layout_graph'] ?? array();
$check(false === ($graph['truncated'] ?? null), 'fixture graph reaches the forms entity untruncated');
$boxes = $empties($graph);
$check(2 === count($boxes), 'fixture yields two empty-box nodes');
foreach ( $boxes as $index => $box ) {
    $check('empty-' . $index === $box['id'] && 'form' === ($box['parent'] ?? null), "empty box {$index} has stable identity under the form");
    $check(1 + 2 * $index === $box['order'], "empty box {$index} sits after field row {$index}");
    $check('span' === $box['source']['tag'] && array( 'error' ) === $box['source']['classes'], "empty box {$index} names its source element");
    $check('14px' === ($box['layout']['height'] ?? null) && 'block' === ($box['layout']['display'] ?? null), "empty box {$index} is a 14px block");
    $check('no_visible_content' === ($box['content'] ?? null) && true === ($box['in_flow'] ?? null) && true === ($box['reserves_space'] ?? null), "empty box {$index} is in flow and reserves space");
    $check(array( 'display', 'height' ) === ($box['provenance'][0]['properties'] ?? null) && '.error' === ($box['provenance'][0]['selector'] ?? null), "empty box {$index} carries provenance");
}
// Existing control identities are unchanged by the new nodes.
$controls = array_values(array_filter($entity['control_topology']['nodes'] ?? array(), static fn (array $node): bool => 'control' === ($node['kind'] ?? null)));
$check(array( 'control-0', 'control-1', 'control-2' ) === array_column($controls, 'id'), 'control topology identities are unchanged');

// Skippable slots are excluded or marked so consumers can skip them.
$form = static fn (string $slot): string => '<form><div class="field"><input name="a"></div>' . $slot . '<div class="field"><input name="b"></div></form>';
$cases = array(
    'display none' => array( '<span class="error"></span>', '.error{display:none;height:14px}', false, false ),
    'hidden attribute' => array( '<div hidden style="height:14px"></div>', '', false, false ),
    'zero height' => array( '<span class="error"></span>', '.error{display:block;height:0}', true, false ),
    'absolute' => array( '<span class="error"></span>', '.error{display:block;height:14px;position:absolute}', false, false ),
    'fixed' => array( '<span class="error"></span>', '.error{display:block;height:14px;position:fixed}', false, false ),
    'inline span ignores height' => array( '<span class="error"></span>', '.error{height:14px}', true, false ),
    'block div min-height' => array( '<div class="error"></div>', '.error{min-height:1rem}', true, true ),
);
foreach ( $cases as $name => [ $slot, $css, $inFlow, $reserves ] ) {
    $boxes = $empties($build($form($slot), $css));
    $check(1 === count($boxes) && $inFlow === $boxes[0]['in_flow'] && $reserves === $boxes[0]['reserves_space'], "{$name}: marked in_flow/reserves_space " . json_encode($boxes));
}
$check(array() === $empties($build($form('<span class="error"></span>'), '.error{display:block}')), 'an unsized empty box is not emitted');

// Conditional height is a variant with provenance.
$graph = $build($form('<div class="error"></div>'), '@media (min-width: 600px){.error{height:20px}}');
$boxes = $empties($graph);
$variant = array_values(array_filter($graph['variants'], static fn (array $variant): bool => 'empty-0' === $variant['node']))[0] ?? array();
$check(1 === count($boxes) && false === $boxes[0]['reserves_space'], 'conditionally sized box reserves no base space');
$check('20px' === ($variant['layout_patch']['height'] ?? null) && 'media' === ($variant['condition']['kind'] ?? null) && array( 'height' ) === ($variant['provenance'][0]['properties'] ?? null), 'conditional height is a variant with provenance');

// A slot with visible text stays copy, not an empty box.
foreach ( array( 'Required', '<em>!</em>', '<img src="x.png" alt="">', '<svg></svg>' ) as $content ) {
    $graph = $build($form('<span class="error">' . $content . '</span>'), '.error{display:block;height:14px}');
    $check(array() === $empties($graph), 'visible content is not an empty box: ' . $content);
}
$withText = $compile(str_replace('<span class="error">&nbsp;</span>', '<span class="error">Required</span>', $fixture));
$check(array() === $empties($withText['layout_graph'] ?? array()), 'fixture with visible slot text emits no empty boxes');
$copy = $build('<form><div class="field"><input name="a"></div><p class="error">Required</p></form>', '.error{height:14px}');
$check(array( 'context-0' ) === array_values(array_filter(array_column($copy['nodes'], 'id'), static fn (string $id): bool => ! in_array($id, array( 'form', 'control-0', 'wrapper-0' ), true))), 'text slot stays a copy context node');

// MAX_NODES bounds hold: empty boxes never displace structure.
$many = '<form>';
for ( $i = 0; $i < 100; ++$i ) $many .= '<input name="f' . $i . '"><span class="error"></span>';
$graph = $build($many . '</form>', '.error{display:block;height:14px} input{display:block}');
$check(count($graph['nodes']) <= 128 && true === $graph['truncated'] && in_array('empty_box_limit', $graph['diagnostics'], true), 'empty boxes respect MAX_NODES and report truncation');
$check(100 === count(array_filter($graph['nodes'], static fn (array $node): bool => 'control' === $node['kind'])), 'every control survives the empty-box budget');

// Validators: empty boxes are closed, classified leaves.
$base = $build($form('<span class="error"></span>'), '.error{display:block;height:14px}');
$index = array_search('empty_box', array_column($base['nodes'], 'kind'), true);
$mutations = array(
    'missing classification' => static function (array &$g) use ($index): void { unset($g['nodes'][$index]['content']); },
    'wrong classification' => static function (array &$g) use ($index): void { $g['nodes'][$index]['content'] = 'copy'; },
    'non-bool flow' => static function (array &$g) use ($index): void { $g['nodes'][$index]['in_flow'] = 'yes'; },
    'space while out of flow' => static function (array &$g) use ($index): void { $g['nodes'][$index]['in_flow'] = false; },
    'container id' => static function (array &$g) use ($index): void { $g['nodes'][$index]['id'] = 'wrapper-99'; },
    'empty fact on container' => static function (array &$g): void { $g['nodes'][0]['content'] = 'no_visible_content'; },
    'child of empty box' => static function (array &$g) use ($index): void { $node = $g['nodes'][0]; $node['id'] = 'context-9'; $node['parent'] = $g['nodes'][$index]['id']; unset($node['in_flow_children']); $g['nodes'][] = $node; },
);
FormLayoutGraphBuilder::assertValid($base);
foreach ( $mutations as $name => $mutate ) {
    $graph = $base;
    $mutate($graph);
    try {
        FormLayoutGraphBuilder::assertValid($graph);
        $failures[] = 'validator accepted: ' . $name;
    } catch (InvalidArgumentException) {
    }
}
$v2 = $base;
$v2['schema'] = 'generic/computed-layout-graph/v2';
try {
    FormLayoutGraphBuilder::assertValid($v2);
    $failures[] = 'validator accepted an empty box in a v2 graph';
} catch (InvalidArgumentException) {
}

if ( array() !== $failures ) {
    fwrite(STDERR, 'FAIL: ' . implode(PHP_EOL . 'FAIL: ', $failures) . PHP_EOL);
    exit(1);
}
echo "form layout empty box passed\n";
