<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use DOMElement;

/**
 * Lowers a `li` whose parent is not a list: invalid markup the list converter
 * never reaches. Invalid list ancestry does not remove browser marker
 * rendering or author `li` selectors, so the source root stays on the exact
 * wrapper carrier while its children remain native editable blocks.
 */
final class OrphanListItemConverter implements ElementConverter
{
    public function __construct(
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly RichTextMaterializer $richTextMaterializer,
        private readonly PatternTreeConverter $tree,
        private readonly LayoutShellLowering $layoutShells,
        private readonly SourceBlockCreator $createBlock
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ( 'li' !== $tagName || $this->isInList($element) || ! $this->runtimeIslands->isSafeTransparentCustomElement($element) ) {
            return ConversionOutcome::unhandled();
        }

        $children = $this->sourceElementClassifier->hasOnlyPhrasingChildren($element)
            ? array($this->createBlock->createBlock('core/paragraph', array('content' => $this->richTextMaterializer->content($element), 'className' => SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS)))
            : $this->tree->children($element, $fallbacks, $captureUnsupported);

        return ConversionOutcome::handled($this->layoutShells->blockForElements(array($element), $children, $element));
    }

    private function isInList(DOMElement $element): bool
    {
        $parent = $element->parentNode;

        return $parent instanceof DOMElement && in_array(strtolower($parent->tagName), array( 'ul', 'ol', 'menu' ), true);
    }
}
