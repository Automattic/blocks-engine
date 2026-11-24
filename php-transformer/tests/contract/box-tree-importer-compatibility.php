<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Contract/BoxTree.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

// The importer smoke fixture still builds v2 observations from this contract.
if ('blocks-engine/box-tree/v2' !== BoxTree::SCHEMA || !in_array('display', BoxTree::PROPERTIES, true) || !in_array('background-blend-mode', BoxTree::PROPERTIES, true)) {
    throw new RuntimeException('Importer sidecar fixture contract changed.');
}

// The importer reads these before compilation, even though intake is removed.
if (33554432 !== BoxTree::MAX_BYTES) {
    throw new RuntimeException('Importer sidecar size limit changed.');
}
foreach (array('box-tree/index.json', 'capture/box-tree/page.json') as $path) {
    if (!BoxTree::isSidecarPath($path)) {
        throw new RuntimeException('Importer sidecar was not recognized: ' . $path);
    }
}
foreach (array('box-tree.json', 'box-tree/index.css', 'not-box-tree/index.json', 'box-tree/index.json.bak') as $path) {
    if (BoxTree::isSidecarPath($path)) {
        throw new RuntimeException('Authored asset was recognized as a sidecar: ' . $path);
    }
}
if ('data-blocks-engine-node-id' !== BoxTree::NODE_ATTRIBUTE || !BoxTree::isEvidenceAttribute(strtoupper(BoxTree::NODE_ATTRIBUTE)) || BoxTree::isEvidenceAttribute('data-authored')) {
    throw new RuntimeException('Capture-only attribute recognition changed.');
}
$document = new DOMDocument();
$element = $document->createElement('div');
$element->setAttribute(BoxTree::NODE_ATTRIBUTE, 'n1');
$element->setAttribute('class', 'authored');
$element->setAttribute('data-authored', 'keep');
$attributes = array_map(static fn (DOMAttr $attribute): string => $attribute->name, BoxTree::authoredAttributes($element));
if (array('class', 'data-authored') !== $attributes || !$element->hasAttribute(BoxTree::NODE_ATTRIBUTE)) {
    throw new RuntimeException('Authored attribute projection must omit only capture identity without mutating the source.');
}
echo "Box-tree importer compatibility: passed\n";
