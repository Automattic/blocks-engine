<?php
declare(strict_types=1);

// ADR 0005: a text-less whole-element link wrapper whose box the capture proves
// equal to its sole child is load-bearing only through its link. Once that
// link moves onto the wrapped image, the anchor emits no Group of its own, so
// a builder's link layer (Wix `_linkLayer`) does not add an editor nesting level.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><title data-blocks-engine-node-id="n3">Link carrier</title>'
    . '<style>.link-layer,.hover-layer{display:block;width:100%;height:100%}a:hover{color:red}</style></head>'
    . '<body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="n5">'
    . '<a class="link-layer" href="/about/#work" data-blocks-engine-capture-anchor-fragment="work" data-blocks-engine-node-id="carrier"><div class="frame" data-blocks-engine-node-id="frame"><div class="media" data-blocks-engine-node-id="media"><img src="https://example.com/work.png" alt="Work" data-blocks-engine-node-id="img"></div><div class="media" data-blocks-engine-node-id="media2"><img src="https://example.com/work-detail.png" alt="Work detail" data-blocks-engine-node-id="img2"></div></div></a>'
    . '<a class="hover-layer" href="/contact/" data-blocks-engine-node-id="hovered"><div class="hover-frame" data-blocks-engine-node-id="hover-frame"><div class="media" data-blocks-engine-node-id="hover-media"><img src="https://example.com/contact.png" alt="Contact" data-blocks-engine-node-id="hover-img"></div><div class="media" data-blocks-engine-node-id="hover-media2"><img src="https://example.com/contact-detail.png" alt="Contact detail" data-blocks-engine-node-id="hover-img2"></div></div></a>'
    . '</main></body></html>';
$tree = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n5']['children'] = array('carrier', 'hovered');
// Each anchor fills its frame exactly; each frame lays out two media boxes.
$chains = array(
    'carrier' => array('frame'), 'frame' => array('media', 'media2'), 'media' => array('img'), 'media2' => array('img2'), 'img' => array(), 'img2' => array(),
    'hovered' => array('hover-frame'), 'hover-frame' => array('hover-media', 'hover-media2'), 'hover-media' => array('hover-img'), 'hover-media2' => array('hover-img2'), 'hover-img' => array(), 'hover-img2' => array(),
);
$viewports = $tree['nodes']['n6']['viewports'];
unset($tree['nodes']['n6'], $tree['nodes']['n7']);
foreach ($chains as $id => $child) {
    $tree['nodes'][$id] = array('children' => $child, 'viewports' => $viewports);
}
foreach ($tree['nodes'] as $id => &$node) {
    foreach ($node['viewports'] as &$observation) {
        $observation['used_styles']['mix-blend-mode'] = 'normal';
        // The anchors are the UA link blue; their image content has no text to inherit it.
        if (in_array($id, array('carrier', 'hovered'), true)) $observation['used_styles']['color'] = 'rgb(0, 0, 238)';
    }
    unset($observation);
}
unset($node);
// The capture saw the second anchor's own hover state.
$tree['nodes']['hovered']['interaction_states'] = array('hover');

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree)),
))->toArray();
$classification = $result['source_reports']['box_tree']['index.html']['classification']['nodes'];
$assert('semantics/id/anchor' === $classification['carrier']['criterion'] && true === ($classification['carrier']['link_carrier'] ?? null), 'An equal-box link wrapper is classified as a link carrier.');
$assert(empty($classification['hovered']['link_carrier']), 'A link wrapper with its own captured state keeps its box.');

$markup = $result['serialized_blocks'];
$assert(!str_contains($markup, 'link-layer'), 'The link carrier emits no Group once its link moved.');
$assert(1 === preg_match('/<!-- wp:image [^\n]*?--><figure[^>]*><a href="\/about\/#work"[^>]*><img[^>]*work\.png/', $markup), 'The carried link lives on the image.');
$assert(1 === preg_match('/<!-- wp:group \{[^\n]*?"className":"frame[^"]*"[^\n]*?-->\s*<div[^>]*>\s*<!-- wp:image /', $markup), 'The wrapped box takes the anchor\'s place around the images.');
$assert(str_contains($markup, 'hover-layer') && str_contains($markup, 'href="/contact/"'), 'The state-targeted link wrapper keeps its Group and link.');
