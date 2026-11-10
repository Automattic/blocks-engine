<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use DOMElement;

/** Lowers a `label` to its readable control block, or to a container when it wraps a control the page cannot represent. */
final class LabelElementConverter implements ElementConverter
{
    public function __construct(
        private readonly ReadableFormControlBlockConverter $readableControls,
        private readonly FlowContainerElementConverter $flowContainers
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ( 'label' !== $tagName ) {
            return ConversionOutcome::unhandled();
        }
        $label = $this->readableControls->convert($element);
        // A label wrapping a control the page cannot represent (a hidden
        // file picker, say) is still the visible box around its content.
        // Lower it as that container: its content stays native blocks and
        // the control reports through standalone control handling.
        if ( (null !== $label && 'core/html' !== ($label['blockName'] ?? null)) || ! $this->wrapsUnrepresentableControl($element) ) {
            return ConversionOutcome::handled($label);
        }

        return ConversionOutcome::handled($this->flowContainers->convertGenericContainer($element, $fallbacks)->block);
    }

    /** Whether the label wraps a control with no page representation: unrendered or without a readable summary. */
    private function wrapsUnrepresentableControl(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( FormControlClassifier::isControlElement($descendant)
                && (FormControlClassifier::isNonAuthoredControl($descendant) || ! FormControlClassifier::isReadableControl($descendant)) ) {
                return true;
            }
        }

        return false;
    }
}
