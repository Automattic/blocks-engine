<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

// A long text page captured with every box side reset (`* { margin: 0;
// padding: 0 }` plus a bottom margin per paragraph) must not restate the
// zero padding and inline margins on each paragraph, heading and list: the
// emitted elements and core styles already leave those sides at zero, so
// each block would carry an eight-value inline spacing style for one
// meaningful margin (#7099). Vertical margins, which the user agent sets to
// 1em, a list's inline padding (its marker indent) and a painted
// paragraph's padding (core pads `has-background`) stay.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$styles = static function (array $overrides = array()): array {
    $used = array_fill_keys(BoxTree::PROPERTIES, 'none');
    foreach (array('padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left') as $box) $used[$box] = '0px';
    foreach (array('border-top', 'border-right', 'border-bottom', 'border-left') as $border) $used[$border] = '0px none rgb(0, 0, 0)';
    $used = array_merge($used, array(
        'display' => 'block', 'position' => 'static', 'overflow-x' => 'visible', 'overflow-y' => 'visible',
        'background-color' => 'rgba(0, 0, 0, 0)', 'opacity' => '1', 'mix-blend-mode' => 'normal',
        'border-radius' => '0px', 'letter-spacing' => 'normal', 'font-style' => 'normal', 'text-transform' => 'none',
        'color' => 'rgb(41, 77, 75)', 'font-family' => 'Arial', 'font-size' => '16px', 'font-weight' => '400', 'line-height' => '24px',
        'list-style-type' => 'disc', 'list-style-position' => 'outside',
    ));
    return array_merge($used, $overrides);
};

$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><title data-blocks-engine-node-id="n3">Policy</title></head>'
    . '<body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="n5"><article data-blocks-engine-node-id="art">'
    . '<h2 data-blocks-engine-node-id="h">Section heading</h2>'
    . '<p data-blocks-engine-node-id="p1">First policy paragraph.</p>'
    . '<p data-blocks-engine-node-id="p2">Second policy paragraph.</p>'
    . '<p data-blocks-engine-node-id="badge">Effective date</p>'
    . '<ul data-blocks-engine-node-id="list"><li data-blocks-engine-node-id="li1">Alpha item</li><li data-blocks-engine-node-id="li2">Beta item</li></ul>'
    . '</article></main></body></html>';
$observe = static fn (array $box, array $used): array => array(
    'desktop' => array('border_box' => $box, 'used_styles' => $used),
    'mobile' => array('border_box' => $box, 'used_styles' => $used),
);
$box = static fn (int $y, int $height, int $x = 0, int $width = 600): array => array('x' => $x, 'y' => $y, 'width' => $width, 'height' => $height);
$nodes = array(
    'n1' => array('children' => array('n4'), 'viewports' => $observe($box(0, 300), $styles())),
    'n4' => array('children' => array('n5'), 'viewports' => $observe($box(0, 300), $styles())),
    'n5' => array('children' => array('art'), 'viewports' => $observe($box(0, 300), $styles())),
    'art' => array('children' => array('h', 'p1', 'p2', 'badge', 'list'), 'viewports' => $observe($box(0, 300), $styles())),
    'h' => array('children' => array(), 'viewports' => $observe($box(0, 32), $styles(array('font-size' => '27px', 'font-weight' => '600', 'line-height' => '32px', 'margin-bottom' => '12px')))),
    'p1' => array('children' => array(), 'viewports' => $observe($box(44, 24), $styles(array('margin-bottom' => '18px')))),
    'p2' => array('children' => array(), 'viewports' => $observe($box(86, 24), $styles(array('margin-bottom' => '18px')))),
    'badge' => array('children' => array(), 'viewports' => $observe($box(128, 40), $styles(array('background-color' => 'rgb(239, 245, 217)', 'margin-bottom' => '18px')))),
    'list' => array('children' => array('li1', 'li2'), 'viewports' => $observe($box(186, 48), $styles(array('padding-left' => '25px', 'margin-bottom' => '22px')))),
    'li1' => array('children' => array(), 'viewports' => $observe($box(186, 24, 25, 575), $styles(array('display' => 'list-item')))),
    'li2' => array('children' => array(), 'viewports' => $observe($box(210, 24, 25, 575), $styles(array('display' => 'list-item')))),
);
$tree = array('schema' => BoxTree::SCHEMA, 'viewports' => array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844)), 'nodes' => $nodes, 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html));
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$markup = (string) $result['serialized_blocks'];
if (getenv("DUMP")) echo $markup, "\n";

$opening = static function (string $text) use ($markup): string {
    $at = strpos($markup, '>' . $text . '<');
    if (false === $at) throw new RuntimeException("Missing block text: $text");
    $start = strrpos(substr($markup, 0, $at), '<!-- wp:');
    return substr($markup, (int) $start, $at + 1 - (int) $start);
};
$spacing = static function (string $block): array {
    if (1 !== preg_match('/^<!-- wp:[a-z\/-]+ (\{.*?\}) -->/s', $block, $match)) return array();
    $attrs = json_decode($match[1], true);
    return is_array($attrs['style']['spacing'] ?? null) ? $attrs['style']['spacing'] : array();
};
$sideKeys = static fn (array $spacing, string $group): array => array_keys(is_array($spacing[$group] ?? null) ? $spacing[$group] : array());

foreach (array('First policy paragraph.' => 'paragraph', 'Second policy paragraph.' => 'paragraph', 'Section heading' => 'heading') as $text => $kind) {
    $block = $opening($text);
    $sides = $spacing($block);
    $assert(array() === $sideKeys($sides, 'padding'), "The $kind restates no zero padding.");
    $assert(array() === array_intersect($sideKeys($sides, 'margin'), array('left', 'right')), "The $kind restates no zero inline margin.");
    $assert(in_array('top', $sideKeys($sides, 'margin'), true) && in_array('bottom', $sideKeys($sides, 'margin'), true), "The $kind keeps its vertical margins over the user-agent 1em.");
    $assert(0 === preg_match('/padding-(?:top|right|bottom|left):0px|margin-(?:left|right):0px/', $block), "The $kind serializes no zero padding or inline margin.");
}

$badge = $spacing($opening('Effective date'));
$assert(array('top', 'right', 'bottom', 'left') === $sideKeys($badge, 'padding'), 'A painted paragraph keeps its zero padding over the has-background box.');

$list = $spacing((string) substr($markup, (int) strrpos(substr($markup, 0, (int) strpos($markup, '>Alpha item<')), '<!-- wp:list ')));
$assert(in_array('left', $sideKeys($list, 'padding'), true) && in_array('right', $sideKeys($list, 'padding'), true), 'A list keeps its inline padding over the marker indent.');
$assert(array() === array_intersect($sideKeys($list, 'padding'), array('top', 'bottom')), 'A list restates no zero block padding.');
$assert(array() === array_intersect($sideKeys($list, 'margin'), array('left', 'right')), 'A list restates no zero inline margin.');

$item = $opening('Alpha item');
$assert(array() === $sideKeys($spacing($item), 'padding') && array() === array_intersect($sideKeys($spacing($item), 'margin'), array('left', 'right')), 'A list item restates no zero padding or inline margin.');
