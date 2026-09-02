<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use DOMElement;

/** Selects the editable, compositional, or preserved representation of a form. */
final class FormDispatcher
{
    public function __construct(private readonly FormDispatchContext $context)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function convert(DOMElement $element, array &$fallbacks): ?array
    {
        $searchBlock = $this->context->searchBlockFromForm($element);
        if ( null !== $searchBlock ) {
            return $searchBlock;
        }

        if ( FormControlClassifier::hasDataEntryControls($element) ) {
            $composition = $this->context->compose($element, $fallbacks);
            if ( null !== $composition ) {
                $fallbacks[] = $this->context->buildFallbackFinding($element, $composition['block'], $composition['slot']);
                $this->context->recordForm($element, $composition['block']);
                return $composition['block'];
            }
        }

        $readableFormBlock = $this->context->buildReadableFormBlock($element);
        if ( null !== $readableFormBlock && ! $this->context->requiresPreservation($element) ) {
            if ( FormControlClassifier::hasDataEntryControls($element) ) {
                $fallbacks[] = $this->context->buildFallbackFinding($element, $readableFormBlock);
            }

            return $readableFormBlock;
        }

        if ( FormControlClassifier::hasDataEntryControls($element) ) {
            $preservationBlock = $this->context->htmlPreservationBlock($element);
            $fallbacks[] = $this->context->buildFallbackFinding($element, $readableFormBlock, $preservationBlock);
            $this->context->recordForm($element, $readableFormBlock);

            return $preservationBlock;
        }

        $readableFormBlock = $this->context->buildReadableFormBlock($element, true);
        $this->context->recordForm($element, $readableFormBlock);

        // Surface a finding so consumers can map the preserved controls onto a provider.
        if ( null === $readableFormBlock || FormControlClassifier::hasDataEntryControls($element) ) {
            $fallbacks[] = $this->context->buildFallbackFinding($element, $readableFormBlock);
        }

        return $readableFormBlock;
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function capturePseudoFormFallback(DOMElement $element, array &$fallbacks): void
    {
        if ( $this->context->isPseudoForm($element) ) {
            $fallbacks[] = $this->context->buildFallbackFinding($element, $this->context->buildReadableFormBlock($element, true));
        }
    }
}
