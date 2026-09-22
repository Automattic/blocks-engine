<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\MathPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationProvenanceState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStylesheetProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\StyleTagScanner;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Closure;
use DOMElement;

/**
 * Owns convertElement special-cases that run before ordered registry dispatch.
 *
 * Collaborators take existing converters, suppressors, and resolvers. Remaining
 * HtmlCompilation operations are explicit constructor closures — not a Context bag.
 */
final class ElementConversionPrelude
{
    /**
     * @param Closure(DOMElement, array<int, array<string, mixed>>&, bool): array<int, array<string, mixed>> $convertChildren
     * @param Closure(DOMElement, array<int, array<string, mixed>>&, bool): ?array<string, mixed> $convertElement
     * @param Closure(DOMElement): array<string, mixed> $htmlPreservationBlock
     * @param Closure(DOMElement, array<int, array<string, mixed>>&, array<int, class-string>): ?array<string, mixed> $recognizePatterns
     * @param Closure(DOMElement): bool $requiresStandaloneInlineLayoutLeaf
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): ?array<string, mixed> $proofBackedWrapperCoalescing
     * @param Closure(DOMElement): ?array<string, mixed> $layoutGeometryProofFor
     */
    public function __construct(
        private readonly NativeGetFormControlConverter $nativeGetFormControls,
        private readonly InertScaffoldingSuppressor $inertScaffolding,
        private readonly ProjectedNavigationConverter $projectedNavigation,
        private readonly PhrasingSvgConverter $phrasingSvg,
        private readonly CapturedDialogConverter $capturedDialog,
        private readonly CapturedChoiceGroupConverter $capturedChoiceGroup,
        private readonly CapturedListboxConverter $capturedListbox,
        private readonly CapturedSelectableSetConverter $capturedSelectableSet,
        private readonly ScrollStateConverter $scrollState,
        private readonly ThemeToggleConverter $themeToggle,
        private readonly CopyToClipboardConverter $copyToClipboard,
        private readonly FormDispatcher $formDispatcher,
        private readonly SearchBlockConverter $searchBlockConverter,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly StyleResolver $styleResolver,
        private readonly SourceBlockCreator $createBlock,
        private readonly RichTextMaterializer $richTextMaterializer,
        private readonly Runtime $runtime,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly TransformationProvenanceState $provenance,
        private readonly Closure $convertChildren,
        private readonly Closure $convertElement,
        private readonly Closure $htmlPreservationBlock,
        private readonly Closure $recognizePatterns,
        private readonly Closure $requiresStandaloneInlineLayoutLeaf,
        private readonly Closure $proofBackedWrapperCoalescing,
        private readonly Closure $layoutGeometryProofFor
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported): ConversionOutcome
    {
        $listbox = $this->capturedListbox->convert($element, $tagName, $fallbacks);
        if ( $listbox->handled ) {
            return $listbox;
        }

        if ( 'a' === $tagName && $this->requiresWrappedButtonPreservation($element) ) {
            return ConversionOutcome::handled(($this->htmlPreservationBlock)($element));
        }

        $nativeForm = $this->nativeGetFormControls->convert($element, $tagName, $fallbacks);
        if ( $nativeForm->handled ) {
            return $nativeForm;
        }

        $inert = $this->inertScaffolding->convert($element, $tagName, $fallbacks);
        if ( $inert->handled ) {
            return $inert;
        }

        if ( 'span' === $tagName && 0 < $element->getElementsByTagName('form')->length ) {
            $children = ($this->convertChildren)($element, $fallbacks, $captureUnsupported);
            return ConversionOutcome::handled(array() === $children ? null : $this->createBlock->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element));
        }

        if ( ($this->requiresStandaloneInlineLayoutLeaf)($element) && $this->authorLayoutLeafSupportsRichText($element) ) {
            $leaf = $this->inlineLayoutCarrierBlock($element);
            if ( null !== $leaf ) {
                return ConversionOutcome::handled($leaf);
            }
        }

        $formControlSlotToken = $this->provenance->formControlSlotToken($element->getNodePath());
        if ( null !== $formControlSlotToken ) {
            $path = $element->getNodePath();
            $this->provenance->releaseFormControlSlot($path);
            try {
                $block = ($this->convertElement)($element, $fallbacks, $captureUnsupported);
            } finally {
                $this->provenance->restoreFormControlSlot($path, $formControlSlotToken);
            }
            if ( null === $block ) {
                return ConversionOutcome::handled(null);
            }
            $block['_binding_token'] = $formControlSlotToken;
            return ConversionOutcome::handled($block);
        }

        $projected = $this->projectedNavigation->convert($element, $tagName, $fallbacks);
        if ( $projected->handled ) {
            return $projected;
        }

        $phrasingSvg = $this->phrasingSvg->convert($element, $tagName, $fallbacks);
        if ( $phrasingSvg->handled ) {
            return $phrasingSvg;
        }

        $dialog = $this->capturedDialog->convert($element, $tagName, $fallbacks);
        if ( $dialog->handled ) {
            return $dialog;
        }

        $choiceGroup = $this->capturedChoiceGroup->convert($element, $tagName, $fallbacks);
        if ( $choiceGroup->handled ) {
            return $choiceGroup;
        }

        $selectableSet = $this->capturedSelectableSet->convert($element, $tagName, $fallbacks);
        if ( $selectableSet->handled ) {
            return $selectableSet;
        }

        $scrollState = $this->scrollState->convert($element, $tagName, $fallbacks);
        if ( $scrollState->handled ) {
            return $scrollState;
        }

        $themeToggle = $this->themeToggle->convert($element, $tagName, $fallbacks);
        if ( $themeToggle->handled ) {
            return $themeToggle;
        }

        $copyToClipboard = $this->copyToClipboard->convert($element, $tagName, $fallbacks);
        if ( $copyToClipboard->handled ) {
            return $copyToClipboard;
        }

        if ( 'form' === $tagName ) {
            return ConversionOutcome::handled($this->formDispatcher->convert($element, $fallbacks));
        }

        if ( ! $this->containsCapturedProviderForm($element) && $this->runtimeIslands->shouldPreserveDataAttributeRuntimeTarget($element) ) {
            return ConversionOutcome::handled(($this->htmlPreservationBlock)($element));
        }

        if ( ('div' === $tagName || str_contains($tagName, '-')) && null !== ($this->layoutGeometryProofFor)($element) ) {
            $proofBacked = ($this->proofBackedWrapperCoalescing)($element, $fallbacks);
            if ( null !== $proofBacked ) {
                return ConversionOutcome::handled($proofBacked);
            }
        }

        if ( 'link' === $tagName ) {
            return ConversionOutcome::handled(null);
        }

        if ( 'style' === $tagName && StyleTagScanner::isCssType(SourceDom::attr($element, 'type')) ) {
            return ConversionOutcome::handled(null);
        }

        $standaloneSearchTrigger = $this->searchBlockConverter->searchBlockFromStandaloneTrigger($element);
        if ( null !== $standaloneSearchTrigger ) {
            return ConversionOutcome::handled($standaloneSearchTrigger);
        }

        $mathBlock = ($this->recognizePatterns)($element, $fallbacks, array(MathPattern::class));
        if ( null !== $mathBlock ) {
            return ConversionOutcome::handled($mathBlock);
        }

        return ConversionOutcome::unhandled();
    }

    public function responsiveNavigationToggleMarker(DOMElement $navigation): string
    {
        return $this->projectedNavigation->responsiveNavigationToggleMarker($navigation);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>
     */
    public function capturedDialogBlock(DOMElement $element, array &$fallbacks): array
    {
        return $this->capturedDialog->block($element, $fallbacks);
    }

    private function requiresWrappedButtonPreservation(DOMElement $anchor): bool
    {
        $button = null;
        foreach ( $anchor->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement || 'button' !== strtolower($child->tagName) || $button instanceof DOMElement ) {
                return false;
            }
            $button = $child;
        }
        if ( ! $button instanceof DOMElement || ! ($button->hasAttribute('class') || $button->hasAttribute('id') || $button->hasAttribute('style')) ) {
            return false;
        }

        $type = strtolower(trim($button->getAttribute('type')));
        if ( ! in_array($type, array( '', 'button' ), true) ) {
            return true;
        }
        if ( '' === $type && SourceDom::hasAncestorTag($button, array( 'form' )) ) {
            return true;
        }

        foreach ( array( 'disabled', 'form', 'formaction', 'formenctype', 'formmethod', 'formnovalidate', 'formtarget', 'popovertarget', 'popovertargetaction', 'command', 'commandfor', 'aria-controls', 'aria-expanded', 'data-action', 'jsaction', 'onclick', 'onchange', 'onsubmit' ) as $attribute ) {
            if ( $button->hasAttribute($attribute) ) {
                return true;
            }
        }

        return false;
    }

    private function authorLayoutLeafSupportsRichText(DOMElement $element): bool
    {
        $supports = function (DOMElement $candidate) use (&$supports): bool {
            $tag = strtolower($candidate->tagName);
            if ( in_array($tag, array( 'img', 'svg' ), true) ) {
                return true;
            }
            if ( 'br' !== $tag && ! $this->sourceElementClassifier->isInlineContentElement($tag) ) {
                return false;
            }
            foreach ( $candidate->childNodes as $child ) {
                if ( $child instanceof DOMElement && ! $supports($child) ) {
                    return false;
                }
            }
            return true;
        };

        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && ! $supports($child) ) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed>|null */
    private function inlineLayoutCarrierBlock(DOMElement $element): ?array
    {
        $content = SourceDom::outerHtml($element);
        $inlineSvgContent = $this->richTextMaterializer->contentWithMaterializedSvgImages($element, $content);
        if ( null !== $inlineSvgContent ) {
            $content = $inlineSvgContent;
        }
        if ( '' === trim($this->runtime->stripAllTags($content)) || $this->richTextMaterializer->requiresHtmlFallbackWithoutNativeSvgImageObjects($content) ) {
            return null;
        }

        return $this->createBlock->createBlock('core/paragraph', array(
            'className' => AuthorStylesheetProjector::INLINE_LAYOUT_CARRIER_CLASS,
            'content' => $content,
            'preserveInlineLayoutLeaf' => true,
        ));
    }

    private function containsCapturedProviderForm(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('form') as $form ) {
            if ( '' !== trim(SourceDom::attr($form, 'data-ux')) ) {
                return true;
            }
        }

        return false;
    }
}
