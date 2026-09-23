<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\Css\CssIdent;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ProjectedNavigationConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\SourceTargetProjectionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\NavigationStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NavigationToggleSuppressor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer;
use DOMElement;

/** Navigation-only evidence and policy, backed by real collaborators. */
final class NavigationPatternContext
{
    public function __construct(
        private readonly ?StyleResolver $styleResolver = null,
        private readonly ?NavigationStyleProjector $navigationStyleProjector = null,
        private readonly ?NavigationToggleSuppressor $navigationToggleSuppressor = null,
        private readonly ?RuntimeIslandAnalyzer $runtimeIslands = null,
        private readonly ?SourceTargetProjectionState $sourceTargetProjection = null,
        private readonly ?HtmlTransformerSession $session = null,
        private readonly ?SvgMaterializer $svgMaterializer = null,
        private readonly ?ProjectedNavigationConverter $projectedNavigation = null,
        private readonly NavigationUnderlineColorResolver $underlineColorResolver = new NavigationUnderlineColorResolver()
    ) {
    }

    /**
     * Author-selector markers the projected stylesheet targets for an element
     * that will be inlined into a navigation item's `label` attribute.
     *
     * @return list<string>
     */
    public function labelPresentationMarkers(DOMElement $element): array
    {
        return $this->session?->authorSelectorProjectionState()->semanticMarkersForPath($element->getNodePath() ?? '') ?? array();
    }

    public function isRuntimeDomTarget(DOMElement $element): bool
    {
        return $this->runtimeIslands?->isRuntimeDomTarget($element) ?? false;
    }

    public function underlineColor(DOMElement $item, DOMElement $anchor): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) {
            return '';
        }

        return $this->underlineColorResolver->resolve(
            $item,
            $anchor,
            fn (DOMElement $element): array => $this->styleResolver->presentationDeclarations($element),
            $this->session?->sourceStyleResolutionState()->pseudoElementRules() ?? array(),
            fn (DOMElement $element, string $selector): bool => $this->styleResolver->matchesCssSelector($element, $selector)
        );
    }

    public function resolvedStyle(DOMElement $element): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) {
            return '';
        }

        return $this->styleResolver->resolveCssVariablesInValue(
            $this->styleResolver->specificityResolvedPresentationStyle($element),
            $element
        );
    }

    /**
     * Marker for a navigation anchor whose resolved box core/navigation-link
     * cannot keep on the element it styles.
     *
     * core renders the block's className on its item, where core's own
     * `.wp-block-navigation .wp-block-navigation-item` background rule
     * outranks the class utilities, and the rendered
     * `.wp-block-navigation-item__content` anchor receives neither the fill
     * nor the padding a source CTA styled through its own classes. The
     * anchor's resolved background and padding are carried here and restated
     * on that anchor by the projector; the padding sides the carry moves are
     * reset on the item to the source item's own winner, so the box is not
     * painted twice now that it lives on the anchor again.
     *
     * Background and padding are never inherited, so a value resolved here was
     * owned by the source anchor itself: a box authored on the source list
     * item resolves nothing here and stays where the author put it.
     *
     * @param ?DOMElement $sourceItem The source element core's item stands in
     *                                for, when it is not the anchor itself.
     */
    public function navigationLinkBoxMarker(DOMElement $anchor, ?DOMElement $sourceItem): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver
            || ! $this->session instanceof HtmlTransformerSession
        ) {
            return '';
        }

        $declarations = $this->styleResolver->cssDeclarations($this->resolvedStyle($anchor));
        $content = array();
        $paddingSides = array();
        $background = trim((string) ($declarations['background-color'] ?? ''));
        if ( $this->safeNavigationBoxValue('background-color', $background) ) {
            $content[] = 'background-color:' . $background;
        }
        foreach ( array( 'padding-top', 'padding-right', 'padding-bottom', 'padding-left' ) as $side ) {
            $value = trim((string) ($declarations[$side] ?? ''));
            if ( ! $this->safeNavigationBoxValue($side, $value) ) {
                continue;
            }
            $content[] = $side . ':' . $value;
            $paddingSides[] = $side;
        }
        if ( array() === $content ) {
            return '';
        }

        $reset = array();
        $itemDeclarations = null === $sourceItem
            ? array()
            : $this->styleResolver->cssDeclarations($this->resolvedStyle($sourceItem));
        foreach ( $paddingSides as $side ) {
            $itemValue = trim((string) ( $itemDeclarations[$side] ?? '' ));
            $reset[] = $side . ':' . ( $this->safeNavigationBoxValue($side, $itemValue) ? $itemValue : '0' );
        }

        $marker = 'blocks-engine-navigation-link-box-' . hash('sha256', implode(';', $content));
        $this->session->generatedSupportStylesheetState()->registerNavigationLinkBox(
            $marker,
            implode(';', $content),
            implode(';', $reset)
        );

        return $marker;
    }

    /** A box declaration only carries when it paints or pads visibly and safely. */
    private function safeNavigationBoxValue(string $property, string $value): bool
    {
        $lower = strtolower(trim($value));
        if ( '' === $lower
            || 1 === preg_match('~[{}<>;]|/\*|(?:expression|url)\s*\(|javascript\s*:~i', $value)
        ) {
            return false;
        }
        if ( in_array($lower, array( 'inherit', 'unset', 'initial', 'revert', 'revert-layer' ), true) ) {
            return false;
        }
        if ( 'background-color' === $property && in_array($lower, array( 'transparent', 'none' ), true) ) {
            return false;
        }
        if ( str_starts_with($property, 'padding') && preg_match('/^(?:0|0px|auto)$/', $lower) ) {
            return false;
        }

        return true;
    }

    public function resolvedDisplay(DOMElement $element): string
    {
        return $this->styleResolver?->resolvedConditionalDisplay($element) ?? '';
    }

    /**
     * Whether the element is hidden once its authored cascade — inline, static,
     * AND media/feature-conditional rules that apply at the desktop reference
     * viewport — is fully resolved. A `hidden md:flex` element correctly reads
     * as visible here; a `md:hidden` overlay duplicate reads as hidden.
     */
    public function isHiddenAtReferenceViewport(DOMElement $element): bool
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) {
            return false;
        }

        $declarations = $this->styleResolver->cssDeclarations($this->styleResolver->controlSurfaceResolvedStyle($element));
        $display = CssValueInspector::comparable((string) ($declarations['display'] ?? ''));
        if ( 'none' === $display ) {
            return true;
        }
        $visibility = CssValueInspector::comparable((string) ($declarations['visibility'] ?? ''));
        if ( in_array($visibility, array( 'hidden', 'collapse' ), true) ) {
            return true;
        }
        $opacity = CssValueInspector::comparable((string) ($declarations['opacity'] ?? ''));

        return is_numeric($opacity) && 0.0 === (float) $opacity;
    }

    /** @return list<string> */
    public function colorInteractionStates(DOMElement $element): array
    {
        return $this->navigationStyleProjector?->navigationColorInteractionStates($element) ?? array();
    }

    public function overlayMenu(DOMElement $element): string
    {
        return $this->navigationToggleSuppressor?->navigationOverlayMenu($element) ?? 'never';
    }

    public function responsiveToggleMarker(DOMElement $element): string
    {
        return $this->projectedNavigation?->responsiveNavigationToggleMarker($element) ?? '';
    }

    /**
     * Marker for a navigation anchor whose artwork core cannot save.
     *
     * core/navigation-link stores only a label and URL, so a source anchor's
     * inline SVG has nowhere to land. An icon-only anchor keeps its accessible
     * name as the saved label and has that icon replace it visually; an
     * anchor that ALSO shows a word keeps that word and gets the icon
     * projected beside it as a leading mark, so neither is lost. Either way
     * the owning transformer registers the recovered presentation and this
     * returns an opaque marker class.
     */
    public function linkIconMarker(DOMElement $element): string
    {
        if ( ! $this->svgMaterializer instanceof SvgMaterializer
            || ! $this->styleResolver instanceof StyleResolver
            || ! $this->session instanceof HtmlTransformerSession
        ) {
            return '';
        }

        $svg = null;
        foreach ( $element->getElementsByTagName('svg') as $candidate ) {
            if ( $candidate instanceof DOMElement ) {
                $svg = $candidate;
                break;
            }
        }
        if ( ! $svg instanceof DOMElement || ! SourceDom::svgHasDrawableContent($svg) ) {
            return '';
        }

        $markup = $this->svgMaterializer->restoreSvgCasing($this->svgMaterializer->sanitizeInlineSvgMarkup($svg));
        if ( '' === $markup || ! SourceDom::isSafeSvgContent($markup) ) {
            return '';
        }

        $box = $this->navigationLinkIconBox($svg);
        if ( '' === $box ) {
            return '';
        }

        $background = ';background-image:url("data:image/svg+xml,' . rawurlencode($markup) . '")'
            . ';background-repeat:no-repeat;background-position:center;background-size:contain';

        if ( '' === trim($element->textContent ?? '') ) {
            $declarations = 'display:inline-block;' . $box . $background . ';font-size:0;line-height:0;color:transparent';
            $marker = 'blocks-engine-navigation-link-icon-' . substr(hash('sha256', $declarations), 0, 12);
            $this->session->generatedSupportStylesheetState()->registerNavigationLinkIcon($marker, $declarations);

            return $marker;
        }

        $gap = $this->navigationLinkLeadingIconGap($svg);
        $declarations = 'content:"";display:inline-block;vertical-align:middle;margin-inline-end:' . $gap . ';' . $box . $background;
        $marker = 'blocks-engine-navigation-link-leading-icon-' . substr(hash('sha256', $declarations), 0, 12);
        $this->session->generatedSupportStylesheetState()->registerNavigationLinkLeadingIcon($marker, $declarations);

        return $marker;
    }

    /**
     * The gap the source placed between a leading icon and its label, read
     * from the nearest ancestor (up to the anchor) that declares one —
     * commonly a flex wrapper's `gap`/`column-gap`. Falls back to a small
     * default so a source that expressed the gap only as component internals
     * (not CSS) still gets a readable, non-zero separation.
     */
    private function navigationLinkLeadingIconGap(DOMElement $svg): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) {
            return '0.375rem';
        }

        $node  = $svg->parentNode;
        $depth = 0;
        while ( $node instanceof DOMElement && $depth < 4 ) {
            ++$depth;
            $declarations = $this->styleResolver->resolvedPresentationDeclarations($node);
            foreach ( array( 'column-gap', 'gap' ) as $property ) {
                $value = trim((string) ($declarations[$property] ?? ''));
                if ( '' === $value || preg_match('/[{}<>;]/', $value) ) {
                    continue;
                }
                $parts = preg_split('/\s+/', $value) ?: array( $value );
                $columnGap = trim((string) end($parts));
                if ( '' !== $columnGap && 1 === preg_match('/^\d/', $columnGap) ) {
                    return $columnGap;
                }
            }
            if ( 'a' === strtolower($node->tagName) ) {
                break;
            }
            $node = $node->parentNode;
        }

        return '0.375rem';
    }

    /**
     * Record navigation presentation the source inherits rather than declares.
     *
     * Deliberately returns nothing: a per-document value must not reach block
     * markup, because shell identity compares that markup across documents and
     * a value that varies by page would fragment one shared template part into
     * several. The recorded presentation is delivered as CSS instead.
     *
     * @param array<int, string> $authorClasses Classes already present on the block.
     */
    public function recordInheritedPresentation(DOMElement $element, array $authorClasses): void
    {
        $this->recordInheritedNavigationPresentation($element, $authorClasses);
        $this->recordNavigationContainerPaintReset($element, $authorClasses);
    }

    /** Record unsupported source residue on the native element replacing it. */
    public function projectSourceToNativeTarget(DOMElement $element, string $targetSelector, string $declarations): void
    {
        $this->sourceTargetProjection?->record(SourceDom::elementSelector($element), $targetSelector, $declarations);
    }

    /** Resolve the rendered box of a navigation icon from its source geometry. */
    private function navigationLinkIconBox(DOMElement $svg): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) {
            return '';
        }

        $declarations = $this->styleResolver->resolvedPresentationDeclarations($svg);
        $dimensions = array();
        foreach ( array( 'width', 'height' ) as $property ) {
            $value = trim((string) ($declarations[$property] ?? ''));
            if ( '' === $value || preg_match('/[{}<>;]/', $value) ) {
                $value = trim(SourceDom::attr($svg, $property));
                $value = '' === $value || ! is_numeric($value) ? '' : $value . 'px';
            }
            if ( '' === $value || 1 === preg_match('/^(?:0|auto|none)$/i', $value) ) {
                return '';
            }
            $dimensions[] = $property . ':' . $value;
        }

        return implode(';', $dimensions);
    }

    /**
     * Recover navigation presentation from the source elements native markup replaces.
     *
     * Builders commonly declare menu type and colour on a wrapper between the
     * anchor and the nav. core/navigation emits its own item markup, so that
     * wrapper does not survive and its rule is left in the stylesheet with
     * nothing to match, which drops the menu to the destination theme's
     * defaults. Read the presentation from the source element the native item
     * stands in for, and state it on that native counterpart.
     *
     * The recovered rule is a nav-descendant selector, so it reaches every
     * item. Only properties every source anchor actually shares may land
     * there; a uniquely styled child (a gradient-text wordmark beside plain
     * links) must keep its own type. `color: transparent` is refused even
     * when shared: it is only legible with `background-clip: text` and a
     * background image, neither of which this projection carries, so
     * replaying it always paints invisible text.
     *
     * Delivered as CSS rather than written onto the block: shell identity
     * compares block markup across documents, so a value that varies per page
     * would split one shared template part into one part per page.
     *
     * @param array<int, string> $authorClasses
     */
    private function recordInheritedNavigationPresentation(DOMElement $navigation, array $authorClasses): void
    {
        if ( array() === $authorClasses || ! $this->sourceTargetProjection instanceof SourceTargetProjectionState ) {
            return;
        }

        $anchors = array();
        foreach ( $navigation->getElementsByTagName('a') as $candidate ) {
            if ( $candidate instanceof DOMElement ) {
                $anchors[] = $candidate;
            }
        }
        if ( array() === $anchors ) {
            return;
        }

        $declarations = array();
        // `font` first: builders commonly state menu type as the shorthand, and
        // a longhand found further out should not silently outrank it.
        foreach ( array( 'font', 'color', 'font-family', 'font-size', 'font-weight', 'font-style', 'letter-spacing', 'text-transform' ) as $property ) {
            $value = $this->sharedNavigationItemPresentationValue($anchors, $navigation, $property);
            // The source component resolves this formula against its own width.
            // Replaying it on Core's replacement anchor changes that reference
            // and inflates the label. The promoted item retains the source
            // wrapper class, so the anchor can inherit the original value.
            if ( in_array($property, array( 'font', 'font-size' ), true) && str_contains($value, '--scaling-factor') ) {
                continue;
            }
            if ( 'color' === $property && $this->isTransparentColor($value) ) {
                continue;
            }
            if ( '' !== $value ) {
                $declarations[] = $property . ':' . $this->navigationProjectionValue($value);
            }
        }
        if ( array() === $declarations ) {
            return;
        }

        $selector = '.wp-block-navigation' . CssIdent::compoundClassSelector($authorClasses) . ' .wp-block-navigation-item__content';
        $this->sourceTargetProjection->record(SourceDom::elementSelector($navigation), $selector, implode(';', $declarations));
    }

    /**
     * Presentation every source anchor inside the navigation actually shares.
     *
     * @param list<DOMElement> $anchors
     */
    private function sharedNavigationItemPresentationValue(array $anchors, DOMElement $navigation, string $property): string
    {
        $shared = null;
        foreach ( $anchors as $anchor ) {
            $value = $this->navigationItemPresentationValue($anchor, $navigation, $property);
            if ( null === $shared ) {
                $shared = $value;
                continue;
            }
            if ( $value !== $shared ) {
                return '';
            }
        }

        return is_string($shared) ? $shared : '';
    }

    /** Transparent ink is invisible unless a clipped background travels with it. */
    private function isTransparentColor(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ( '' === $normalized ) {
            return false;
        }
        if ( 'transparent' === $normalized ) {
            return true;
        }

        $compact = preg_replace('/\s+/', '', $normalized) ?? '';
        if ( in_array($compact, array( '#0000', '#00000000' ), true) ) {
            return true;
        }

        return 1 === preg_match('/^(?:rgba?|hsla?)\((?:[^,]+,){3}0(?:\.0+)?\)$/', $compact)
            || 1 === preg_match('#^(?:rgba?|hsla?)\([^/]+/0(?:\.0+)?%?\)$#', $compact);
    }

    /**
     * A promoted navigation is no longer inside the source component's query
     * container. Bind inherited container-width units to the viewport, the
     * responsive reference shared by the source page and its native replacement.
     */
    private function navigationProjectionValue(string $value): string
    {
        return preg_replace('/(?<![a-z-])cqw\b/i', 'vw', $value) ?? $value;
    }

    /**
     * Keep a painted or framed menu stated once.
     *
     * WordPress copies a navigation block's classes onto both the `nav` and its
     * responsive container, so a source rule that styles the menu through one
     * of those classes matches twice. Paint renders stacked on itself; a frame
     * — the menu's own margin, padding, and rules — is charged twice and
     * doubles the menu's height. Where the source states either, neutralise it
     * on the inner container so the `nav` keeps the single source declaration.
     *
     * @param array<int, string> $authorClasses
     */
    private function recordNavigationContainerPaintReset(DOMElement $navigation, array $authorClasses): void
    {
        if ( array() === $authorClasses || ! $this->sourceTargetProjection instanceof SourceTargetProjectionState ) {
            return;
        }

        $resets = array();
        if ( $this->navigationDeclaresAny($navigation, array( 'background-color', 'background-image', 'background', 'border-top-left-radius', 'border-radius', 'box-shadow' )) ) {
            $resets[] = 'background:none!important';
            $resets[] = 'border-radius:0!important';
            $resets[] = 'box-shadow:none!important';
        }
        if ( $this->navigationDeclaresAny($navigation, array( 'padding', 'padding-top', 'padding-bottom', 'padding-block', 'border-top', 'border-bottom', 'border-block', 'border-width', 'border-top-width', 'border-bottom-width' )) ) {
            $resets[] = 'padding:0!important';
            $resets[] = 'border:0!important';
        }
        // Core already zeroes the container's margin, but a source rule keyed on
        // the menu class outranks that reset and offsets the list inside its own
        // nav. The `nav` keeps the source spacing.
        if ( $this->navigationDeclaresAny($navigation, array( 'margin', 'margin-top', 'margin-bottom', 'margin-block' )) ) {
            $resets[] = 'margin:0!important';
        }
        if ( array() === $resets ) {
            return;
        }

        // Descendant, not child: core nests the container inside its responsive
        // wrapper, so a child combinator never reaches it.
        $selector = '.wp-block-navigation' . CssIdent::compoundClassSelector($authorClasses) . ' .wp-block-navigation__container';
        $this->sourceTargetProjection->record(SourceDom::elementSelector($navigation), $selector, implode(';', $resets));
    }

    /**
     * Does the source navigation itself state any of these properties?
     *
     * @param array<int, string> $properties
     */
    private function navigationDeclaresAny(DOMElement $navigation, array $properties): bool
    {
        foreach ( $properties as $property ) {
            $value = $this->navigationItemPresentationValue($navigation, $navigation, $property);
            if ( '' !== $value && ! in_array(strtolower($value), array( 'none', 'transparent', '0', '0px', 'rgba(0, 0, 0, 0)', 'medium none', '0 none' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve an item's presentation from within the navigation only.
     *
     * The search is bounded by the navigation element: anything above it is
     * document chrome that the destination theme legitimately supplies, and
     * reading it would recover the destination's own default rather than the
     * source's menu styling.
     */
    private function navigationItemPresentationValue(DOMElement $anchor, DOMElement $navigation, string $property): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) {
            return '';
        }

        $node  = $anchor;
        $depth = 0;
        while ( $node instanceof DOMElement && $depth < 12 ) {
            ++$depth;
            $resolved     = $this->styleResolver->resolveCssVariablesInValue(
                $this->styleResolver->specificityResolvedPresentationStyle($node)
            );
            $declarations = $this->styleResolver->cssDeclarations($resolved);
            $value        = trim((string) ($declarations[$property] ?? ''));
            if ( '' === $value ) {
                $value = $this->styleResolver->conditionalDeclaration($node, $property);
            }
            if ( '' === $value ) {
                $value = $this->styleResolver->unsupportedSelectorDeclaration($node, $property);
            }
            if ( '' !== $value
                && ! in_array(strtolower($value), array( 'inherit', 'unset', 'initial', 'revert', 'revert-layer' ), true)
                && ! preg_match('~[{}<>;]|/\*|(?:expression|url)\s*\(|javascript\s*:~i', $value)
            ) {
                return $value;
            }
            if ( $node->isSameNode($navigation) ) {
                break;
            }
            $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null;
        }

        return '';
    }
}
