<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\InteractiveSurface;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\DocumentReferenceIndex;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<body><div id="target"></div><div id="self" aria-controls="self"></div><div id="target"></div><a href="#tar%67et"></a><button aria-haspopup="listbox" aria-controls="target other"></button><div data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="opener" data-blocks-engine-closers="opener"></div><button id="opener"></button></body>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
$elements = iterator_to_array($document->getElementsByTagName('*'));
$read = static function () use ($elements): array {
    return array_map(static fn($element): array => array(
        InteractiveSurface::classify($element),
        SourceDom::documentReferencesFragmentId($element, $element->getAttribute('id')),
        SourceDom::isBoundCapturedDialogTrigger($element),
        SourceDom::isBoundCapturedDialogCloser($element),
    ), $elements);
};
$before = $read();
DocumentReferenceIndex::install($document);
InteractiveSurface::indexDocument($document);
if ($read() !== $before) throw new RuntimeException('Indexed references changed semantics.');
if (2 !== count(DocumentReferenceIndex::forDocument($document)->elementsWithId('target'))) throw new RuntimeException('Duplicate responsive ids were lost.');
DocumentReferenceIndex::release($document);
InteractiveSurface::releaseDocument($document);
if (null !== DocumentReferenceIndex::forDocument($document)) throw new RuntimeException('Conversion index leaked.');
$document->getElementsByTagName('button')->item(0)->removeAttribute('aria-controls');
if (null !== InteractiveSurface::classify($document->getElementById('target'))) throw new RuntimeException('Standalone mutable DOM query was cached.');
fwrite(STDOUT, "Document reference index regression passed.\n");
