<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Lowers a standalone form control to its readable block, or omits it when the form runtime island preserves it. */
final class StandaloneFormControlConverter implements ElementConverter
{
    public function __construct(
        private readonly ReadableFormControlBlockConverter $readableControls,
        private readonly FormRuntimeIslandRecorder $formRuntimeIslands
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        $block = $this->readableControls->convert($element);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }

        return $this->formRuntimeIslands->preserveStandaloneControl($element) ? ConversionOutcome::handled(null) : ConversionOutcome::unhandled();
    }
}
