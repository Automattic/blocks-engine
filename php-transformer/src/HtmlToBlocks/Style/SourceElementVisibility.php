<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Whether a source element starts hidden: the narrow check on its own
 * unconditional declarations, and the reference-viewport check across its
 * ancestors.
 */
final class SourceElementVisibility
{
    public function __construct(
        private readonly StyleResolver $styleResolver
    ) {
    }

    /**
     * Whether any trigger of a captured dialog can show at some viewport: no
     * element from the trigger up declares `display:none` at its base without
     * a conditional value that shows it, and none carries `hidden`.
     */
    private function capturedDialogHasReachableTrigger(DOMElement $dialog): bool
    {
        $document = $dialog->ownerDocument;
        foreach ( SourceDom::tokens(SourceDom::attr($dialog, 'data-blocks-engine-triggers')) as $id ) {
            $trigger = '' !== $id && null !== $document ? $document->getElementById($id) : null;
            if ( ! $trigger instanceof DOMElement ) {
                continue;
            }
            $reachable = true;
            for ( $node = $trigger; $node instanceof DOMElement && 'body' !== strtolower($node->tagName); $node = $node->parentNode ) {
                $display = $this->styleResolver->declaredPresentation($node, 'display');
                $base = CssValueInspector::comparable($display->base());
                $shownSomewhere = array() !== array_filter($display->conditional(), static fn (string $value): bool => 'none' !== CssValueInspector::comparable($value));
                if ( $node->hasAttribute('hidden') || ( 'none' === $base && ! $shownSomewhere ) ) {
                    $reachable = false;
                    break;
                }
            }
            if ( $reachable ) {
                return true;
            }
        }

        return false;
    }

    public function startsHidden(DOMElement $element): bool
    {
        if ( SourceDom::isDocumentVariantRoot($element) ) {
            return false;
        }
        // A projected captured dialog is the panel's open state. Its source
        // rest rules hide it until script opens it; the dialog block owns that
        // closed state, so they do not make the dialog hidden content.
        // A dialog none of whose triggers can ever show is still dropped.
        if ( 'dialog' === strtolower($element->tagName) && 'true' === SourceDom::attr($element, 'data-blocks-engine-captured-dialog')
            && $this->capturedDialogHasReachableTrigger($element)
        ) {
            return false;
        }
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        $display = CssValueInspector::declared($declarations, 'display');
        $visibility = CssValueInspector::declared($declarations, 'visibility');
        $opacity = CssValueInspector::declared($declarations, 'opacity');
        return 'none' === $display
            || in_array($visibility, array( 'hidden', 'collapse' ), true)
            || (is_numeric($opacity) && 0.0 === (float) $opacity);
    }

    /**
     * Whether the element, or any ancestor, is hidden AT THE REFERENCE
     * VIEWPORT once its whole authored cascade is resolved — inline style,
     * static rules, AND rules scoped to a media or feature query that applies
     * at that viewport — via {@see \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver::controlSurfaceResolvedStyle()}.
     *
     * `startsHidden()` is the narrow, cheap signal a dozen
     * unrelated call sites need: the element's own UNCONDITIONAL declarations
     * only. That narrowness is wrong twice over for navigation
     * deduplication. First, an off-canvas drawer's `<nav>` usually carries no
     * hidden declaration of its own at all — an ANCESTOR wrapper hides it
     * (`lg:hidden`, i.e. `display:none` inside `@media (min-width:1024px)`).
     * Second, the desktop nav *itself* commonly carries `hidden lg:flex` —
     * `display:none` unconditionally, overridden by a conditional `flex` at
     * the reference viewport — and the narrow, static-only check reads that
     * unconditional `display:none` as "hidden", which would misclassify the
     * one nav that must never be deduplicated away.
     * `controlSurfaceResolvedStyle()` resolves both correctly: the full
     * cascade — inline, static, and reference-viewport-applicable conditional
     * rules together, in specificity and source order — is exactly the
     * "classification signal, not presentation projection" its own doc
     * comment calls for.
     *
     * Left undetected, {@see \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\NavigationBlockNormalizer}
     * cannot tell a genuinely hidden duplicate from the real desktop nav —
     * both would read as equally visible — so the drawer survives as its own
     * navigation block and renders wherever its resolved `overlayMenu`
     * intent puts it, duplicating the primary menu in the document flow.
     */
    public function orAncestorStartsHidden(DOMElement $element): bool
    {
        for (
            $candidate = $element;
            $candidate instanceof DOMElement;
            $candidate = $candidate->parentNode instanceof DOMElement ? $candidate->parentNode : null
        ) {
            if ( SourceDom::isDocumentVariantRoot($candidate) ) {
                return false;
            }
            $declarations = $this->styleResolver->cssDeclarations($this->styleResolver->controlSurfaceResolvedStyle($candidate));
            $display = CssValueInspector::declared($declarations, 'display');
            if ( 'none' === $display ) {
                return true;
            }
            $visibility = CssValueInspector::declared($declarations, 'visibility');
            if ( in_array($visibility, array( 'hidden', 'collapse' ), true) ) {
                return true;
            }
            $opacity = CssValueInspector::declared($declarations, 'opacity');
            if ( is_numeric($opacity) && 0.0 === (float) $opacity ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a wrapper around the element hides it at the collapsed (mobile)
     * reference viewport. The element itself is excluded: a navigation becomes
     * core/navigation, whose own overlay replaces the source's collapsed
     * state, while a wrapper stays a group that keeps hiding it.
     */
    public function wrapperHiddenAtCollapsedViewport(DOMElement $element): bool
    {
        for (
            $candidate = $element->parentNode;
            $candidate instanceof DOMElement && 'body' !== strtolower($candidate->tagName);
            $candidate = $candidate->parentNode
        ) {
            if ( SourceDom::isDocumentVariantRoot($candidate) ) {
                return false;
            }
            $declarations = $this->styleResolver->collapsedViewportDeclarations($candidate, array( 'display', 'visibility' ));
            if ( 'none' === CssValueInspector::declared($declarations, 'display')
                || in_array(CssValueInspector::declared($declarations, 'visibility'), array( 'hidden', 'collapse' ), true) ) {
                return true;
            }
        }

        return false;
    }
}
