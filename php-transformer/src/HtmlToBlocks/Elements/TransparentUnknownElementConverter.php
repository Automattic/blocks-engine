<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Lowers a transparent unknown element. A hyphenated custom element is an
 * authored component: when the classifier calls its subtree a cohesive custom
 * block, it still becomes the generated block the unsupported recorder
 * produced. An unknown non-hyphenated name (`bdt`) is never a component.
 */
final class TransparentUnknownElementConverter implements ElementConverter
{
    public function __construct(
        private readonly RenderNeutralElements $renderNeutral,
        private readonly HtmlTransformerSession $session,
        private readonly GeneratedComponentLowering $generatedComponents,
        private readonly FlowContainerElementConverter $flowContainers,
        private readonly SourceBlockCreator $createBlock,
        private readonly BlockFactory $blockFactory
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ( ! WrapperDecision::transparentUnknownElement($this->renderNeutral, $element)->isFold() ) {
            return ConversionOutcome::unhandled();
        }

        $generated = str_contains($element->tagName, '-') ? $this->session->fallbackEmitter()->maybeGenerateCustomBlock($element, $this->session->requireGeneratedBlockRegistry()) : null;
        if ( null !== $generated ) {
            return ConversionOutcome::handled($this->generatedComponents->generatedBlock($generated, $element));
        }

        $block = $this->flowContainers->convertUnknownElement($element, $fallbacks)->block;
        $landmark = $this->renderNeutral->namedLandmark($element);
        if ( null !== $landmark && null !== $block ) {
            // The landmark is preserved, not dropped: the host's own group
            // carries its matching tag and accessible name. If lowering did not
            // yield a group for the host, wrap the result in one.
            if ( 'core/group' !== ($block['blockName'] ?? '') || ! $this->isHostBlock($block, $element) ) {
                $block = $this->createBlock->createBlock('core/group', array(), array( $block ), $element);
            }
            $block = $this->blockFactory->rebuild($block, array_merge($block['attrs'] ?? array(), $landmark));
        }

        return ConversionOutcome::handled($block);
    }

    /**
     * Whether the lowered block is the host's own group rather than a single
     * child that lowering promoted in its place. The host's own group carries
     * its anchor or every one of its classes.
     *
     * @param array<string, mixed> $block
     */
    private function isHostBlock(array $block, DOMElement $element): bool
    {
        $attrs = $block['attrs'] ?? array();
        if ( ! in_array((string) ($attrs['tagName'] ?? 'div'), array( '', 'div' ), true) || '' !== (string) ($attrs['ariaLabel'] ?? '') ) {
            return false;
        }
        $id = trim(SourceDom::attr($element, 'id'));
        if ( '' !== $id ) {
            return $id === (string) ($attrs['anchor'] ?? '');
        }
        $classes = SourceDom::classNames($element);
        if ( array() === $classes ) {
            // A bare host's group carries no identity of its own either.
            return '' === (string) ($attrs['anchor'] ?? '') && '' === (string) ($attrs['className'] ?? '');
        }
        return array() === array_diff($classes, SourceDom::tokens((string) ($attrs['className'] ?? '')));
    }
}
