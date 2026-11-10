<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/**
 * Routes a source `table` to a layout projection, a native `core/table`, or a
 * preserved core/html block.
 *
 * The ordering is the contract: layout-table shapes are claimed before the
 * element is offered to data-table classification, because a table used purely
 * for layout must become columns rather than a semantic table.
 */
final class TableElementConverter implements ElementConverter
{
    public function __construct(
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver $presentationResolver,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator $createBlock,
        private readonly TableElementContext $context)
    {
    }

    public function handles(string $tagName): bool
    {
        return 'table' === $tagName;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ( ! $this->handles($tagName) ) {
            return ConversionOutcome::unhandled();
        }

        $policy = $this->context->classificationPolicy();

        if ( $policy->isSingleColumnLayoutTable($element) ) {
            return ConversionOutcome::handled($this->context->singleColumnLayoutTableGroupBlock($element, $fallbacks));
        }

        if ( $policy->isNestedLayoutTableMember($element) ) {
            return ConversionOutcome::handled($this->context->nestedLayoutTableColumnsBlock($element, $fallbacks));
        }

        if ( $policy->isMediaLayoutTable($element) ) {
            return ConversionOutcome::handled($this->context->mediaLayoutTableColumnsBlock($element, $fallbacks));
        }

        if ( $policy->isStructuredLayoutTable($element) ) {
            return ConversionOutcome::handled($this->context->mediaLayoutTableColumnsBlock($element, $fallbacks));
        }

        if ( $policy->isMetadataLayoutTable($element) ) {
            return ConversionOutcome::handled($this->context->mediaLayoutTableColumnsBlock($element, $fallbacks));
        }

        if ( $policy->isPercentLayoutTable($element) ) {
            return ConversionOutcome::handled($this->context->nestedLayoutTableColumnsBlock($element, $fallbacks));
        }

        $classification = $policy->classify($element);
        if ( ! $classification['representable'] ) {
            return ConversionOutcome::handled($this->createBlock->htmlPreservationBlock($element));
        }

        $presentation = $this->presentationResolver->presentationAttributes($element);
        if ( TableAttributesBuilder::isNativeSavedTable($element) && isset($presentation['className']) ) {
            // core/table saves hasFixedLayout as this class; it is an attribute, not an author class.
            $classes = array_values(array_filter(preg_split('/\s+/', (string) $presentation['className']) ?: array(), static fn (string $class): bool => '' !== $class && 'has-fixed-layout' !== $class));
            if ( array() === $classes ) {
                unset($presentation['className']);
            } else {
                $presentation['className'] = implode(' ', $classes);
            }
        }

        return ConversionOutcome::handled(
            $this->createBlock->createBlock(
                'core/table',
                array_merge($presentation, $this->context->tableAttributes($element)),
                array(),
                $element
            )
        );
    }
}
