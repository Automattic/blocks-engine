<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Materializes the capture-owned listbox contract as an editable native select. */
final class CapturedListboxConverter implements ElementConverter
{
    public function __construct(private readonly AuthoredFormControlBlockConverter $authoredControls)
    {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ('listbox' === strtolower(trim($element->getAttribute('role')))
            && '' !== trim($element->getAttribute('data-dla-listbox-panel'))) {
            return ConversionOutcome::handled(null);
        }
        if ('button' !== $tagName || '' === trim($element->getAttribute('data-dla-listbox-trigger'))) {
            if ('details' !== $tagName) {
                return ConversionOutcome::unhandled();
            }
            $summary = null;
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement && 'summary' === strtolower($child->tagName)) {
                    $summary = $child;
                    break;
                }
            }
            if (!$summary instanceof DOMElement || 0 === $element->getElementsByTagName('*')->length) {
                return ConversionOutcome::unhandled();
            }
            $hasOption = false;
            foreach ($element->getElementsByTagName('*') as $candidate) {
                if ($candidate instanceof DOMElement && 'option' === strtolower(trim($candidate->getAttribute('role')))) {
                    $hasOption = true;
                    break;
                }
            }
            if (!$hasOption) {
                return ConversionOutcome::unhandled();
            }
            $block = $this->authoredControls->listbox($summary, $element);
            return null === $block ? ConversionOutcome::unhandled() : ConversionOutcome::handled($block);
        }

        $key = trim($element->getAttribute('data-dla-listbox-trigger'));
        $document = $element->ownerDocument;
        if (!$document instanceof \DOMDocument) {
            return ConversionOutcome::unhandled();
        }
        $panel = null;
        foreach ($document->getElementsByTagName('*') as $candidate) {
            if ($candidate instanceof DOMElement
                && $candidate->getAttribute('data-dla-listbox-panel') === $key
                && 'listbox' === strtolower(trim($candidate->getAttribute('role')))) {
                $panel = $candidate;
                break;
            }
        }
        if (!$panel instanceof DOMElement) {
            return ConversionOutcome::unhandled();
        }

        $block = $this->authoredControls->listbox($element, $panel);
        return null === $block ? ConversionOutcome::unhandled() : ConversionOutcome::handled($block);
    }
}
