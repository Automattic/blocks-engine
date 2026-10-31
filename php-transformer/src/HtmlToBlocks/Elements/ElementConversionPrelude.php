<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\MathPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationProvenanceState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStylesheetProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\AddressableInlineLayoutLeaf;
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
     * A menu its script opens in place. Core's generated list and items are
     * transport, so the source's own children stay the menu's layout items
     * and the state classes the script sets on the menu govern alone.
     */
    public const SCRIPT_NAVIGATION_CLASS = 'blocks-engine-script-navigation';

    private function commentContainer(DOMElement $element, string $name, array $children): array
    {
        $block = $this->createBlock->createBlock($name, $this->styleResolver->presentationAttributes($element), $children, $element);
        $open = '<div class="' . htmlspecialchars($element->getAttribute('class'), ENT_QUOTES, 'UTF-8') . '">';
        $block['innerHTML'] = $open . '</div>';
        $block['innerContent'] = array_merge(array($open), array_fill(0, count($children), null), array('</div>'));
        return $block;
    }

    /**
     * @param Closure(DOMElement, array<int, array<string, mixed>>&, bool): array<int, array<string, mixed>> $convertChildren
     * @param Closure(DOMElement, array<int, array<string, mixed>>&, bool): ?array<string, mixed> $convertElement
     * @param Closure(DOMElement): array<string, mixed> $htmlPreservationBlock
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): ?array<string, mixed> $linkedRuntimeControl
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
        private readonly Closure $linkedRuntimeControl,
        private readonly Closure $recognizePatterns,
        private readonly Closure $requiresStandaloneInlineLayoutLeaf,
        private readonly Closure $proofBackedWrapperCoalescing,
        private readonly Closure $layoutGeometryProofFor,
        private readonly Closure $adoptAsSemanticBlock,
        private readonly ?CapturedCollectionConverter $capturedCollection = null
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported): ConversionOutcome
    {
        if (WordPressComments::hasClass($element, 'wp-block-comments')) {
            $children = ($this->convertChildren)($element, $fallbacks, $captureUnsupported);
            return ConversionOutcome::handled($this->commentContainer($element, 'core/comments', $children));
        }
        if (WordPressComments::hasClass($element, 'wp-block-comment-template')) {
            foreach ($element->childNodes as $item) {
                if (!$item instanceof DOMElement || 'li' !== $item->tagName) continue;
                $copy = $item->cloneNode(true);
                $xpath = new \DOMXPath($element->ownerDocument);
                foreach ($xpath->query('.//ol[contains(concat(" ",normalize-space(@class)," ")," children ")]', $copy) as $nested) $nested->parentNode->removeChild($nested);
                $children = ($this->convertChildren)($copy, $fallbacks, $captureUnsupported);
                $block = $this->createBlock->createBlock('core/comment-template', $this->styleResolver->presentationAttributes($element), $children, $element);
                $block['innerContent'] = array_fill(0, count($children), null);
                return ConversionOutcome::handled($block);
            }
            return ConversionOutcome::handled($this->createBlock->createBlock('core/comment-template'));
        }
        foreach (array('comments-title', 'comment-author-name', 'comment-date', 'comment-content', 'comment-reply-link', 'avatar') as $type) {
            if (!WordPressComments::hasClass($element, 'wp-block-' . $type)) continue;
            $attrs = $this->styleResolver->presentationAttributes($element);
            if ('comments-title' === $type) $attrs['level'] = (int) substr($element->tagName, 1) ?: 2;
            if ('avatar' === $type) {
                $image = $element->getElementsByTagName('img')->item(0);
                if ($image instanceof DOMElement && is_numeric($image->getAttribute('width'))) $attrs['size'] = (int) $image->getAttribute('width');
            }
            return ConversionOutcome::handled($this->createBlock->createBlock('core/' . $type, $attrs));
        }
        if (WordPressCommentForm::isReplyHeading($element)) return ConversionOutcome::handled(null);
        if (WordPressCommentForm::matches($element)) {
            // This is a dynamic destination-site primitive. Source post IDs,
            // nonces, and form values must never become block attributes.
            $form = $this->createBlock->createBlock('core/post-comments-form');
            for ($ancestor = $element->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
                if (WordPressComments::hasClass($ancestor, 'wp-block-comments')) return ConversionOutcome::handled($form);
            }
            $comments = $this->createBlock->createBlock('core/comments', array(), array($form));
            $comments['innerHTML'] = '<div class="wp-block-comments"></div>';
            $comments['innerContent'] = array('<div class="wp-block-comments">', null, '</div>');
            return ConversionOutcome::handled($comments);
        }

        $collection = $this->capturedCollection?->convert($element, $tagName, $fallbacks);
        if ($collection?->handled) return $collection;
        $listbox = $this->capturedListbox->convert($element, $tagName, $fallbacks);
        if ( $listbox->handled ) {
            return $listbox;
        }

        if ( 'a' === $tagName && $this->requiresWrappedButtonPreservation($element) ) {
            // One captured runtime control inside the link converts natively
            // (the link stays as its saved wrapper); everything else keeps the
            // wrapped-button preservation the shape has always taken.
            $linked = ($this->linkedRuntimeControl)($element, $fallbacks);
            if ( null !== $linked ) {
                return ConversionOutcome::handled($linked);
            }
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

        if ( $this->runtimeIslands->shouldPreserveRuntimeTable($element) ) {
            $targets = $this->runtimeIslands->runtimeTargetsInSubtree($element, 8);
            $this->runtimeIslands->recordRuntimeIsland($element, 'dom', 'runtime_dom_target', 'client_script_execution', array(
                'target_count' => count($targets),
                'targets' => $targets,
            ));
            return ConversionOutcome::handled(($this->htmlPreservationBlock)($element));
        }

        if ( 'form' === $tagName ) {
            return ConversionOutcome::handled($this->formDispatcher->convert($element, $fallbacks));
        }

        if ( ! $this->containsCapturedProviderForm($element)
            && ! $this->isPortableSrcSwapGallery($element)
            && $this->runtimeIslands->shouldPreserveDataAttributeRuntimeTarget($element)
        ) {
            // A script-addressed menu stays a native navigation block; its hooks
            // render with it. Anything the pattern cannot claim stays an island.
            if ( 'nav' === $tagName ) {
                $navigation = ($this->recognizePatterns)($element, $fallbacks, array( NavigationPattern::class ));
                if ( 'core/navigation' === ($navigation['blockName'] ?? null) ) {
                    if ( 'mobile' !== ($navigation['attrs']['overlayMenu'] ?? 'never') ) {
                        $navigation['attrs']['className'] = SourceDom::mergeClassNames((string) ($navigation['attrs']['className'] ?? ''), self::SCRIPT_NAVIGATION_CLASS);
                    }
                    return ConversionOutcome::handled($navigation);
                }
            }
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
            if ( 'true' === $button->getAttribute(CaptureEvidence::INERT_CONTROL)
                && in_array($attribute, array( 'aria-controls', 'aria-expanded', 'data-action', 'jsaction', 'onclick', 'onchange', 'onsubmit' ), true) ) {
                continue;
            }
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
        // A lone addressable span becomes the paragraph's own native anchor.
        // RichText discards unknown <span id> wrappers when its text is edited;
        // keeping the ID on core/paragraph lets the edit survive save/reload.
        // A text-only span is the paragraph itself: the paragraph takes the
        // span's class and presentation, and the synthetic-paragraph inline
        // box lays it out as the span did. A boxless carrier around the span
        // cannot take focus, so the editor could not select its text.
        if (AddressableInlineLayoutLeaf::isPlainTextSpan($element)) {
            $attrs = $this->styleResolver->presentationAttributes($element);
            $attrs['content'] = SourceDom::innerHtml($element);
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS);
            ($this->adoptAsSemanticBlock)($element);
            return $this->createBlock->createBlock('core/paragraph', $attrs, array(), $element);
        }
        $identity = AddressableInlineLayoutLeaf::identity($element, $this->styleResolver);
        $content = null !== $identity ? SourceDom::innerHtml($element) : SourceDom::outerHtml($element);
        $inlineSvgContent = $this->richTextMaterializer->contentWithMaterializedSvgImages($element, $content);
        if ( null !== $inlineSvgContent ) {
            $content = $inlineSvgContent;
        }
        if ( '' === trim($this->runtime->stripAllTags($content)) || $this->richTextMaterializer->requiresHtmlFallbackWithoutNativeSvgImageObjects($content) ) {
            return null;
        }

        $className = AuthorStylesheetProjector::INLINE_LAYOUT_CARRIER_CLASS;
        if (null !== $identity) {
            $className .= ' blocks-engine-addressable-inline-' . ('inline-block' === $identity['display'] ? 'block' : 'text');
            $className .= ' ' . $identity['class_name'];
        }
        $attrs = array(
            'className' => trim($className),
            'content' => $content,
            'preserveInlineLayoutLeaf' => true,
        );
        if (null !== $identity) $attrs['anchor'] = $identity['id'];
        return $this->createBlock->createBlock('core/paragraph', $attrs);
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

    private function isPortableSrcSwapGallery(DOMElement $element): bool
    {
        if (!$element->hasAttribute(CaptureEvidence::GALLERY) || !$element->hasAttribute(CaptureEvidence::GALLERY_SEQUENCE)) {
            return false;
        }
        $sequence = json_decode($element->getAttribute(CaptureEvidence::GALLERY_SEQUENCE), true);
        if (!is_array($sequence) || count($sequence) < 2) {
            return false;
        }
        foreach ($sequence as $url) {
            if (!is_string($url) || '' === trim($url)) return false;
        }
        foreach ($element->getElementsByTagName('img') as $image) {
            if ($image instanceof DOMElement && preg_match('/^-?[0-9]+$/D', trim($image->getAttribute(CaptureEvidence::GALLERY_SLOT)))) {
                return true;
            }
        }
        return false;
    }
}
