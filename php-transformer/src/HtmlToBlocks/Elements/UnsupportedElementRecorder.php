<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/**
 * Terminal step of the element dispatch chain: the element mapped to nothing
 * native, so either generate a static-render custom block for it or record a
 * `html_unsupported_element` fallback diagnostic.
 *
 * Producer link (issue #497): this is the core/html fallback decision. When the
 * structural classifier identifies the subtree as a high-confidence
 * `custom_block`, a generated block is emitted instead of raw core/html.
 */
final class UnsupportedElementRecorder
{
    public function __construct(
        private readonly UnsupportedElementContext $context,
        private readonly FormControlMetadataBuilder $formControlMetadataBuilder
    ) {
    }

    /**
     * Record the unsupported element.
     *
     * Returns a generated component block when one could be produced, otherwise
     * appends a fallback diagnostic and returns null so the caller drops the
     * element.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function record(DOMElement $element, string $tagName, array &$fallbacks): ?array
    {
        $generated = $this->context->maybeGenerateCustomBlock($element);
        if ( null !== $generated ) {
            return $this->context->generatedComponentBlock($generated, $element);
        }

        $fallbacks[] = $this->context->buildFallbackDiagnostic($this->fallbackRow($element, $tagName));

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackRow(DOMElement $element, string $tagName): array
    {
        $fallback = array(
            'type'            => 'unsupported_element',
            'reason'          => 'unsupported_element',
            'diagnostic_code' => 'html_unsupported_element',
            'source_format'   => 'html',
            'tag'             => $tagName,
            'selector'        => $this->context->elementSelector($element),
            'attributes'      => $this->context->htmlAttributes($element),
            'context'         => $this->context->sourceContext($element),
            'classification'  => $this->context->classifyFallbackSubtree($element),
            'events'          => $this->context->eventMetadata($element),
            'text_length'     => strlen(trim($element->textContent ?? '')),
            'child_count'     => $this->context->childElementCount($element),
            'html'            => $this->context->safeFallbackHtml($element),
        );

        $control = $this->formControlMetadataBuilder->control($element);
        if ( array() !== $control ) {
            $fallback['control'] = $control;
        }

        return $fallback;
    }
}
