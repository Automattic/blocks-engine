<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Converts description lists and their term/detail children. */
final class DescriptionListElementConverter implements ElementConverter
{
    public function __construct(
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier $sourceElementClassifier,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator $createBlock,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver $presentationResolver,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterialization $richTextMaterializer,
        private readonly DescriptionListElementContext $context)
    {
    }

    public function handles(string $tagName): bool
    {
        return in_array($tagName, array( 'dl', 'dt', 'dd' ), true);
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ( ! $this->handles($tagName) ) {
            return ConversionOutcome::unhandled();
        }

        if ( 'dl' === $tagName ) {
            return ConversionOutcome::handled($this->convertDescriptionList($element, $fallbacks));
        }

        if ( 'dd' === $tagName && $this->sourceElementClassifier->hasBlockContentChildren($element) ) {
            $children = $this->context->convertChildren($element, $fallbacks, true);
            if ( array() !== $children ) {
                return ConversionOutcome::handled($this->createBlock->createBlock(
                    'core/group',
                    $this->presentationResolver->presentationAttributes($element),
                    $children,
                    $element
                ));
            }
        }

        $content = $this->richTextMaterializer->content($element);
        if ( ! $this->context->hasVisibleText($content) ) {
            return ConversionOutcome::handled(null);
        }

        return ConversionOutcome::handled($this->createBlock->createBlock(
            'core/paragraph',
            array_merge($this->presentationResolver->presentationAttributes($element), array( 'content' => $content )),
            array(),
            $element
        ));
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    private function convertDescriptionList(DOMElement $element, array &$fallbacks): ?array
    {
        $descriptionList = $this->context->descriptionListBlock($element);
        if ( null !== $descriptionList ) {
            return $descriptionList;
        }

        $metadataGrid = $this->context->metadataGridBlock($element);
        if ( null !== $metadataGrid ) {
            return $metadataGrid;
        }

        $items = $this->context->definitionListItems($element);
        if ( array() !== $items ) {
            $attributes = $this->context->authorLayoutOwnership->isCssOwnedGridElement($element)
                ? $this->context->authorLayoutOwnership->cssOwnedGridAttributes($element)
                : $this->presentationResolver->presentationAttributes($element);

            return $this->createBlock->createBlock('core/list', $attributes, $items, $element);
        }

        $children = $this->context->convertChildren($element, $fallbacks, true);
        if ( array() === $children ) {
            return null;
        }

        return $this->createBlock->createBlock('core/group', $this->presentationResolver->presentationAttributes($element), $children, $element);
    }
}
