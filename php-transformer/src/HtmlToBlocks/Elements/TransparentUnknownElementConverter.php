<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
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
        private readonly FlowContainerElementConverter $flowContainers
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ( ! $this->renderNeutral->isTransparentUnknownElement($element) ) {
            return ConversionOutcome::unhandled();
        }

        $generated = str_contains($element->tagName, '-') ? $this->session->fallbackEmitter()->maybeGenerateCustomBlock($element, $this->session->requireGeneratedBlockRegistry()) : null;
        if ( null !== $generated ) {
            return ConversionOutcome::handled($this->generatedComponents->generatedBlock($generated, $element));
        }

        return ConversionOutcome::handled($this->flowContainers->convertUnknownElement($element, $fallbacks)->block);
    }
}
