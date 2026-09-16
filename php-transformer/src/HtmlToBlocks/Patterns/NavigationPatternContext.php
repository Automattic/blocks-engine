<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ProjectedNavigationConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\SourceTargetProjectionState;
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

    public function resolvedDisplay(DOMElement $element): string
    {
        return $this->styleResolver?->resolvedConditionalDisplay($element) ?? '';
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
     * Marker for an icon-only navigation anchor whose artwork core cannot save.
     *
     * core/navigation-link stores only a label and URL, so a source anchor whose
     * visible content is an inline SVG loses that artwork. The owning transformer
     * registers the recovered presentation and returns an opaque marker class.
     */
    public function linkIconMarker(DOMElement $element): string
    {
        if ( ! $this->svgMaterializer instanceof SvgMaterializer
            || ! $this->styleResolver instanceof StyleResolver
            || ! $this->session instanceof HtmlTransformerSession
        ) {
            return '';
        }

        // Only an anchor with no visible text is described by its icon. An
        // anchor that also shows a word keeps that word as its presentation.
        if ( '' !== trim($element->textContent ?? '') ) {
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

        $declarations = 'display:inline-block;' . $box
            . ';background-image:url("data:image/svg+xml,' . rawurlencode($markup) . '")'
            . ';background-repeat:no-repeat;background-position:center;background-size:contain'
            . ';font-size:0;line-height:0;color:transparent';
        $marker = 'blocks-engine-navigation-link-icon-' . substr(hash('sha256', $declarations), 0, 12);
        $this->session->generatedSupportStylesheetState()->registerNavigationLinkIcon($marker, $declarations);

        return $marker;
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

        $declarations = $this->styleResolver->cssDeclarations(
            $this->styleResolver->resolveCssVariablesInValue(
                $this->styleResolver->specificityResolvedPresentationStyle($svg)
            )
        );
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

        $anchor = null;
        foreach ( $navigation->getElementsByTagName('a') as $candidate ) {
            if ( $candidate instanceof DOMElement ) {
                $anchor = $candidate;
                break;
            }
        }
        if ( ! $anchor instanceof DOMElement ) {
            return;
        }

        $declarations = array();
        // `font` first: builders commonly state menu type as the shorthand, and
        // a longhand found further out should not silently outrank it.
        foreach ( array( 'font', 'color', 'font-family', 'font-size', 'font-weight', 'font-style', 'letter-spacing', 'text-transform' ) as $property ) {
            $value = $this->navigationItemPresentationValue($anchor, $navigation, $property);
            // The source component resolves this formula against its own width.
            // Replaying it on Core's replacement anchor changes that reference
            // and inflates the label. The promoted item retains the source
            // wrapper class, so the anchor can inherit the original value.
            if ( in_array($property, array( 'font', 'font-size' ), true) && str_contains($value, '--scaling-factor') ) {
                continue;
            }
            if ( '' !== $value ) {
                $declarations[] = $property . ':' . $this->navigationProjectionValue($value);
            }
        }
        if ( array() === $declarations ) {
            return;
        }

        $selector = '.wp-block-navigation.' . implode('.', $authorClasses) . ' .wp-block-navigation-item__content';
        $this->sourceTargetProjection->record(SourceDom::elementSelector($navigation), $selector, implode(';', $declarations));
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
        $selector = '.wp-block-navigation.' . implode('.', $authorClasses) . ' .wp-block-navigation__container';
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
