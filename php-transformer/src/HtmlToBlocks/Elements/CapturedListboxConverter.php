<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use DOMElement;

/** Materializes the capture-owned listbox contract as an editable native select. */
final class CapturedListboxConverter implements ElementConverter
{
    public function __construct(private readonly AuthoredFormControlBlockConverter $authoredControls)
    {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ('listbox' === SourceDom::role($element)
            && '' !== trim($element->getAttribute(CaptureEvidence::LISTBOX_PANEL))) {
            return ConversionOutcome::handled(null);
        }
        if ('button' !== $tagName || '' === trim($element->getAttribute(CaptureEvidence::LISTBOX_TRIGGER))) {
            return ConversionOutcome::unhandled();
        }

        $block = $this->authoredControls->listbox($element);
        return null === $block ? ConversionOutcome::unhandled() : ConversionOutcome::handled($block);
    }
}
