<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Lowers a render-neutral custom flow wrapper the way an unknown flow element lowers. */
final class RenderNeutralFlowWrapperConverter implements ElementConverter
{
    public function __construct(
        private readonly RenderNeutralElements $renderNeutral,
        private readonly FlowContainerElementConverter $flowContainers
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        return $this->renderNeutral->isRenderNeutralCustomFlowWrapper($element)
            ? ConversionOutcome::handled($this->flowContainers->convertUnknownElement($element, $fallbacks)->block)
            : ConversionOutcome::unhandled();
    }
}
