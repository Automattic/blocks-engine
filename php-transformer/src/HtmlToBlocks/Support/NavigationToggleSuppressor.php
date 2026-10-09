<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\DeclaredPresentation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMDocument;
use DOMElement;
use DOMNode;
use SplObjectStorage;

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
    /**
     * NavigationPattern outcome per source element. Toggle ownership asks the
     * same question of every element in a candidate scope, for every toggle,
     * every ancestor scope and every navigation, so an unmemoized deep document
     * re-runs the full recognizer (and its cascade matching) hundreds of times
     * per element. Keyed by element identity — the storage holds each wrapper,
     * so identity stays stable while node paths may shift — and cleared
     * whenever source markup mutates after selector analysis.
     *
     * @var SplObjectStorage<DOMElement, bool>
     */
    private SplObjectStorage $coreNavigationRecognitions;

    /** Recognizer executions behind {@see self::convertsToCoreNavigation()}. */
    public int $coreNavigationRecognitionExecutions = 0;

    public function __construct(
        private readonly NavigationToggleSuppressionContext $context,
        private readonly StyleResolver $styleResolver
    ) {
        $this->coreNavigationRecognitions = new SplObjectStorage();
    }

    /** Source markup changed after selector analysis, so memoized recognitions are stale. */
    public function forgetCoreNavigationRecognitions(): void
    {
        $this->coreNavigationRecognitions = new SplObjectStorage();
    }
    /**
     * Bind a hidden dialog/menu to its source hamburger before recursive
     * conversion. The responsive core/navigation must occupy the control's
     * layout slot, not the hidden overlay's document position.
     */
    public function collectProjectedNavigationRelationships(DOMElement $root): void
    {
        // A captured menu dialog that repeats a dropdown row belongs to that
        // row's navigation. Record it first so the hamburger and the panel are
        // never offered to the projections below.
        foreach ( CapturedMenuDialogFold::detect($root) as $fold ) {
            $this->context->navigationProjection()->foldMenuDialog($fold);
        }

        $elementsById = array();
        foreach ( $root->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && '' !== trim(SourceDom::attr($element, 'id')) ) {
                $elementsById[trim(SourceDom::attr($element, 'id'))] = $element;
            }
        }

        foreach ( $root->getElementsByTagName('*') as $control ) {
            if ( ! $control instanceof DOMElement || $this->isCapturedDialogControl($control) || $this->isInsideNativeDisclosurePanel($control) ) {
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
            if ( $this->isBoundCapturedDialogTrigger($control) ) {
                continue;
            }

            $relationship = $this->implicitHiddenNavigationRelationship($control);
            if ( null !== $relationship ) {
                $isDialogProjection = $this->hasDialogPopupSemantics($control) || $this->isSemanticDialog($relationship['target']);
                // A visible in-flow menu with the same destinations already owns
                // the overlay. Projecting a non-dialog collapsed copy onto a
                // CSS glyph toggle would emit a second navigation in the toggle's
                // slot. Dialog and hash-anchor overlays still occupy the control.
                if ( ! $isDialogProjection
                    && ! $this->isProjectableHashAnchorMenuToggle($control)
                    && $this->hasEquivalentSourceNavigationVariant($relationship['navigation']) ) {
                    continue;
                }
                if ( $this->hasDialogPopupSemantics($control) ) {
                    $this->context->navigationProjection()->markImplicitDialogControl($control);
                }
                $this->recordProjectedNavigationRelationship($control, $relationship['target'], $relationship['navigation']);
            }
        }
    }

    private function recordProjectedNavigationRelationship(DOMElement $control, DOMElement $target, DOMElement $navigation): void
    {
        // A separate toggle cannot claim a panel that belongs to an operable
        // native disclosure. Projecting it would suppress the details content
        // even though the disclosure already owns its summary and open state.
        if ( $this->isInsideNativeDisclosurePanel($target) || $this->isInsideNativeDisclosurePanel($navigation) ) {
            return;
        }
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
            if ( SourceDom::isDocumentVariantRoot($node) ) {
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

        // The target reads as hidden by its own unconditional declaration
        // (e.g. a `hidden md:flex` desktop nav), but a builder commonly
        // expresses "visible only from this breakpoint up" that same way.
        // Resolving the full cascade at the desktop reference viewport tells
        // the genuine off-canvas panel — still hidden once every applicable
        // media-conditional rule is applied — apart from the real, in-flow
        // desktop navigation the toggle merely stands in for on narrow
        // viewports. Only the former is a control's hidden counterpart; the
        // latter must keep its own document position and let the toggle
        // become core/navigation's native responsive affordance instead.
        if ( ! $this->targetHiddenAtReferenceViewport($target) ) {
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
                        // A nested item/cluster is part of the containing menu,
                        // not a second independently controlled occurrence.
                        if ( SourceDom::elementContains($inner, $candidate) ) {
                            continue;
                        }
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

    /**
     * Whether the target stays hidden once its authored cascade is resolved
     * at the desktop reference viewport — the same test
     * {@see self::sourceElementIsHidden()} makes, but reading display,
     * visibility and opacity (on the element or an ancestor) through
     * media-conditional rules that apply there instead of unconditional
     * declarations only. A max-height clip is a static, non-responsive
     * hiding technique, so it is read the same narrow way in both checks.
     */
    private function targetHiddenAtReferenceViewport(DOMElement $target): bool
    {
        if ( $target->hasAttribute('hidden')
            || 'true' === strtolower(SourceDom::attr($target, 'aria-hidden'))
            || 'false' === strtolower(SourceDom::attr($target, 'data-visible'))
            || $this->context->sourceElementOrAncestorStartsHidden($target) ) {
            return true;
        }

        $maxHeight = CssValueInspector::comparable(
            (string) ($this->styleResolver->structuralPresentationDeclarations($target)['max-height'] ?? '')
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
        if ( $this->isCapturedDialogControl($element) || $this->isBoundCapturedDialogTrigger($element) ) {
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
     * A control inside a native disclosure's panel (a submenu expander in a
     * captured mobile drawer, say) lives in content the details block already
     * preserves. It is never the hamburger for a hidden overlay: its bounded
     * scope would reach the closed panel it sits in and move that panel's
     * navigation out of the disclosure.
     */
    private function isInsideNativeDisclosurePanel(DOMElement $control): bool
    {
        for ( $node = $control->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( 'details' === strtolower($node->tagName) ) {
                $summary = null;
                foreach ( $node->childNodes as $child ) {
                    if ( $child instanceof DOMElement && 'summary' === strtolower($child->tagName) ) {
                        $summary = $child;
                        break;
                    }
                }
                return ! ( $summary instanceof DOMElement && SourceDom::elementContains($summary, $control) );
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

    /**
     * A control bound to a projected native dialog (`data-blocks-engine-triggers`
     * names its id) owns that dialog; it is the dialog's opener, not redundant
     * hamburger chrome a rebuilt navigation overlay supersedes.
     */
    private function isBoundCapturedDialogTrigger(DOMElement $element): bool
    {
        return SourceDom::isBoundCapturedDialogTrigger($element);
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

    /** Record only the trigger/panel ids owned by native submenu lowering. */
    public function collectSupersededCapturedSubmenuSelectors(DOMElement $root): void
    {
        foreach ($root->getElementsByTagName('*') as $element) {
            if (!$element instanceof DOMElement || !NavigationPattern::ownsCapturedSubmenuTrigger($element)) continue;
            foreach (array($element->getAttribute('id'), $element->getAttribute('aria-controls'), $element->getAttribute('data-dla-dialog-trigger')) as $id) {
                $id = ltrim(trim($id), '#');
                if ('' !== $id && 1 === preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/', $id)) $this->context->runtimeSelectors()->supersede('#' . $id);
            }
        }
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
                $this->recordSupersededControlPaths($element);
            }
        }
    }

    /**
     * Record the dropped toggle, and everything inside it, as source paths no
     * author selector can address in the output. The author stylesheet
     * projection keeps a type selector (`.nav button`, `.nav button svg`)
     * whose matched source element has no projected counterpart, so once the
     * toggle is gone the only `button`s such a rule can reach inside the
     * converted header are the open and close buttons Core renders in its
     * place — and the toggle's placement or desktop `display:none` would land
     * on them. Marking the paths lets the projection bind those rules to a
     * marker nothing carries, and exclude Core's chrome from rules the toggle
     * shared with elements that do survive.
     */
    private function recordSupersededControlPaths(DOMElement $toggle): void
    {
        $projections = $this->context->selectorProjections();
        $projections->markSupersededControlPath($toggle->getNodePath() ?? '');
        foreach ( $toggle->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement ) {
                $projections->markSupersededControlPath($descendant->getNodePath() ?? '');
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
                && $this->namesMenu($accessibleName);
        }

        $isButton = 'button' === $tagName;
        $isRoleButton = ('a' === $tagName && 'button' === strtolower(SourceDom::attr($element, 'role')))
            || AuthoredButtonBlockGenerator::isRoleButton($element);
        if ( ! $isButton && ! $isRoleButton ) {
            return false;
        }

        // A control inside a form belongs to that form's own fields (a country
        // code picker, a date popup): it opens the field's list, never the
        // site menu, however far up the page a menu dialog sits.
        if ( $this->hasAncestorTag($element, 'form') ) {
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
        if ( $this->isHamburgerBarStackControl($element) ) {
            return true;
        }

        // Icon-bars shape drawn as a character: the control's only visible
        // content is the hamburger glyph itself (☰, or its ≡ stand-in). Sites
        // without an icon font or SVG set type the three bars as text, so
        // the control reads as "labelled" although nothing on screen is a
        // word. It is the same icon the empty-span stack paints, with the
        // same JS-only behavior the importer cannot carry.
        if ( $this->isHamburgerGlyphControl($element) ) {
            return true;
        }

        // Named-icon shape: a labelless control whose accessible name — its
        // aria-label/title, or the text it hides visually (an off-screen
        // "Menu" behind a CSS-drawn glyph) — names a menu.
        return $this->accessibleNameNamesMenu($element);
    }

    private function hasAncestorTag(DOMElement $element, string $tagName): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $tagName === strtolower($node->tagName) ) {
                return true;
            }
        }

        return false;
    }

    private function accessibleNameNamesMenu(DOMElement $element): bool
    {
        return $this->namesMenu(implode(' ', array(
            SourceDom::attr($element, 'aria-label'),
            SourceDom::attr($element, 'title'),
            $element->textContent,
        )));
    }

    /**
     * Whether an accessible name calls its control a menu. The word is matched
     * whole, including its accented spellings (`Menú`, `Menü`).
     */
    private function namesMenu(string $accessibleName): bool
    {
        return 1 === preg_match('/(?:^|[^\p{L}\p{N}])(?:navigation|nav|men[uúùü]|hamburger)(?:[^\p{L}\p{N}]|$)/iu', $accessibleName);
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

        return $this->isCssGeneratedHamburgerGlyphLabel($element);
    }

    /**
     * A labelless control whose only element child is a single empty span/div:
     * the host CSS paints as a 3-bar glyph via the child's own box plus
     * :before/:after. Two nested empty bars are already handled above; this
     * shape has one host and no nested bars in the DOM. Restricted to controls
     * the source hides at the default viewport beside a menu, so an empty
     * decorative label elsewhere is not treated as a hamburger.
     */
    private function isCssGeneratedHamburgerGlyphLabel(DOMElement $element): bool
    {
        if ( '' !== $this->visibleMenuToggleLabel($element) || ! $this->isHiddenAtDefaultViewport($element) ) {
            return false;
        }

        $hosts = array();
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
            $hosts[] = $child;
        }
        if ( 1 !== count($hosts) ) {
            return false;
        }

        $host = $hosts[0];
        if ( ! in_array(strtolower($host->tagName), array( 'span', 'div', 'i' ), true)
            || '' !== trim($host->textContent ?? '')
            || 0 !== $host->childNodes->length ) {
            return false;
        }

        return $this->sitsBesideNavigationCandidate($element);
    }

    private function sitsBesideNavigationCandidate(DOMElement $element): bool
    {
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }

        foreach ( $parent->childNodes as $sibling ) {
            if ( $sibling instanceof DOMElement
                && ! $sibling->isSameNode($element)
                && $this->isEquivalentNavigationVariantCandidate($sibling) ) {
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
     * Whether the control's only visible content is the hamburger glyph: the
     * three bars typed as a character instead of drawn with spans or SVG.
     * An image beside it, a word beside it, or a different symbol (a kebab
     * "more" control, an ellipsis, a plus) is never matched.
     */
    private function isHamburgerGlyphControl(DOMElement $element): bool
    {
        return 0 === $element->getElementsByTagName('img')->length
            && $this->isMenuGlyph($this->visibleMenuToggleTextContent($element));
    }

    /**
     * Whether a text run is nothing but one pictographic menu glyph:
     * U+2630 TRIGRAM FOR HEAVEN (☰), the hamburger as a character, or
     * U+2261 IDENTICAL TO (≡), the stand-in used where ☰ lacked font
     * coverage. An optional variation selector and surrounding whitespace
     * (including no-break spaces) are allowed. Other symbols are not menu
     * glyphs: ⋮ and … open "more"/overflow actions, and a repeated or
     * mixed run is a label, not an icon.
     */
    private function isMenuGlyph(string $text): bool
    {
        return 1 === preg_match('/^[\s\p{Z}]*[\x{2630}\x{2261}][\x{FE0E}\x{FE0F}]?[\s\p{Z}]*$/u', $text);
    }

    /**
     * Visible text label of a control with decorative chrome (icons, empty
     * hamburger bars, the hamburger glyph) and source-hidden descendants
     * stripped. Empty means the control shows no text label; accessible
     * names remain separate semantics.
     */
    private function visibleMenuToggleLabel(DOMElement $element): string
    {
        if ( $this->hidesTextVisually($element, false) ) {
            return '';
        }

        $label = $this->visibleMenuToggleTextContent($element);

        return $this->isMenuGlyph($label) ? '' : $label;
    }

    /** The control's visible text, before deciding whether it is a label or an icon. */
    private function visibleMenuToggleTextContent(DOMElement $element): string
    {
        $text = '';
        foreach ( $element->childNodes as $child ) {
            $text .= $this->visibleMenuToggleText($child);
        }

        return trim($text);
    }

    private function visibleMenuToggleText(DOMNode $node): string
    {
        if ( XML_TEXT_NODE === $node->nodeType ) {
            return $node->textContent ?? '';
        }

        if ( ! $node instanceof DOMElement
            || 'svg' === strtolower($node->tagName)
            || 'true' === strtolower(SourceDom::attr($node, 'aria-hidden'))
            || $this->hidesTextVisually($node, true) ) {
            return '';
        }

        $text = '';
        foreach ( $node->childNodes as $child ) {
            $text .= $this->visibleMenuToggleText($child);
        }

        return $text;
    }

    /**
     * Whether the source keeps the element's text out of sight at any viewport
     * it declares. A toggle label only has to be hidden where the toggle
     * shows, and that is usually behind a mobile media query. Text-hiding
     * declarations (off-screen text-indent, zero font-size) keep the box
     * painting, so they apply to the control itself; a label descendant is
     * also hidden by `display:none`, `visibility:hidden`, or a clipped box.
     */
    private function hidesTextVisually(DOMElement $element, bool $isLabelDescendant): bool
    {
        $resolved = $this->styleResolver->cssDeclarations($this->styleResolver->specificityResolvedPresentationStyle($element));
        if ( $isLabelDescendant && 1 === preg_match('/^none(?:\s*!important)?$/i', trim((string) ($resolved['display'] ?? ''))) ) {
            return true;
        }

        $textProperties = $isLabelDescendant ? array( 'visibility', 'text-indent', 'font-size' ) : array( 'text-indent', 'font-size' );
        $declared = $this->styleResolver->authorDeclaredValuesAtAnyViewport(
            $element,
            array_merge($textProperties, $isLabelDescendant ? array( 'display', 'position', 'overflow', 'width', 'height', 'clip', 'clip-path' ) : array())
        );
        foreach ( $textProperties as $property ) {
            foreach ( $declared[$property] ?? array() as $value ) {
                if ( CssValueInspector::hidesText($property, $value) ) {
                    return true;
                }
            }
        }

        if ( ! $isLabelDescendant ) {
            return false;
        }

        foreach ( $declared['display'] ?? array() as $value ) {
            if ( 'none' === CssValueInspector::comparable($value) ) {
                return true;
            }
        }

        return CssValueInspector::isVisuallyClippedBox(array_map(
            static fn (array $values): string => (string) end($values),
            $declared
        ));
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

    /** The captured menu dialog a dropdown row has absorbed, if any. */
    public function capturedMenuFoldForRow(DOMElement $row): ?CapturedMenuDialogFold
    {
        return $this->context->navigationProjection()->foldForRow($row);
    }

    public function releaseCapturedMenuFold(CapturedMenuDialogFold $fold): void
    {
        $this->context->navigationProjection()->releaseFold($fold);
    }

    public function navigationToggleControl(DOMElement $navigation): ?DOMElement
    {
        $fold = $this->context->navigationProjection()->foldForRow($navigation);
        if ( $fold instanceof CapturedMenuDialogFold ) {
            return $fold->triggers[0];
        }

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

            if ( $this->isBoundCapturedDialogTrigger($toggle) ) {
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
        // A native details disclosure owns its summary and panel navigation.
        // It remains an independent mobile surface, rather than evidence that a
        // sibling desktop navigation should be replaced by Core's overlay toggle.
        if ( $this->isInsideNativeDisclosure($navigationRoot) ) {
            return false;
        }
        $signature = $this->sourceNavigationSignature($navigationRoot);
        if ( '' === $signature ) {
            return false;
        }
        if ( $this->hasNativeDisclosureNavigation($document, $signature) ) {
            return false;
        }

        foreach ( $document->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement
                || $candidate->isSameNode($navigationRoot)
                || $this->isInsideProjectedNavigationSuppressed($candidate)
                || $this->isInsideNativeDisclosure($candidate)
                || SourceDom::elementContains($navigationRoot, $candidate)
                || SourceDom::elementContains($candidate, $navigationRoot)
                || ! $this->isEquivalentNavigationVariantCandidate($candidate)
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

    private function isInsideProjectedNavigationSuppressed(DOMElement $element): bool
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $this->isProjectedNavigationSuppressed($node) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A collapsed counterpart need not be a <nav> landmark. Builders commonly
     * emit the desktop list and its overlay copy as signaled containers
     * (`div.nav`, `ul.menu`, a drawer whose class names the collapsed surface).
     */
    private function isEquivalentNavigationVariantCandidate(DOMElement $element): bool
    {
        if ( $this->isNavigationMenuCandidate($element) ) {
            return true;
        }
        if ( $this->hasMobileNavigationSignal($element) ) {
            return true;
        }

        $identity = strtolower(trim(SourceDom::attr($element, 'id') . ' ' . SourceDom::attr($element, 'class') . ' ' . SourceDom::attr($element, 'role')));

        return 1 === preg_match('/(?:^|[\s_-])(?:nav|navbar|navigation|menu)(?:$|[\s_-])/', $identity);
    }

    private function hasNativeDisclosureNavigation(DOMDocument $document, string $signature): bool
    {
        foreach ( $document->getElementsByTagName('details') as $disclosure ) {
            if ( ! $disclosure instanceof DOMElement || ! $this->isNativeDisclosureWithPanel($disclosure) ) {
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

    private function isInsideNativeDisclosure(DOMElement $element): bool
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( 'details' === strtolower($node->tagName) && $this->isNativeDisclosureWithPanel($node) ) {
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
        // The hamburger of an absorbed menu dialog is hidden at the wide
        // reference viewport, so the navigation that takes it over shows its
        // own button only on narrow screens.
        if ( $this->context->navigationProjection()->foldForTrigger($control) instanceof CapturedMenuDialogFold ) {
            return 'mobile';
        }
        if ( $control->hasAttribute('data-dla-dialog-trigger') ) {
            return 'always';
        }
        if ( ! NavigationPattern::ownsCapturedSubmenuTrigger($control)
            && ( $control->hasAttribute('data-dla-dialog-trigger') || 'dialog' === strtolower(trim(SourceDom::attr($control, 'aria-haspopup'))) )
            && ! $this->isHiddenAtDefaultViewport($control)
            && ! $this->hasDefaultViewportVisibleNavigationTwin($control)
        ) {
            return 'always';
        }
        // The enclosing document branch already owns its viewport visibility.
        // A control visible throughout that branch must keep its opener above
        // Core's fixed mobile breakpoint as well.
        if ( null !== SourceDom::documentVariantRoot($control) && ! $this->isHiddenAtDefaultViewport($control) ) {
            return 'always';
        }
        if ( ! $this->isHashAnchorMenuProjection($control) ) {
            return 'mobile';
        }

        return $this->isHiddenAtDefaultViewport($control) && $this->hasDefaultViewportVisibleNavigationTwin($control)
            ? 'mobile'
            : 'always';
    }

    /**
     * The viewport width (px) at and below which the source collapses this
     * navigation behind its toggle, or null when the author stylesheet states
     * no such boundary.
     *
     * core/navigation switches its native overlay at a fixed 600px. A source
     * that hides its menu and shows the toggle below a wider boundary — a
     * `max-width:1300px` query setting the menu to `display:none`, or a
     * mobile-first menu that stays `display:none` until a `min-width` query
     * shows it — needs that boundary carried into the generated support CSS,
     * or the result renders wrong between 600px and the source boundary.
     *
     * The evidence is the author's own media-conditional `display`, read on
     * the menu side and on the toggle side separately. The menu side is every
     * element on the path from each menu link up to, but excluding, the first
     * ancestor that also holds the toggle (a wrapper that holds both cannot
     * be what collapses one behind the other); the menu reads hidden at a
     * width when every link has some element on its path set to `none`. The
     * toggle side is the path from the toggle up to the first ancestor that
     * holds a menu link. Every width those queries name is a candidate, and
     * the boundary is the widest candidate at which the menu reads hidden
     * while it reads visible one pixel wider. When the menu's `display` never
     * switches (a panel hidden by a transform, a clip or an off-canvas
     * offset), the toggle's own visibility is the fallback: the widest width
     * at which the toggle shows but is gone one pixel wider.
     *
     * Only width media queries can yield a boundary. A `print`-only or
     * `prefers-*`-only hide names no width and leaves the result null, as
     * does a menu hidden or shown at every width.
     */
    public function sourceCollapseBreakpoint(DOMElement $navigation, DOMElement $toggle): ?float
    {
        $menuLinks = array();
        foreach ( $navigation->getElementsByTagName('a') as $anchor ) {
            if ( $anchor instanceof DOMElement
                && $anchor->hasAttribute('href')
                && ! $anchor->isSameNode($toggle)
                && ! SourceDom::elementContains($anchor, $toggle)
                && ! SourceDom::elementContains($toggle, $anchor) ) {
                $menuLinks[] = $anchor;
            }
        }
        if ( array() === $menuLinks ) {
            $menuLinks[] = $navigation;
        }

        $displays = array();
        $displayOf = function (DOMElement $element) use (&$displays): DeclaredPresentation {
            $id = spl_object_id($element);
            if ( ! isset($displays[ $id ]) ) {
                $displays[ $id ] = $this->styleResolver->declaredPresentation($element, 'display');
            }

            return $displays[ $id ];
        };
        $pathAbove = static function (DOMElement $start, callable $stop): array {
            $path = array();
            for ( $node = $start; $node instanceof DOMElement; $node = $node->parentNode ) {
                if ( in_array(strtolower($node->tagName), array( 'body', 'html' ), true) || $stop($node) ) {
                    break;
                }
                $path[] = $node;
            }

            return $path;
        };

        $menuPaths = array();
        foreach ( $menuLinks as $link ) {
            $menuPaths[] = $pathAbove($link, static fn (DOMElement $node): bool => SourceDom::elementContains($node, $toggle) || $node->isSameNode($toggle));
        }
        $togglePath = $pathAbove($toggle, static function (DOMElement $node) use ($menuLinks): bool {
            foreach ( $menuLinks as $link ) {
                if ( SourceDom::elementContains($node, $link) || $node->isSameNode($link) ) {
                    return true;
                }
            }

            return false;
        });

        $candidates = array();
        foreach ( array_merge(array_merge(array(), ...$menuPaths), $togglePath) as $element ) {
            foreach ( $displayOf($element)->conditionStacks() as $stack ) {
                foreach ( $stack as $condition ) {
                    if ( 1 !== preg_match('/^@media\b/i', trim($condition)) ) {
                        continue;
                    }
                    if ( preg_match_all('/(\d*\.?\d+)\s*(px|r?em)\b/i', $condition, $matches, PREG_SET_ORDER) ) {
                        foreach ( $matches as $match ) {
                            $px = (float) $match[1] * ( 'px' === strtolower($match[2]) ? 1.0 : 16.0 );
                            $candidates[] = $px;
                            $candidates[] = $px - 1.0;
                        }
                    }
                }
            }
        }
        if ( array() === $candidates ) {
            return null;
        }
        $candidates = array_values(array_unique(array_filter($candidates, static fn (float $px): bool => 0.0 < $px)));
        rsort($candidates);

        $hiddenAt = function (array $path, float $width) use ($displayOf): bool {
            foreach ( $path as $element ) {
                $value = $displayOf($element)->resolvedValueWhere(
                    fn (array $conditions): bool => $this->styleResolver->conditionsApplyAtViewport($conditions, $width)
                );
                if ( 'none' === strtolower(CssValueInspector::withoutImportant(trim($value))) ) {
                    return true;
                }
            }

            return false;
        };
        $menuHiddenAt = static function (float $width) use ($menuPaths, $hiddenAt): bool {
            foreach ( $menuPaths as $path ) {
                if ( ! $hiddenAt($path, $width) ) {
                    return false;
                }
            }

            return true;
        };

        foreach ( $candidates as $width ) {
            if ( $menuHiddenAt($width) && ! $menuHiddenAt($width + 1.0) ) {
                return $width;
            }
        }
        foreach ( $candidates as $width ) {
            if ( ! $hiddenAt($togglePath, $width) && $hiddenAt($togglePath, $width + 1.0) ) {
                return $width;
            }
        }

        return null;
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
            if ( SourceDom::isDocumentVariantRoot($node) || in_array(strtolower($node->tagName), array( 'body', 'html' ), true) ) {
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

        return $this->namesMenu($accessibleName);
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
        if ( $this->coreNavigationRecognitions->offsetExists($element) ) {
            return $this->coreNavigationRecognitions[$element];
        }

        ++$this->coreNavigationRecognitionExecutions;
        $navigation = $this->context->patternRecognizers()->firstMatch(
            $element,
            $this->context->probePatternContext(),
            array( \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern::class )
        );

        return $this->coreNavigationRecognitions[$element] = null !== $navigation && 'core/navigation' === ($navigation->block()['blockName'] ?? '');
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
