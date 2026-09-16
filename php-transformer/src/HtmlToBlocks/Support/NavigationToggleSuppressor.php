<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Decides which source menu toggles and overlay menus are superseded by the
 * emitted core/navigation, and which source nodes the projection suppresses.
 *
 * Extracted from HtmlTransformer as a collaborator rather than a mixin: every
 * dependency it needs from the transformer is declared on
 * {@see NavigationToggleSuppressionContext}, so this class has no $this access
 * to the transformer and can be exercised without constructing one.
 *
 * Projection state lives on the per-transform session rather than on this
 * collaborator, so it resets with every transform.
 */
final class NavigationToggleSuppressor
{
    public function __construct(
        private readonly NavigationToggleSuppressionContext $context,
        private readonly StyleResolver $styleResolver
    ) {
    }
    /**
     * Bind a hidden dialog/menu to its source hamburger before recursive
     * conversion. The responsive core/navigation must occupy the control's
     * layout slot, not the hidden overlay's document position.
     */
    public function collectProjectedNavigationRelationships(DOMElement $root): void
    {
        $elementsById = array();
        foreach ( $root->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && '' !== trim(SourceDom::attr($element, 'id')) ) {
                $elementsById[trim(SourceDom::attr($element, 'id'))] = $element;
            }
        }

        foreach ( $root->getElementsByTagName('*') as $control ) {
            if ( ! $control instanceof DOMElement || $this->isCapturedDialogControl($control) ) {
                continue;
            }
            if ( ! $this->isHamburgerMenuToggleControl($control) && ! $this->isProjectableHashAnchorMenuToggle($control) ) {
                continue;
            }

            foreach ( preg_split('/\s+/', trim(SourceDom::attr($control, 'aria-controls'))) ?: array() as $controlledId ) {
                $target = $elementsById[ltrim($controlledId, '#')] ?? null;
                if ( ! $target instanceof DOMElement || $this->isInsideOwnDisclosurePanel($control, $target) ) {
                    continue;
                }
                $navigation = $target instanceof DOMElement ? $this->hiddenNavigationInControlledTarget($target) : null;
                if ( ! $target instanceof DOMElement || ! $navigation instanceof DOMElement ) {
                    continue;
                }
                $this->recordProjectedNavigationRelationship($control, $target, $navigation);
                break;
            }

            if ( $this->context->navigationProjection()->hasTargetForControl($control) ) {
                continue;
            }

            $relationship = $this->implicitHiddenNavigationRelationship($control);
            if ( null !== $relationship ) {
                if ( $this->hasDialogPopupSemantics($control) ) {
                    $this->context->navigationProjection()->markImplicitDialogControl($control);
                }
                $this->recordProjectedNavigationRelationship($control, $relationship['target'], $relationship['navigation']);
            }
        }
    }

    private function recordProjectedNavigationRelationship(DOMElement $control, DOMElement $target, DOMElement $navigation): void
    {
        if ( $this->context->navigationProjection()->isSuppressed($navigation) ) {
            return;
        }

        $this->context->navigationProjection()->projectTarget($control, $navigation);
        $this->context->navigationProjection()->suppress($target);
        $this->context->navigationProjection()->suppress($navigation);
        if ( $this->isProjectableHashAnchorMenuToggle($control) ) {
            $this->suppressEquivalentNavigationDuplicates($control, $navigation);
        }
    }

    private function suppressEquivalentNavigationDuplicates(DOMElement $control, DOMElement $navigation): void
    {
        $signature = $this->sourceNavigationSignature($navigation);
        if ( '' === $signature ) {
            return;
        }

        $root = $this->documentVariantRoot($control);
        foreach ( $root->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement
                || $candidate->isSameNode($navigation)
                || $this->context->navigationProjection()->isSuppressed($candidate)
                || SourceDom::elementContains($candidate, $control)
                || ! $this->isAssociatedNavigationTarget($candidate)
                || $signature !== $this->sourceNavigationSignature($candidate) ) {
                continue;
            }
            $this->context->navigationProjection()->suppress($candidate);
        }
    }

    private function documentVariantRoot(DOMElement $element): DOMElement
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( 1 === preg_match('/(?:^|\s)data-liberation-(?:desktop|mobile)-document(?:\s|$)/', SourceDom::attr($node, 'class')) ) {
                return $node;
            }
            if ( 'body' === strtolower($node->tagName) ) {
                return $node;
            }
        }

        $document = $element->ownerDocument;

        return $document instanceof DOMDocument && $document->documentElement instanceof DOMElement
            ? $document->documentElement
            : $element;
    }

    private function hasDialogPopupSemantics(DOMElement $control): bool
    {
        return in_array('dialog', preg_split('/\s+/', strtolower(trim(SourceDom::attr($control, 'aria-haspopup')))) ?: array(), true)
            && $control->hasAttribute('aria-expanded');
    }

    /**
     * @return array{target: DOMElement, navigation: DOMElement}|null
     */
    private function implicitHiddenNavigationRelationship(DOMElement $control): ?array
    {
        $depth = 0;
        for ( $scope = $this->menuToggleScope($control); $scope instanceof DOMElement && $depth < 12; $scope = $scope->parentNode, ++$depth ) {
            $dialogCandidates = array();
            $navigationCandidates = array();
            foreach ( $scope->getElementsByTagName('*') as $candidate ) {
                if ( ! $candidate instanceof DOMElement || $candidate->isSameNode($control) ) {
                    continue;
                }

                // A native disclosure's own collapsible panel is the content the
                // details block preserves, never the control's overlay target.
                if ( $this->isInsideOwnDisclosurePanel($control, $candidate) ) {
                    continue;
                }

                if ( $this->isSemanticDialog($candidate) ) {
                    $navigation = $this->hiddenNavigationInControlledTarget($candidate);
                    if ( $navigation instanceof DOMElement ) {
                        $dialogCandidates[] = array('target' => $candidate, 'navigation' => $navigation);
                    }
                    continue;
                }

                // A capture can retain only the initially closed menu while the
                // source creates its dialog after a click. A unique hidden nav
                // in the control's bounded scope is its static counterpart.
                if ( $this->context->navigationProjection()->isSuppressed($candidate) ) {
                    continue;
                }
                $navigation = $this->hiddenNavigationInControlledTarget($candidate);
                if ( $navigation instanceof DOMElement && ! $this->context->navigationProjection()->isSuppressed($navigation) ) {
                    $navigationCandidates[] = array('target' => $candidate, 'navigation' => $navigation);
                }
            }

            if ( 1 === count($dialogCandidates) ) {
                return $dialogCandidates[0];
            }
            if ( 1 < count($dialogCandidates) ) {
                return null;
            }
            if ( 1 === count($navigationCandidates) ) {
                return $navigationCandidates[0];
            }
            if ( 1 < count($navigationCandidates) ) {
                $equivalent = $this->equivalentHiddenNavigationCandidates($navigationCandidates);
                if ( array() !== $equivalent ) {
                    return $this->nearestHiddenNavigationCandidate($control, $equivalent);
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{target: DOMElement, navigation: DOMElement}> $candidates
     * @return list<array{target: DOMElement, navigation: DOMElement}>
     */
    private function equivalentHiddenNavigationCandidates(array $candidates): array
    {
        $grouped = array();
        foreach ( $candidates as $candidate ) {
            $signature = $this->sourceNavigationSignature($candidate['navigation']);
            if ( '' === $signature ) {
                continue;
            }
            $grouped[$signature][] = $candidate;
        }
        foreach ( $grouped as $group ) {
            if ( 1 < count($group) ) {
                return $group;
            }
        }

        return array();
    }

    /**
     * @param list<array{target: DOMElement, navigation: DOMElement}> $candidates
     * @return array{target: DOMElement, navigation: DOMElement}
     */
    private function nearestHiddenNavigationCandidate(DOMElement $control, array $candidates): array
    {
        $best = $candidates[0];
        $bestDistance = $this->elementTreeDistance($control, $best['target']);
        foreach ( $candidates as $index => $candidate ) {
            if ( 0 === $index ) {
                continue;
            }
            $distance = $this->elementTreeDistance($control, $candidate['target']);
            if ( $distance < $bestDistance ) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    private function elementTreeDistance(DOMElement $from, DOMElement $to): int
    {
        $fromAncestors = array();
        $depth = 0;
        for ( $node = $from; $node instanceof DOMElement; $node = $node->parentNode, ++$depth ) {
            $fromAncestors[spl_object_id($node)] = $depth;
        }
        $walk = 0;
        for ( $node = $to; $node instanceof DOMElement; $node = $node->parentNode, ++$walk ) {
            if ( isset($fromAncestors[spl_object_id($node)]) ) {
                return $fromAncestors[spl_object_id($node)] + $walk;
            }
        }

        return PHP_INT_MAX;
    }

    private function hiddenNavigationInControlledTarget(DOMElement $target): ?DOMElement
    {
        if ( ! $this->sourceElementIsHidden($target) ) {
            return null;
        }

        $tagName = strtolower($target->tagName);
        $role = strtolower(SourceDom::attr($target, 'role'));
        $isLandmark = in_array($tagName, array( 'dialog', 'nav' ), true)
            || in_array($role, array( 'dialog', 'alertdialog', 'navigation' ), true);
        if ( ! $isLandmark && ! $this->isAssociatedNavigationTarget($target) ) {
            $inner = null;
            foreach ( $target->getElementsByTagName('*') as $candidate ) {
                if ( $candidate instanceof DOMElement && $this->isAssociatedNavigationTarget($candidate) ) {
                    if ( $inner instanceof DOMElement ) {
                        return null;
                    }
                    $inner = $candidate;
                }
            }
            if ( ! $inner instanceof DOMElement ) {
                return null;
            }
            $target = $inner;
        }

        $candidates = array($target);
        foreach ( $target->getElementsByTagName('*') as $candidate ) {
            if ( $candidate instanceof DOMElement ) {
                $candidates[] = $candidate;
            }
        }
        foreach ( $candidates as $candidate ) {
            if ( $this->isAssociatedNavigationTarget($candidate)
                && '' !== $this->sourceNavigationSignature($candidate)
                && $this->convertsToCoreNavigation($candidate) ) {
                return $candidate;
            }
        }

        return null;
    }

    private function isSemanticDialog(DOMElement $element): bool
    {
        return 'dialog' === strtolower($element->tagName)
            || in_array(strtolower(SourceDom::attr($element, 'role')), array( 'dialog', 'alertdialog' ), true);
    }

    private function sourceElementIsHidden(DOMElement $element): bool
    {
        if ( $this->context->sourceElementStartsHidden($element)
            || $element->hasAttribute('hidden')
            || 'true' === strtolower(SourceDom::attr($element, 'aria-hidden'))
            || 'false' === strtolower(SourceDom::attr($element, 'data-visible')) ) {
            return true;
        }

        $maxHeight = CssValueInspector::comparable(
            (string) ($this->styleResolver->structuralPresentationDeclarations($element)['max-height'] ?? '')
        );

        return in_array($maxHeight, array( '0', '0px' ), true);
    }

    public function projectedNavigationTargetForControl(DOMElement $control): ?DOMElement
    {
        return $this->context->navigationProjection()->targetForControl($control);
    }

    public function isImplicitDialogNavigationControl(DOMElement $control): bool
    {
        return $this->context->navigationProjection()->isImplicitDialogControl($control);
    }

    public function isProjectedNavigationSuppressed(DOMElement $element): bool
    {
        return $this->context->navigationProjection()->isSuppressed($element);
    }

    /**
     * A JS-only hamburger menu-toggle that is redundant chrome whenever it is
     * associated with a source navigation menu — whether or not that menu
     * converts to core/navigation.
     *
     * The toggle is detected GENERICALLY by structural/semantic signals — never
     * by a specific class string — so any framework's hamburger is recognized:
     * a <button> (or <a role="button">) carrying aria-controls and/or
     * aria-expanded whose visible content is empty/decorative bars (only empty
     * spans or an icon, no text label), or an input-free <label> containing a
     * nested stack of CSS-drawn bars. It is suppressed when it opens, lives
     * inside, or sits beside a source navigation menu. A converted menu already
     * ships its own responsive overlay hamburger; a menu that does NOT convert
     * still must not gain an always-visible dead hamburger the source hid behind
     * responsive CSS/JS the importer cannot carry (the "added UI" defect). Real
     * labeled buttons, and toggle-shaped controls with no associated navigation,
     * still convert to core/button normally.
     */
    public function isRedundantMenuToggleControl(DOMElement $element): bool
    {
        if ( $this->isCapturedDialogControl($element) ) {
            return false;
        }

        if ( $this->isProjectableHashAnchorMenuToggle($element) ) {
            if ( $this->context->navigationProjection()->hasTargetForControl($element)
                || ! $this->context->navigationProjection()->hasProjection() ) {
                return false;
            }
        } elseif ( ! $this->isHamburgerMenuToggleControl($element) ) {
            return false;
        }

        // A native disclosure whose panel is still its own content is operable
        // zero-JS UI: its summary toggles the panel the `core/details` block
        // preserves natively, so it can never be the redundant chrome a rebuilt
        // overlay navigation supersedes — dropping it would delete the panel.
        if ( $this->isNativeDisclosureWithPanel($element) ) {
            return false;
        }

        return $this->hasAssociatedNavigationMenu($element);
    }

    /**
     * Whether the element is a native `details` disclosure — or the summary of
     * one — that still carries panel content beyond its summary.
     */
    private function isNativeDisclosureWithPanel(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( 'details' === $tagName ) {
            $details = $element;
        } elseif ( 'summary' === $tagName
            && $element->parentNode instanceof DOMElement
            && 'details' === strtolower($element->parentNode->tagName) ) {
            $details = $element->parentNode;
        } else {
            return false;
        }

        foreach ( $details->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'summary' !== strtolower($child->tagName) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the candidate element lives inside the toggle's own native
     * `details` disclosure: the toggle is the details itself or its summary,
     * and the candidate is a descendant of that same details. A disclosure's
     * own collapsible panel is the content the converted `core/details` block
     * keeps closed until its summary opens it — it can never be an external
     * menu that makes the toggle redundant chrome or a projected overlay
     * target. Without this boundary, a captured disclosure with an icon-only
     * menu summary and a `<nav>`/dialog panel was dropped wholesale (the
     * hamburger read as redundant for its own panel) and the panel was
     * suppressed as an overlay it was never separate from.
     */
    private function isInsideOwnDisclosurePanel(DOMElement $toggle, DOMElement $candidate): bool
    {
        $tagName = strtolower($toggle->tagName);
        if ( 'details' === $tagName ) {
            $details = $toggle;
        } elseif ( 'summary' === $tagName
            && $toggle->parentNode instanceof DOMElement
            && 'details' === strtolower($toggle->parentNode->tagName) ) {
            $details = $toggle->parentNode;
        } else {
            return false;
        }

        for ( $node = $candidate; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $node->isSameNode($details) ) {
                return true;
            }
        }

        return false;
    }

    /** A native disclosure with a captured dialog has its own preservation path. */
    private function isCapturedDialogControl(DOMElement $element): bool
    {
        if ( 'summary' === strtolower($element->tagName) && $element->parentNode instanceof DOMElement ) {
            $element = $element->parentNode;
        }
        if ( 'details' !== strtolower($element->tagName) ) {
            return false;
        }

        $hasSummary = false;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            if ( 'summary' === strtolower($child->tagName) ) {
                $hasSummary = true;
                continue;
            }
            if ( $hasSummary && 'dialog' === strtolower(SourceDom::attr($child, 'role')) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Authoritatively record, in a single deterministic pass over the source
     * document, the selectors made redundant by every hamburger menu-toggle the
     * transformer treats as superseded by native navigation. A redundant
     * menu-toggle is always dropped from the output — whether by the element
     * converter, the navigation pattern's chrome handling, or the buttons
     * container — so scanning the source by the same `isRedundantMenuToggleControl`
     * predicate captures the superseded selectors independently of which drop
     * path executed, with no per-path bookkeeping.
     */
    public function collectSupersededNavToggleSelectors(DOMElement $root): void
    {
        foreach ( $root->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && $this->isRedundantMenuToggleControl($element) ) {
                $this->recordSupersededNavToggleSelectors($element);
            }
        }
    }

    /**
     * Record the source selectors made redundant when a hamburger menu-toggle is
     * dropped in favor of the native navigation overlay: the toggle's own id and
     * class selectors, plus the id/class selectors of the menu/overlay it
     * controlled via `aria-controls`. A preserved site script may still reference
     * these selectors (e.g. `.nav-toggle`, `#nav-mobile`); the runtime-dependency
     * parity report uses this set to mark a resulting "missing DOM target"
     * finding as a superseded, acceptable loss rather than a materialization bug.
     * Only selectors of menu-toggles the transformer actually removed are
     * recorded, so genuinely-broken targets stay flagged.
     */
    private function recordSupersededNavToggleSelectors(DOMElement $toggle): void
    {
        $this->recordSupersededSelectorsForElement($toggle);

        foreach ( preg_split('/\s+/', trim(SourceDom::attr($toggle, 'aria-controls'))) ?: array() as $controlledId ) {
            $controlledId = ltrim(trim($controlledId), '#');
            if ( '' === $controlledId ) {
                continue;
            }

            $this->context->runtimeSelectors()->supersede('#' . $controlledId);

            $target = $this->elementWithId($toggle, $controlledId);
            if ( $target instanceof DOMElement && ! $target->isSameNode($toggle) ) {
                $this->recordSupersededSelectorsForElement($target);
            }
        }

        $nearbyOverlay = $this->nearbyNavigationOverlayForToggle($toggle);
        if ( $nearbyOverlay instanceof DOMElement ) {
            $this->recordSupersededSelectorsForElement($nearbyOverlay);
        }
    }

    private function nearbyNavigationOverlayForToggle(DOMElement $toggle): ?DOMElement
    {
        $container = $toggle->parentNode;
        while ( $container instanceof DOMElement && 'nav' !== strtolower($container->tagName) ) {
            $container = $container->parentNode;
        }

        if ( ! $container instanceof DOMElement ) {
            return null;
        }

        for ( $sibling = $container->nextSibling; null !== $sibling; $sibling = $sibling->nextSibling ) {
            if ( ! $sibling instanceof DOMElement ) {
                continue;
            }

            if ( $this->isNavigationOverlayCandidate($sibling) ) {
                return $sibling;
            }

            if ( in_array(strtolower($sibling->tagName), array('main', 'section', 'article'), true) ) {
                return null;
            }
        }

        return null;
    }

    private function isNavigationOverlayCandidate(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( ! in_array($tagName, array('nav', 'ul', 'ol'), true) ) {
            return false;
        }

        $anchorCount = 0;
        foreach ( $element->getElementsByTagName('a') as $anchor ) {
            if ( $anchor instanceof DOMElement && '' !== trim($anchor->textContent ?? '') ) {
                ++$anchorCount;
            }
        }

        if ( $anchorCount < 2 ) {
            return false;
        }

        $label = strtolower(SourceDom::attr($element, 'aria-label'));
        if ( str_contains($label, 'navigation') || str_contains($label, 'menu') || str_contains($label, 'mobile') ) {
            return true;
        }

        $role = strtolower(SourceDom::attr($element, 'role'));
        return 'navigation' === $role;
    }

    private function recordSupersededSelectorsForElement(DOMElement $element): void
    {
        $id = trim(SourceDom::attr($element, 'id'));
        if ( '' !== $id ) {
            $this->context->runtimeSelectors()->supersede('#' . $id);
        }

        foreach ( preg_split('/\s+/', trim(SourceDom::attr($element, 'class'))) ?: array() as $class ) {
            if ( '' !== $class ) {
                $this->context->runtimeSelectors()->supersede('.' . $class);
            }
        }
    }

    private function isHamburgerMenuToggleControl(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( 'label' === $tagName ) {
            return $this->isNestedHamburgerBarLabel($element) || $this->isCheckboxBoundEmptyLabel($element);
        }
        if ( 'input' === $tagName ) {
            return $this->isCheckboxWithEmptyBoundLabel($element);
        }

        if ( 'details' === $tagName ) {
            $summary = $element->getElementsByTagName('summary')->item(0);
            return $summary instanceof DOMElement
                && $summary->parentNode instanceof DOMElement
                && $summary->parentNode->isSameNode($element)
                && $this->isHamburgerMenuToggleControl($summary);
        }

        if ( 'summary' === $tagName ) {
            $parent = $element->parentNode;
            $accessibleName = strtolower(trim(implode(' ', array(
                SourceDom::attr($element, 'aria-label'),
                SourceDom::attr($element, 'title'),
            ))));
            return $parent instanceof DOMElement
                && 'details' === strtolower($parent->tagName)
                && '' === $this->visibleMenuToggleLabel($element)
                && 1 === preg_match('/(?:^|[^a-z0-9])(?:navigation|nav|menu|hamburger)(?:[^a-z0-9]|$)/', $accessibleName);
        }

        $isButton = 'button' === $tagName;
        $isButtonRoleAnchor = 'a' === $tagName && 'button' === strtolower(SourceDom::attr($element, 'role'));
        if ( ! $isButton && ! $isButtonRoleAnchor ) {
            return false;
        }

        if ( '' !== $this->visibleMenuToggleLabel($element) ) {
            return false;
        }

        // ARIA-toggle shape: a labelless control that opens a menu via ARIA
        // state (aria-controls/aria-expanded), regardless of its icon markup.
        if ( $element->hasAttribute('aria-controls') || $element->hasAttribute('aria-expanded') ) {
            return true;
        }

        // Icon-bars shape: a labelless control whose only content is the stacked
        // empty <span> bars that draw a hamburger glyph, with no ARIA toggle
        // wiring. Many themes draw the bars with CSS on empty spans and bind the
        // open/close behavior in JS the importer cannot carry, so the control
        // arrives with no aria-* hooks at all — only its bar-stack shape betrays
        // it. Recognizing that shape (never a class string) lets these toggles be
        // dropped too, instead of surfacing as an empty, always-visible button.
        return $this->isHamburgerBarStackControl($element);
    }

    private function isCheckboxBoundEmptyLabel(DOMElement $element): bool
    {
        $controlId = trim(SourceDom::attr($element, 'for'));
        if ( '' === $controlId || '' !== $this->visibleMenuToggleLabel($element) ) {
            return false;
        }

        $control = $this->elementWithId($element, $controlId);
        if ( ! $control instanceof DOMElement
            || 'input' !== strtolower($control->tagName)
            || 'checkbox' !== strtolower(SourceDom::attr($control, 'type'))
            || ! $this->hasCheckboxNavigationToggleSignal($element, $control)
        ) {
            return false;
        }

        foreach ( array( $element, $control ) as $candidate ) {
            for ( $node = $candidate->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
                if ( 'form' === strtolower($node->tagName) ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function hasCheckboxNavigationToggleSignal(DOMElement $label, DOMElement $control): bool
    {
        $identity = array();
        foreach ( array( $label, $control ) as $element ) {
            foreach ( array( 'id', 'class', 'aria-label', 'aria-controls', 'title' ) as $attribute ) {
                $identity[] = SourceDom::attr($element, $attribute);
            }
        }

        return 1 === preg_match('/(?:^|[^a-z0-9])(?:navigation|nav|menu|hamburger|drawer|offcanvas)(?:[^a-z0-9]|$)/', strtolower(implode(' ', $identity)));
    }

    private function isCheckboxWithEmptyBoundLabel(DOMElement $element): bool
    {
        $controlId = trim(SourceDom::attr($element, 'id'));
        if ( '' === $controlId || 'checkbox' !== strtolower(SourceDom::attr($element, 'type')) ) {
            return false;
        }

        $document = $element->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return false;
        }

        foreach ( $document->getElementsByTagName('label') as $label ) {
            if ( $label instanceof DOMElement
                && $controlId === trim(SourceDom::attr($label, 'for'))
                && $this->isCheckboxBoundEmptyLabel($label)
            ) {
                return true;
            }
        }

        return false;
    }

    private function isNestedHamburgerBarLabel(DOMElement $element): bool
    {
        if ( $element->hasAttribute('for')
            || 0 !== $element->getElementsByTagName('input')->length
            || 0 !== $element->getElementsByTagName('select')->length
            || 0 !== $element->getElementsByTagName('textarea')->length ) {
            return false;
        }

        foreach ( $element->getElementsByTagName('*') as $container ) {
            if ( ! $container instanceof DOMElement ) {
                continue;
            }

            $bars = 0;
            foreach ( $container->childNodes as $child ) {
                if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                    continue;
                }
                if ( ! $child instanceof DOMElement
                    || ! in_array(strtolower($child->tagName), array( 'div', 'span' ), true)
                    || '' !== trim($child->textContent ?? '')
                    || 0 !== $child->childNodes->length ) {
                    $bars = 0;
                    break;
                }
                ++$bars;
            }
            if ( $bars >= 2 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the control's only content is a stack of two or more empty <span>
     * bars: the framework-agnostic shape of a CSS-drawn hamburger glyph. A real
     * button carries a text label, an image, or other meaningful content, so it
     * is never matched. Genuinely empty controls (no bars) are not matched
     * either; only the deliberate multi-bar stack qualifies.
     */
    private function isHamburgerBarStackControl(DOMElement $element): bool
    {
        $emptyBars = 0;
        foreach ( $element->childNodes as $child ) {
            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }

            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return false;
                }
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                return false;
            }

            if ( 'svg' === strtolower($child->tagName) ) {
                $svgBars = $child->getElementsByTagName('line')->length + $child->getElementsByTagName('rect')->length;
                if ( $svgBars < 2 ) {
                    return false;
                }
                $emptyBars += $svgBars;
                continue;
            }

            if ( 'span' !== strtolower($child->tagName)
                || '' !== trim($child->textContent ?? '')
                || 0 !== $child->getElementsByTagName('img')->length
                || 0 !== $child->getElementsByTagName('svg')->length ) {
                return false;
            }

            ++$emptyBars;
        }

        return $emptyBars >= 2;
    }

    /**
     * Visible text label of a control with decorative chrome (icons, empty
     * hamburger bars) and source-hidden descendants stripped. Empty means the
     * control shows no text label; accessible names remain separate semantics.
     */
    private function visibleMenuToggleLabel(DOMElement $element): string
    {
        $label = '';
        foreach ( $element->childNodes as $child ) {
            $label .= $this->visibleMenuToggleText($child);
        }

        return trim($label);
    }

    private function visibleMenuToggleText(DOMNode $node): string
    {
        if ( XML_TEXT_NODE === $node->nodeType ) {
            return $node->textContent ?? '';
        }

        if ( ! $node instanceof DOMElement
            || 'svg' === strtolower($node->tagName)
            || 'true' === strtolower(SourceDom::attr($node, 'aria-hidden'))
            || $this->hasHiddenDisplay($node) ) {
            return '';
        }

        $text = '';
        foreach ( $node->childNodes as $child ) {
            $text .= $this->visibleMenuToggleText($child);
        }

        return $text;
    }

    private function hasHiddenDisplay(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->cssDeclarations($this->styleResolver->specificityResolvedPresentationStyle($element));
        return 1 === preg_match('/^none(?:\s*!important)?$/i', trim((string) ($declarations['display'] ?? '')));
    }

    /**
     * Whether the toggle is associated with a source navigation menu: it opens
     * one via aria-controls, lives inside a navigation landmark, or sits beside a
     * navigation menu within its enclosing landmark. Association does NOT require
     * the menu to convert to core/navigation — a navbar whose links fail to
     * convert must still drop its dead hamburger rather than emit it as an
     * always-visible core/button.
     */
    private function hasAssociatedNavigationMenu(DOMElement $toggle): bool
    {
        $controlledIds = preg_split('/\s+/', trim(SourceDom::attr($toggle, 'aria-controls'))) ?: array();
        foreach ( $controlledIds as $controlledId ) {
            if ( '' === $controlledId ) {
                continue;
            }

            $target = $this->elementWithId($toggle, $controlledId);
            if ( $target instanceof DOMElement
                && ! $target->isSameNode($toggle)
                && ! $this->isInsideOwnDisclosurePanel($toggle, $target)
                && $this->isAssociatedNavigationTarget($target) ) {
                return true;
            }
        }

        $scope = $this->menuToggleScope($toggle);
        if ( $this->isNavigationLandmark($scope) ) {
            return true;
        }

        foreach ( $scope->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement || $candidate->isSameNode($toggle) ) {
                continue;
            }

            if ( $this->isInsideOwnDisclosurePanel($toggle, $candidate) ) {
                continue;
            }

            if ( $this->isAssociatedNavigationTarget($candidate) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Preserve a responsive overlay only when the source declares equivalent
     * desktop/mobile menus or an associated hamburger control.
     */
    public function navigationOverlayMenu(DOMElement $navigation): string
    {
        if ( $this->hasEquivalentSourceNavigationVariant($navigation) ) {
            return 'mobile';
        }

        return $this->navigationToggleControl($navigation) instanceof DOMElement ? 'mobile' : 'never';
    }

    public function navigationToggleControl(DOMElement $navigation): ?DOMElement
    {

        $document = $navigation->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return null;
        }

        foreach ( $document->getElementsByTagName('*') as $toggle ) {
            if ( ! $toggle instanceof DOMElement
                || $this->isCapturedDialogControl($toggle)
                || ( ! $this->isHamburgerMenuToggleControl($toggle) && ! $this->isProjectableHashAnchorMenuToggle($toggle) )
            ) {
                continue;
            }

            $projectedTarget = $this->projectedNavigationTargetForControl($toggle);
            if ( $projectedTarget instanceof DOMElement ) {
                if ( $projectedTarget->isSameNode($navigation) ) {
                    return $this->concreteToggleControl($toggle);
                }
                continue;
            }

            if ( ! $this->hasAssociatedNavigationMenu($toggle) ) {
                continue;
            }

            if ( SourceDom::elementContains($navigation, $toggle) ) {
                return $this->concreteToggleControl($toggle);
            }

            foreach ( preg_split('/\s+/', trim(SourceDom::attr($toggle, 'aria-controls'))) ?: array() as $controlledId ) {
                $target = '' === $controlledId ? null : $this->elementWithId($toggle, $controlledId);
                if ( $target instanceof DOMElement
                    && (SourceDom::elementContains($target, $navigation) || SourceDom::elementContains($navigation, $target))
                ) {
                    return $this->concreteToggleControl($toggle);
                }
            }

            for ( $container = $toggle->parentNode; $container instanceof DOMElement && 'body' !== strtolower($container->tagName); $container = $container->parentNode ) {
                if ( SourceDom::elementContains($container, $navigation) && $this->isUniqueNavigationInScope($container, $navigation) ) {
                    return $this->concreteToggleControl($toggle);
                }
            }

            $scope = $this->menuToggleScope($toggle);
            if ( 'body' !== strtolower($scope->tagName)
                && SourceDom::elementContains($scope, $navigation)
                && $this->isUniqueNavigationInScope($scope, $navigation) ) {
                return $this->concreteToggleControl($toggle);
            }
        }

        return null;
    }

    private function isUniqueNavigationInScope(DOMElement $scope, DOMElement $navigation): bool
    {
        $candidates = array();
        foreach ( $scope->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement || ! $this->convertsToCoreNavigation($candidate) ) {
                continue;
            }

            $candidates[$candidate->getNodePath()] = $candidate;
        }

        foreach ( $candidates as $path => $candidate ) {
            foreach ( $candidates as $otherPath => $other ) {
                if ( $path !== $otherPath && SourceDom::elementContains($other, $candidate) ) {
                    unset($candidates[$path]);
                    break;
                }
            }
        }

        if ( 1 !== count($candidates) ) {
            return false;
        }

        $candidate = array_values($candidates)[0];
        return $candidate->isSameNode($navigation)
            || SourceDom::elementContains($candidate, $navigation)
            || SourceDom::elementContains($navigation, $candidate);
    }

    private function concreteToggleControl(DOMElement $toggle): DOMElement
    {
        if ( 'details' === strtolower($toggle->tagName) ) {
            $summary = $toggle->getElementsByTagName('summary')->item(0);
            if ( $summary instanceof DOMElement ) {
                return $summary;
            }
        }
        return $toggle;
    }

    private function hasEquivalentSourceNavigationVariant(DOMElement $navigation): bool
    {
        $document = $navigation->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return false;
        }

        $navigationRoot = $this->navigationLandmarkAncestor($navigation) ?? $navigation;
        // A captured details disclosure owns its summary and panel navigation.
        // It remains an independent mobile surface, rather than evidence that a
        // sibling desktop navigation should be replaced by Core's overlay toggle.
        if ( $this->isInsideCapturedDisclosure($navigationRoot) ) {
            return false;
        }
        $signature = $this->sourceNavigationSignature($navigationRoot);
        if ( '' === $signature ) {
            return false;
        }
        if ( $this->hasCapturedDisclosureNavigation($document, $signature) ) {
            return false;
        }

        foreach ( $document->getElementsByTagName('nav') as $candidate ) {
            if ( ! $candidate instanceof DOMElement
                || $candidate->isSameNode($navigationRoot)
                || $this->isProjectedNavigationSuppressed($candidate)
                || $this->isInsideCapturedDisclosure($candidate)
                || SourceDom::elementContains($navigationRoot, $candidate)
                || SourceDom::elementContains($candidate, $navigationRoot)
            ) {
                continue;
            }
            if ( $signature === $this->sourceNavigationSignature($candidate)
                && ($this->hasMobileNavigationSignal($navigationRoot) || $this->hasMobileNavigationSignal($candidate))
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasCapturedDisclosureNavigation(DOMDocument $document, string $signature): bool
    {
        foreach ( $document->getElementsByTagName('details') as $disclosure ) {
            if ( ! $disclosure instanceof DOMElement || ! $this->isCapturedDialogControl($disclosure) ) {
                continue;
            }

            foreach ( $disclosure->getElementsByTagName('nav') as $candidate ) {
                if ( $candidate instanceof DOMElement && $signature === $this->sourceNavigationSignature($candidate) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isInsideCapturedDisclosure(DOMElement $element): bool
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( 'details' === strtolower($node->tagName) && $this->isCapturedDialogControl($node) ) {
                return true;
            }
        }

        return false;
    }

    private function hasMobileNavigationSignal(DOMElement $element): bool
    {
        for ( $node = $element; $node instanceof DOMElement && 'body' !== strtolower($node->tagName); $node = $node->parentNode ) {
            $identity = strtolower(trim(SourceDom::attr($node, 'id') . ' ' . SourceDom::attr($node, 'class') . ' ' . SourceDom::attr($node, 'aria-label')));
            if ( preg_match('/(?:^|[\s_-])(?:mobile|drawer|offcanvas|overlay|menu-panel|nav-panel)(?:$|[\s_-])/', $identity) ) {
                return true;
            }
        }

        return false;
    }

    private function navigationLandmarkAncestor(DOMElement $element): ?DOMElement
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $this->isNavigationLandmark($node) ) {
                return $node;
            }
        }

        return null;
    }

    private function sourceNavigationSignature(DOMElement $navigation): string
    {
        $links = array();
        foreach ( $navigation->getElementsByTagName('a') as $anchor ) {
            if ( $anchor instanceof DOMElement
                && '' !== trim($anchor->textContent ?? '')
                && $this->isNavigationDestinationAnchor($anchor)
            ) {
                $links[] = strtolower(trim($anchor->textContent ?? '')) . '|' . trim(SourceDom::attr($anchor, 'href'));
            }
        }

        return 2 > count($links) ? '' : implode("\n", $links);
    }

    /**
     * Whether an anchor is a real navigation destination rather than a
     * disclosure/overflow toggle (e.g. a "More" menu trigger that duplicates
     * the surrounding items in a nested submenu). Toggle anchors carry
     * aria-expanded/aria-controls or a non-navigating href (empty, "#", or
     * javascript:) and must not count toward a nav's link signature: a source
     * that expresses the same toggle as a labelled anchor in one header
     * instance and as a <details>/<summary> disclosure in a duplicate instance
     * (or omits its visible label under a narrower layout) must still compare
     * as the equivalent navigation, so its responsive overlay is preserved
     * instead of silently downgrading the menu to overlayMenu "never".
     */
    /**
     * A hash (or empty) anchor whose accessible name is a menu control, not a
     * destination. Builders emit this instead of <button> / <a role="button">.
     * Used only for overlay projection, never for dropping the control as
     * redundant chrome — without a hidden panel the source trigger is still
     * the visible MENU label.
     */
    public function isHashAnchorMenuProjection(DOMElement $element): bool
    {
        return $this->isProjectableHashAnchorMenuToggle($element);
    }

    /**
     * Overlay mode for a projected control. A hash-anchor hamburger whose
     * associated menu is hidden at the default viewport stays `always`. When
     * the default stylesheet hides that hamburger and leaves an equivalent
     * inline list visible, Core's mobile overlay keeps the desktop links.
     */
    public function projectedOverlayMenu(DOMElement $control): string
    {
        if ( ! $this->isHashAnchorMenuProjection($control) ) {
            return 'mobile';
        }

        return $this->isHiddenAtDefaultViewport($control) && $this->hasDefaultViewportVisibleNavigationTwin($control)
            ? 'mobile'
            : 'always';
    }

    private function hasDefaultViewportVisibleNavigationTwin(DOMElement $control): bool
    {
        $navigation = $this->projectedNavigationTargetForControl($control);
        if ( ! $navigation instanceof DOMElement ) {
            return false;
        }

        $signature = $this->sourceNavigationSignature($navigation);
        if ( '' === $signature ) {
            return false;
        }

        $root = $this->documentVariantRoot($control);
        foreach ( $root->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement
                || $candidate->isSameNode($navigation)
                || SourceDom::elementContains($candidate, $control)
                || SourceDom::elementContains($control, $candidate)
                || ! $this->isAssociatedNavigationTarget($candidate)
                || $signature !== $this->sourceNavigationSignature($candidate)
                || $this->isHiddenAtDefaultViewport($candidate) ) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function isHiddenAtDefaultViewport(DOMElement $element): bool
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), array( 'body', 'html' ), true) ) {
                break;
            }
            if ( $this->sourceElementIsHidden($node) ) {
                return true;
            }
        }

        return false;
    }

    private function isProjectableHashAnchorMenuToggle(DOMElement $element): bool
    {
        if ( 'a' !== strtolower($element->tagName) || '' !== $this->visibleMenuToggleLabel($element) ) {
            return false;
        }

        $href = trim(SourceDom::attr($element, 'href'));
        if ( '' !== $href && ! str_starts_with($href, '#') && ! str_starts_with(strtolower($href), 'javascript:') ) {
            return false;
        }

        $accessibleName = strtolower(trim(implode(' ', array(
            SourceDom::attr($element, 'aria-label'),
            SourceDom::attr($element, 'title'),
        ))));

        return 1 === preg_match('/(?:^|[^a-z0-9])(?:navigation|nav|menu|hamburger)(?:[^a-z0-9]|$)/', $accessibleName);
    }

    private function isNavigationDestinationAnchor(DOMElement $anchor): bool
    {
        if ( $anchor->hasAttribute('aria-controls') || $anchor->hasAttribute('aria-expanded') ) {
            return false;
        }

        $href = trim(SourceDom::attr($anchor, 'href'));
        if ( '' === $href || str_starts_with($href, '#') ) {
            return false;
        }

        return ! str_starts_with(strtolower($href), 'javascript:');
    }


    /**
     * Whether an element is a navigation menu the toggle can be bound to: a
     * structural/semantic navigation menu candidate (nav landmark or signaled
     * list), or any container that converts to core/navigation (e.g. a signaled
     * direct-anchor menu div).
     */
    private function isAssociatedNavigationTarget(DOMElement $element): bool
    {
        return $this->isNavigationMenuCandidate($element) || $this->convertsToCoreNavigation($element);
    }

    private function isNavigationLandmark(DOMElement $element): bool
    {
        return 'nav' === strtolower($element->tagName) || 'navigation' === strtolower(SourceDom::attr($element, 'role'));
    }

    /**
     * Nearest enclosing navigation/header landmark, or the document body, used
     * to bound the search for a sibling navigation menu.
     */
    private function menuToggleScope(DOMElement $toggle): DOMElement
    {
        for ( $node = $toggle->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            $tagName = strtolower($node->tagName);
            if ( 'body' === $tagName ) {
                return $node;
            }

            if ( in_array($tagName, array( 'header', 'nav' ), true) || in_array(strtolower(SourceDom::attr($node, 'role')), array( 'banner', 'navigation' ), true) ) {
                return $node;
            }
        }

        return $toggle;
    }

    public function isNavigationMenuCandidate(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( 'nav' === $tagName || 'navigation' === strtolower(SourceDom::attr($element, 'role')) ) {
            return true;
        }

        return in_array($tagName, array( 'ul', 'ol' ), true) && SourceDom::hasSourceNavigationSignal($element);
    }

    public function convertsToCoreNavigation(DOMElement $element): bool
    {
        $navigation = $this->context->patternRecognizers()->firstMatch(
            $element,
            $this->context->probePatternContext(),
            array( \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern::class )
        );

        return null !== $navigation && 'core/navigation' === ($navigation->block()['blockName'] ?? '');
    }

    private function elementWithId(DOMElement $context, string $id): ?DOMElement
    {
        $document = $context->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return null;
        }

        foreach ( $document->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && $element->getAttribute('id') === $id ) {
                return $element;
            }
        }

        return null;
    }
}
