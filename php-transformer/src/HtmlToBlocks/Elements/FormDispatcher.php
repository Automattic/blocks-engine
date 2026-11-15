<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ListingControlsRecognizer;
use DOMElement;

/** Selects the editable, compositional, or preserved representation of a form. */
final class FormDispatcher
{
    /**
     * Classes added by the contact-form materialization contract, not inherited
     * from source. Declare before lowering so earlier source siblings can carry
     * identity too. Preference-only forms are runtime islands, not provider slots.
     *
     * @return list<string>
     */
    public static function hostRenderedClasses(DOMElement $element): array
    {
        if ( 'form' !== strtolower($element->tagName)
            || in_array(FormRuntimeRequirementAnalyzer::formIntent($element), array('preference', 'search', 'filter', 'login'), true)
            || ! FormControlClassifier::hasDataEntryControls($element) ) {
            return array();
        }
        return array('contact-form', 'commentsblock', 'jetpack-contact-form__form', 'wp-block-jetpack-contact-form');
    }

    /** @param \Closure(DOMElement): ?array<string, mixed>|null $cardPurchaseBlock */
    public function __construct(private readonly FormDispatchContext $context, private readonly SourceBlockCreator $createBlock, private readonly ?\Closure $cardPurchaseBlock = null)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function convert(DOMElement $element, array &$fallbacks): ?array
    {
        if ( 0 === $element->childElementCount && '' === trim($element->textContent) ) {
            return null;
        }

        $cardPurchase = null !== $this->cardPurchaseBlock ? ($this->cardPurchaseBlock)($element) : null;
        if ( null !== $cardPurchase ) {
            return $cardPurchase;
        }

        // Tool controls (including a lone date input) always use the same
        // editable representation, never the prose-summary/raw-HTML fallback.
        if ( 'client_side_tool' === FormRuntimeRequirementAnalyzer::formIntent($element) ) {
            $readable = $this->context->buildReadableFormBlock($element, true, $fallbacks);
            if ( null !== $readable ) {
                $fallbacks[] = $this->context->buildFallbackFinding($element, $readable);
                $this->context->recordForm($element, $readable);
                return $readable;
            }
        }

        $loginBlock = $this->context->loginBlockFromForm($element);
        if ( null !== $loginBlock ) {
            return $loginBlock;
        }

        $searchBlock = $this->context->searchBlockFromForm($element);
        if ( null !== $searchBlock ) {
            return $searchBlock;
        }

        // Listing bounds keep a form intent finding and listing-control anchors.
        if ( (new ListingControlsRecognizer())->hasNumericBoundsForForm($element) ) {
            $readable = $this->context->buildReadableFormBlock($element, true, $fallbacks);
            if ( null !== $readable ) {
                $fallbacks[] = $this->context->buildFallbackFinding($element, $readable);
                $this->context->recordForm($element, $readable);
                return $readable;
            }
        }
        $nativeGetForm = $this->context->nativeGetFormBlock($element, $fallbacks);
        if ( null !== $nativeGetForm ) {
            return $nativeGetForm;
        }

        // An anchor-wrapped button is invalid interactive nesting, but an
        // omitted type still submits its ancestor form. Do not detach that
        // control into a dead core/button when native form lowering declines it.
        if ( $this->hasAnchorWrappedButton($element) ) {
            return $this->createBlock->htmlPreservationBlock($element);
        }

        // Choice-only state needs runtime behavior, not provider substitution.
        // Reuse the button-only preference path: readable blocks and one island.
        if ( FormRuntimeRequirementAnalyzer::collectsOnlyChoices($element) ) {
            $readableFormBlock = $this->context->buildReadableFormBlock($element, true, $fallbacks);
            if ( null !== $readableFormBlock ) {
                $this->context->recordForm($element, $readableFormBlock);
                return $readableFormBlock;
            }
        }

        $hasChoiceGroups = FormControlClassifier::hasCapturedChoiceGroups($element);
        // Nested wrapping labels have a native source-owned subtree even
        // when their control has no prose summary (for example a date input).
        $nativeWrappingLabel = false;
        foreach ($element->getElementsByTagName('label') as $label) {
            foreach (FormControlClassifier::controlElements($label) as $control) {
                if (!$control->parentNode->isSameNode($label)) $nativeWrappingLabel = true;
            }
        }
        $summarizable = FormControlClassifier::controlsHaveProseSummaries($element);
        if ( $summarizable && ( FormControlClassifier::hasDataEntryControls($element) || $hasChoiceGroups ) ) {
            $composition = $this->context->compose($element, $fallbacks);
            if ( null !== $composition ) {
                // The composed block already reported this subtree's findings;
                // the readable tree is only the provider substitution input.
                $readableFallbacks = array();
                $readable = $this->context->buildReadableFormBlock($element, true, $readableFallbacks);
                $fallbacks[] = $this->context->buildFallbackFinding($element, $readable, $composition['slot']);
                $this->context->recordForm($element, $composition['block']);
                return $composition['block'];
            }
        }

        if (!$summarizable && $nativeWrappingLabel) {
            $readable = $this->context->buildReadableFormBlock($element, true, $fallbacks);
            if (null !== $readable) {
                $fallbacks[] = $this->context->buildFallbackFinding($element, $readable);
                $this->context->recordForm($element, $readable);
                return $readable;
            }
        }

        $readableFormBlock = $this->context->buildReadableFormBlock($element, false, $fallbacks);
        if ( null !== $readableFormBlock && ! $this->context->requiresPreservation($element) && $summarizable ) {
            if ( FormControlClassifier::hasDataEntryControls($element) || $hasChoiceGroups ) {
                $fallbacks[] = $this->context->buildFallbackFinding($element, $readableFormBlock);
            }

            return $readableFormBlock;
        }

        if ( FormControlClassifier::hasDataEntryControls($element) || $hasChoiceGroups ) {
            $preservationBlock = $this->createBlock->htmlPreservationBlock($element);
            $fallbacks[] = $this->context->buildFallbackFinding($element, $readableFormBlock, $preservationBlock);
            $this->context->recordForm($element, $readableFormBlock);

            return $preservationBlock;
        }

        $readableFormBlock = $this->context->buildReadableFormBlock($element, true, $fallbacks);
        $this->context->recordForm($element, $readableFormBlock);

        // Surface a finding so consumers can map the preserved controls onto a provider.
        if ( null === $readableFormBlock || FormControlClassifier::hasDataEntryControls($element) || $hasChoiceGroups ) {
            $fallbacks[] = $this->context->buildFallbackFinding($element, $readableFormBlock);
        }

        return $readableFormBlock;
    }

    private function hasAnchorWrappedButton(DOMElement $form): bool
    {
        foreach ( $form->getElementsByTagName('button') as $button ) {
            if ( ! $button instanceof DOMElement ) {
                continue;
            }
            for ( $ancestor = $button->parentNode; $ancestor instanceof DOMElement && $ancestor !== $form; $ancestor = $ancestor->parentNode ) {
                if ( 'a' === strtolower($ancestor->tagName) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function capturePseudoFormFallback(DOMElement $element, array &$fallbacks): void
    {
        if ( $this->context->isProviderPseudoForm($element) ) {
            $fallbacks[] = $this->context->buildFallbackFinding($element, $this->context->buildReadableFormBlock($element, true));
        }
    }
}
