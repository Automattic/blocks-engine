<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

trait NavigationToggleSuppressionTrait
{
    /** @var array<string, DOMElement> */
    private array $projectedNavigationTargetsByControlPath = array();

    /** @var array<string, true> */
    private array $projectedNavigationSuppressedPaths = array();

    /** @var array<string, true> */
    private array $implicitDialogNavigationControlPaths = array();

    /**
     * Bind a hidden dialog/menu to its source hamburger before recursive
     * conversion. The responsive core/navigation must occupy the control's
     * layout slot, not the hidden overlay's document position.
     */
    private function collectProjectedNavigationRelationships(DOMElement $root): void
    {
        $elementsById = array();
        foreach ( $root->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && '' !== trim($this->attr($element, 'id')) ) {
                $elementsById[trim($this->attr($element, 'id'))] = $element;
            }
        }

        foreach ( $root->getElementsByTagName('*') as $control ) {
            if ( ! $control instanceof DOMElement || ! $this->isHamburgerMenuToggleControl($control) ) {
                continue;
            }

            foreach ( preg_split('/\s+/', trim($this->attr($control, 'aria-controls'))) ?: array() as $controlledId ) {
                $target = $elementsById[ltrim($controlledId, '#')] ?? null;
                $navigation = $target instanceof DOMElement ? $this->hiddenNavigationInControlledTarget($target) : null;
                if ( ! $target instanceof DOMElement || ! $navigation instanceof DOMElement ) {
                    continue;
                }
                $this->recordProjectedNavigationRelationship($control, $target, $navigation);
                break;
            }

            if ( isset($this->projectedNavigationTargetsByControlPath[$control->getNodePath()])
                || ! $this->hasDialogPopupSemantics($control) ) {
                continue;
            }

            $relationship = $this->implicitHiddenNavigationRelationship($control);
            if ( null !== $relationship ) {
                $this->implicitDialogNavigationControlPaths[$control->getNodePath()] = true;
                $this->recordProjectedNavigationRelationship($control, $relationship['target'], $relationship['navigation']);
            }
        }
    }

    private function recordProjectedNavigationRelationship(DOMElement $control, DOMElement $target, DOMElement $navigation): void
    {
        if ( isset($this->projectedNavigationSuppressedPaths[$navigation->getNodePath()]) ) {
            return;
        }

        $this->projectedNavigationTargetsByControlPath[$control->getNodePath()] = $navigation;
        $this->projectedNavigationSuppressedPaths[$target->getNodePath()] = true;
        $this->projectedNavigationSuppressedPaths[$navigation->getNodePath()] = true;
    }

    private function hasDialogPopupSemantics(DOMElement $control): bool
    {
        return in_array('dialog', preg_split('/\s+/', strtolower(trim($this->attr($control, 'aria-haspopup')))) ?: array(), true)
            && $control->hasAttribute('aria-expanded');
    }

    /**
     * @return array{target: DOMElement, navigation: DOMElement}|null
     */
    private function implicitHiddenNavigationRelationship(DOMElement $control): ?array
    {
        $depth = 0;
        for ( $scope = $this->menuToggleScope($control); $scope instanceof DOMElement && $depth < 12; $scope = $scope->parentNode, ++$depth ) {
            $candidates = array();
            foreach ( $scope->getElementsByTagName('*') as $candidate ) {
                if ( ! $candidate instanceof DOMElement || $candidate->isSameNode($control) || ! $this->isSemanticDialog($candidate) ) {
                    continue;
                }

                $navigation = $this->hiddenNavigationInControlledTarget($candidate);
                if ( $navigation instanceof DOMElement ) {
                    $candidates[] = array('target' => $candidate, 'navigation' => $navigation);
                }
            }

            if ( 1 === count($candidates) ) {
                return $candidates[0];
            }
            if ( 1 < count($candidates) ) {
                return null;
            }
        }

        return null;
    }

    private function hiddenNavigationInControlledTarget(DOMElement $target): ?DOMElement
    {
        $tagName = strtolower($target->tagName);
        $role = strtolower($this->attr($target, 'role'));
        if ( ! $this->sourceElementIsHidden($target)
            || ( ! in_array($tagName, array( 'dialog', 'nav' ), true)
                && ! in_array($role, array( 'dialog', 'alertdialog', 'navigation' ), true) ) ) {
            return null;
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
            || in_array(strtolower($this->attr($element, 'role')), array( 'dialog', 'alertdialog' ), true);
    }

    private function sourceElementIsHidden(DOMElement $element): bool
    {
        return $this->sourceElementStartsHidden($element)
            || $element->hasAttribute('hidden')
            || 'true' === strtolower($this->attr($element, 'aria-hidden'))
            || 'false' === strtolower($this->attr($element, 'data-visible'));
    }

    private function projectedNavigationTargetForControl(DOMElement $control): ?DOMElement
    {
        return $this->projectedNavigationTargetsByControlPath[$control->getNodePath()] ?? null;
    }

    private function isImplicitDialogNavigationControl(DOMElement $control): bool
    {
        return isset($this->implicitDialogNavigationControlPaths[$control->getNodePath()]);
    }

    private function isProjectedNavigationSuppressed(DOMElement $element): bool
    {
        return isset($this->projectedNavigationSuppressedPaths[$element->getNodePath()]);
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
    private function isRedundantMenuToggleControl(DOMElement $element): bool
    {
        if ( ! $this->isHamburgerMenuToggleControl($element) ) {
            return false;
        }

        return $this->hasAssociatedNavigationMenu($element);
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
    private function collectSupersededNavToggleSelectors(DOMElement $root): void
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

        foreach ( preg_split('/\s+/', trim($this->attr($toggle, 'aria-controls'))) ?: array() as $controlledId ) {
            $controlledId = ltrim(trim($controlledId), '#');
            if ( '' === $controlledId ) {
                continue;
            }

            $this->runtimeSelectors()->supersede('#' . $controlledId);

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

        $label = strtolower($this->attr($element, 'aria-label'));
        if ( str_contains($label, 'navigation') || str_contains($label, 'menu') || str_contains($label, 'mobile') ) {
            return true;
        }

        $role = strtolower($this->attr($element, 'role'));
        return 'navigation' === $role;
    }

    private function recordSupersededSelectorsForElement(DOMElement $element): void
    {
        $id = trim($this->attr($element, 'id'));
        if ( '' !== $id ) {
            $this->runtimeSelectors()->supersede('#' . $id);
        }

        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
            if ( '' !== $class ) {
                $this->runtimeSelectors()->supersede('.' . $class);
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

        $isButton = 'button' === $tagName;
        $isButtonRoleAnchor = 'a' === $tagName && 'button' === strtolower($this->attr($element, 'role'));
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
        $controlId = trim($this->attr($element, 'for'));
        if ( '' === $controlId || '' !== $this->visibleMenuToggleLabel($element) ) {
            return false;
        }

        $control = $this->elementWithId($element, $controlId);
        if ( ! $control instanceof DOMElement
            || 'input' !== strtolower($control->tagName)
            || 'checkbox' !== strtolower($this->attr($control, 'type'))
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
                $identity[] = $this->attr($element, $attribute);
            }
        }

        return 1 === preg_match('/(?:^|[^a-z0-9])(?:navigation|nav|menu|hamburger|drawer|offcanvas)(?:[^a-z0-9]|$)/', strtolower(implode(' ', $identity)));
    }

    private function isCheckboxWithEmptyBoundLabel(DOMElement $element): bool
    {
        $controlId = trim($this->attr($element, 'id'));
        if ( '' === $controlId || 'checkbox' !== strtolower($this->attr($element, 'type')) ) {
            return false;
        }

        $document = $element->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return false;
        }

        foreach ( $document->getElementsByTagName('label') as $label ) {
            if ( $label instanceof DOMElement
                && $controlId === trim($this->attr($label, 'for'))
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
            || 'true' === strtolower($this->attr($node, 'aria-hidden'))
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
        $declarations = $this->cssDeclarations($this->specificityResolvedPresentationStyle($element));
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
        $controlledIds = preg_split('/\s+/', trim($this->attr($toggle, 'aria-controls'))) ?: array();
        foreach ( $controlledIds as $controlledId ) {
            if ( '' === $controlledId ) {
                continue;
            }

            $target = $this->elementWithId($toggle, $controlledId);
            if ( $target instanceof DOMElement && ! $target->isSameNode($toggle) && $this->isAssociatedNavigationTarget($target) ) {
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
    private function navigationOverlayMenu(DOMElement $navigation): string
    {
        if ( $this->hasEquivalentSourceNavigationVariant($navigation) ) {
            return 'mobile';
        }

        $document = $navigation->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return 'never';
        }

        foreach ( $document->getElementsByTagName('*') as $toggle ) {
            if ( ! $toggle instanceof DOMElement
                || ! $this->isHamburgerMenuToggleControl($toggle)
                || ! $this->hasAssociatedNavigationMenu($toggle)
            ) {
                continue;
            }

            $projectedTarget = $this->projectedNavigationTargetForControl($toggle);
            if ( $projectedTarget instanceof DOMElement ) {
                if ( $projectedTarget->isSameNode($navigation) ) {
                    return 'mobile';
                }
                continue;
            }

            if ( $this->elementContains($navigation, $toggle) ) {
                return 'mobile';
            }

            foreach ( preg_split('/\s+/', trim($this->attr($toggle, 'aria-controls'))) ?: array() as $controlledId ) {
                $target = '' === $controlledId ? null : $this->elementWithId($toggle, $controlledId);
                if ( $target instanceof DOMElement
                    && ($this->elementContains($target, $navigation) || $this->elementContains($navigation, $target))
                ) {
                    return 'mobile';
                }
            }

            for ( $container = $toggle->parentNode; $container instanceof DOMElement && 'body' !== strtolower($container->tagName); $container = $container->parentNode ) {
                if ( $this->elementContains($container, $navigation) ) {
                    return 'mobile';
                }
            }

            $scope = $this->menuToggleScope($toggle);
            if ( 'body' !== strtolower($scope->tagName) && $this->elementContains($scope, $navigation) ) {
                return 'mobile';
            }
        }

        return 'never';
    }

    private function hasEquivalentSourceNavigationVariant(DOMElement $navigation): bool
    {
        $document = $navigation->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return false;
        }

        $navigationRoot = $this->navigationLandmarkAncestor($navigation) ?? $navigation;
        $signature = $this->sourceNavigationSignature($navigationRoot);
        if ( '' === $signature ) {
            return false;
        }

        foreach ( $document->getElementsByTagName('nav') as $candidate ) {
            if ( ! $candidate instanceof DOMElement
                || $candidate->isSameNode($navigationRoot)
                || $this->isProjectedNavigationSuppressed($candidate)
                || $this->elementContains($navigationRoot, $candidate)
                || $this->elementContains($candidate, $navigationRoot)
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

    private function hasMobileNavigationSignal(DOMElement $element): bool
    {
        for ( $node = $element; $node instanceof DOMElement && 'body' !== strtolower($node->tagName); $node = $node->parentNode ) {
            $identity = strtolower(trim($this->attr($node, 'id') . ' ' . $this->attr($node, 'class') . ' ' . $this->attr($node, 'aria-label')));
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
            if ( $anchor instanceof DOMElement && '' !== trim($anchor->textContent ?? '') ) {
                $links[] = strtolower(trim($anchor->textContent ?? '')) . '|' . trim($this->attr($anchor, 'href'));
            }
        }

        return 2 > count($links) ? '' : implode("\n", $links);
    }

    private function elementContains(DOMElement $container, DOMElement $element): bool
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $node->isSameNode($container) ) {
                return true;
            }
        }

        return false;
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
        return 'nav' === strtolower($element->tagName) || 'navigation' === strtolower($this->attr($element, 'role'));
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

            if ( in_array($tagName, array( 'header', 'nav' ), true) || in_array(strtolower($this->attr($node, 'role')), array( 'banner', 'navigation' ), true) ) {
                return $node;
            }
        }

        return $toggle;
    }

    private function isNavigationMenuCandidate(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( 'nav' === $tagName || 'navigation' === strtolower($this->attr($element, 'role')) ) {
            return true;
        }

        return in_array($tagName, array( 'ul', 'ol' ), true) && $this->hasSourceNavigationSignal($element);
    }

    private function convertsToCoreNavigation(DOMElement $element): bool
    {
        $navigation = $this->patternRecognizers->firstMatch(
            $element,
            $this->probePatternContext(),
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
