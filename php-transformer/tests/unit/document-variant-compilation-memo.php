<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\DocumentReferenceIndex;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

$document = new DOMDocument();
$document->loadHTML('<div class="site-document-variant-desktop"><p id="source">Text</p></div>');
$source = $document->getElementById('source');
$root = $source->parentNode;
DocumentReferenceIndex::install($document);
try {
    for ($i = 0; $i < 5; ++$i) {
        if (SourceDom::documentVariantRoot($source) !== $root) throw new RuntimeException('Memoized variant root differs');
    }
    $clone = $source->cloneNode(true);
    if (null !== SourceDom::documentVariantRoot($clone)) throw new RuntimeException('Detached clone inherited source scope');
} finally {
    DocumentReferenceIndex::release($document);
}
$root->removeAttribute('class');
DocumentReferenceIndex::install($document);
try {
    if (null !== SourceDom::documentVariantRoot($source)) throw new RuntimeException('Variant memo leaked into next compilation');
} finally {
    DocumentReferenceIndex::release($document);
}
echo "Document variant compilation memo passed\n";
