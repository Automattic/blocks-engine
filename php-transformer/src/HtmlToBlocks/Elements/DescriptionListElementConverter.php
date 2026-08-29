<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Converts description lists and their term/detail children. */
final class DescriptionListElementConverter
{
    public function __construct(private readonly DescriptionListElementContext $context)
    {
    }

    public function handles(string $tagName): bool
    {
        return in_array($tagName, array( 'dl', 'dt', 'dd' ), true);
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( ! $this->handles($tagName) ) {
            return ConversionOutcome::unhandled();
        }

        if ( 'dl' === $tagName ) {
            return ConversionOutcome::handled($this->convertDescriptionList($element, $fallbacks));
        }

        if ( 'dd' === $tagName && $this->context->hasBlockContentChildren($element) ) {
            $children = $this->context->convertChildren($element, $fallbacks, true);
            if ( array() !== $children ) {
                return ConversionOutcome::handled($this->context->createBlock(
                    'core/group',
                    $this->context->presentationAttributes($element),
                    $children,
                    $element
                ));
            }
        }

        $content = $this->context->richTextContent($element);
        if ( ! $this->context->hasVisibleText($content) ) {
            return ConversionOutcome::handled(null);
        }

        return ConversionOutcome::handled($this->context->createBlock(
            'core/paragraph',
            array_merge($this->context->presentationAttributes($element), array( 'content' => $content )),
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
            $attributes = $this->context->isCssOwnedGridElement($element)
                ? $this->context->cssOwnedGridAttributes($element)
                : $this->context->presentationAttributes($element);

            return $this->context->createBlock('core/list', $attributes, $items, $element);
        }

        $children = $this->context->convertChildren($element, $fallbacks, true);
        if ( array() === $children ) {
            return null;
        }

        return $this->context->createBlock('core/group', $this->context->presentationAttributes($element), $children, $element);
    }
}
