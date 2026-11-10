<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ListStructure;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\WrapperCoalescer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/** Author-owned layout boundaries and their CSS attribute carriers. */
final class AuthorLayoutOwnership
{
    public const CSS_OWNED_FLOW_CLASS = 'blocks-engine-css-owned-flow';

    /** @var list<string> Inline grid declarations carried to the generated stylesheet for css-owned grids. */
    private const CSS_OWNED_GRID_CARRIER_PROPERTIES = array(
        'display', 'grid', 'grid-template', 'grid-template-areas', 'grid-template-columns',
        'grid-template-rows', 'grid-auto-flow', 'grid-auto-columns', 'grid-auto-rows',
        'gap', 'row-gap', 'column-gap', 'grid-row-gap', 'grid-column-gap',
        'align-content', 'align-items', 'justify-content', 'justify-items', 'place-content', 'place-items',
    );

    /** @var list<string> Inline flex declarations carried to the generated stylesheet for css-owned flex containers. */
    private const CSS_OWNED_FLEX_CARRIER_PROPERTIES = array(
        'display', 'flex-flow', 'flex-direction', 'flex-wrap', 'gap', 'row-gap', 'column-gap',
        'align-content', 'align-items', 'justify-content', 'place-content',
    );

    private const CSS_OWNED_LAYOUT_CLASS = 'blocks-engine-css-owned-layout';
    private const CSS_OWNED_GRID_CLASS = 'blocks-engine-css-owned-grid';

    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly WrapperCoalescer $wrapperCoalescer,
        private readonly HtmlTransformerSession $session,
        private readonly Runtime $runtime,
        private readonly ListStructure $listStructure
    ) {}

    /** @return array<string, mixed> */
    public function cssOwnedGroupAttributes(DOMElement $element, bool $carryOwnTextAlignment = false, bool $topologyChanged = false): array
    {
        // A fixed inline height can become a clipping constraint when conversion
        // replaces a CSS-owned container's direct children with block wrappers.
        $excludedGeometryProperties = $topologyChanged ? array( 'height' ) : array();
        $attrs = $this->styleResolver->presentationAttributes(
            $element,
            $excludedGeometryProperties,
            $carryOwnTextAlignment ? array( 'text-align' ) : array()
        );
        $layout = $attrs['layout'] ?? null;
        if ( is_array($layout) && 'grid' === (string) ($layout['type'] ?? '')
            && ( '' !== (string) ($layout['minimumColumnWidth'] ?? '') || isset($layout['columnCount']) )
        ) {
            // The source track list is exactly expressible as native grid
            // layout (repeat(auto-fill, minmax(<width>,1fr)) as
            // minimumColumnWidth, or N equal 1fr tracks as columnCount), so
            // WordPress owns the track geometry. Group save markup does not
            // serialize blockGap, so source gap remains stylesheet owned by
            // the normalization in createBlock().
            $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
            $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
            unset($style['spacing']['blockGap']);
            if ( empty($style['spacing']) ) {
                unset($style['spacing']);
            }
            $background = trim((string) ($declarations['background-color'] ?? $declarations['background'] ?? ''));
            if ( 1 === preg_match('/^(#[0-9a-f]{3,8}|[a-z][a-z-]*|(?:rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch|var)\([^()]*\))$/i', $background)
                && ! in_array(strtolower($background), array( 'none', 'inherit', 'initial', 'unset', 'revert', 'revert-layer' ), true)
                && ! isset($style['color']['background'])
                && ! $this->styleResolver->hasConditionalStyleFamily($element, 'background')
            ) {
                $style['color'] = array_merge(is_array($style['color'] ?? null) ? $style['color'] : array(), array( 'background' => $background ));
            }
            if ( array() !== $style ) {
                $attrs['style'] = $style;
            }

            return $this->markUnsafeFixedHeightTopologyChange($attrs, $topologyChanged);
        }

        if ( $this->isCssOwnedGridElement($element) ) {
            return $this->markUnsafeFixedHeightTopologyChange(
                $this->cssOwnedGridAttributes($element, $excludedGeometryProperties),
                $topologyChanged
            );
        }

        if ( $this->isCssOwnedFlexElement($element) ) {
            $attrs = $this->cssOwnedFlexAttributes($element, $excludedGeometryProperties);
        }

        unset($attrs['layout']);
        $attrs['className'] = SourceDom::mergeClassNames(
            (string) ($attrs['className'] ?? ''),
            self::CSS_OWNED_LAYOUT_CLASS
        );
        if ( ! $this->authorOwnsChildFlowSpacing($element) && ! $this->isStructuralListContext($element) ) {
            return $this->markUnsafeFixedHeightTopologyChange($attrs, $topologyChanged);
        }
        $attrs['className'] = SourceDom::mergeClassNames(
            (string) $attrs['className'],
            self::CSS_OWNED_FLOW_CLASS
        );
        $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
        $spacing = is_array($style['spacing'] ?? null) ? $style['spacing'] : array();
        if ( ! isset($spacing['blockGap']) ) {
            $spacing['blockGap'] = '0';
            $style['spacing'] = $spacing;
            $attrs['style'] = $style;
        }

        return $this->markUnsafeFixedHeightTopologyChange($attrs, $topologyChanged);
    }

    /**
     * Pass unsafe CSS-owned height exclusions to IntrinsicVisualMediaHeight::apply().
     * createBlock() strips this flag before serializing block markup.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function markUnsafeFixedHeightTopologyChange(array $attrs, bool $topologyChanged): array
    {
        if ( $topologyChanged ) {
            $attrs['unsafeFixedHeightTopologyChange'] = true;
        }

        return $attrs;
    }

    private function isStructuralListContext(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( in_array($tagName, array( 'ul', 'ol' ), true) && $this->listStructure->listContainsStructuralItemContent($element) ) {
            return true;
        }
        if ( 'li' === $tagName && $this->listStructure->isStructuralListItem($element) ) {
            return true;
        }

        for ( $ancestor = $element->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            $ancestorTag = strtolower($ancestor->tagName);
            if ( 'li' === $ancestorTag && $this->listStructure->isStructuralListItem($ancestor) ) {
                return true;
            }
            if ( in_array($ancestorTag, array( 'ul', 'ol' ), true) && $this->listStructure->listContainsStructuralItemContent($ancestor) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Author CSS owns a container's geometry when it establishes flex or grid.
     */
    public function isAuthorOwnedLayout(DOMElement $element): bool
    {
        if ( 0 === SourceDom::childElementCount($element) ) {
            return false;
        }
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        $display = CssValueInspector::comparable((string) ($declarations['display'] ?? ''));
        if ( in_array($display, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true) ) {
            return true;
        }

        foreach ( $this->styleResolver->styleRuleCandidates($element, 'conditional') as $rule ) {
            if ( $this->styleResolver->matchesCssSelector($element, $rule['selector']) && in_array(CssValueInspector::comparable((string) ($rule['declarations']['display'] ?? '')), array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true) ) {
                return true;
            }
        }

        return false;
    }

    public function isDirectChildOfAuthorOwnedLayout(DOMElement $element): bool
    {
        return $element->parentNode instanceof DOMElement && $this->isAuthorOwnedLayout($element->parentNode);
    }

    public function isDirectChildOfAuthorFlexLayout(DOMElement $element): bool
    {
        return $element->parentNode instanceof DOMElement
            && in_array($this->authoredDisplay($element->parentNode), array( 'flex', 'inline-flex' ), true);
    }

    public function requiresStandaloneInlineLayoutLeaf(DOMElement $element): bool
    {
        if ($this->runtimeIslands->retainsRuntimeTextLeafInRichText($element)) return true;
        if ( $this->isAtomicDirectInlineLayoutItem($element) ) {
            return true;
        }

        if ( ! $this->sourceElementClassifier->isInlineContentElement(strtolower($element->tagName))
            || '' === trim($this->runtime->stripAllTags(SourceDom::innerHtml($element))) ) {
            return false;
        }

        // Images keep their inline carrier; SVG uses the standalone leaf path.
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && 'img' === strtolower($descendant->tagName) ) {
                return false;
            }
        }

        // Keep a formatting run's carrier boxless before a block.
        if ( ! SourceDom::ancestorElement($element, 'li') instanceof DOMElement
            && $this->isDirectFormattingRunBeforeBlock($element)
        ) {
            return true;
        }

        // A lone styled inline must not add a paragraph line box and spacing.
        if ( ! SourceDom::ancestorElement($element, 'li') instanceof DOMElement
            && $this->isLoneStyledInlineInFlowWrapper($element)
        ) {
            return true;
        }

        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        $display = CssValueInspector::comparable((string) ($declarations['display'] ?? 'inline'));
        if ( 'block' === $display ) {
            return true;
        }

        if ( ! $this->wrapperCoalescer->isDirectChildOfStructuralLayout($element) ) {
            return false;
        }

        if ( 0 < $element->getElementsByTagName('svg')->length ) {
            return true;
        }

        $typographyProperties = array( 'font', 'font-family', 'font-size', 'font-style', 'font-variant', 'font-weight', 'letter-spacing', 'line-height', 'text-align', 'text-decoration', 'text-indent', 'text-shadow', 'text-transform', 'word-spacing' );
        if ( array() !== $declarations && array() === array_diff(array_keys($declarations), $typographyProperties) ) {
            return true;
        }

        foreach ( array( 'grid-column', 'grid-row', 'order', 'align-self', 'justify-self', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left' ) as $property ) {
            if ( CssValueInspector::isNonZero((string) ($declarations[$property] ?? '')) ) {
                return true;
            }
        }

        if ( SourceDom::ancestorElement($element, 'li') instanceof DOMElement ) {
            return false;
        }

        // A paragraph carrier can host selector-addressed phrasing without a group.
        return (array() !== $this->session->authorSelectorProjectionState()->semanticMarkersForElement($element));
    }

    public function isDirectFormattingRunBeforeBlock(DOMElement $element): bool
    {
        $parent = $element->parentNode;
        if ( ! in_array(strtolower($element->tagName), array( 'abbr', 'b', 'cite', 'code', 'del', 'em', 'i', 'ins', 'kbd', 'mark', 's', 'samp', 'small', 'strong', 'sub', 'sup', 'time', 'u', 'var' ), true)
            || ! $parent instanceof DOMElement
            || $this->sourceElementClassifier->isInlineSourceElement(strtolower($parent->tagName))
        ) {
            return false;
        }

        for ( $sibling = $element->nextSibling; null !== $sibling; $sibling = $sibling->nextSibling ) {
            if ( XML_TEXT_NODE === $sibling->nodeType && '' === trim($sibling->textContent ?? '') ) {
                continue;
            }
            if ( XML_COMMENT_NODE === $sibling->nodeType ) {
                continue;
            }

            return $sibling instanceof DOMElement
                && ! $this->sourceElementClassifier->isInlineSourceElement(strtolower($sibling->tagName));
        }

        return false;
    }

    public function isLoneStyledInlineInFlowWrapper(DOMElement $element): bool
    {
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement
            || $this->isRichTextInlineContext($element)
            || $this->sourceElementClassifier->isInlineSourceElement(strtolower($parent->tagName))
            || ! ShellLandmarkPolicy::isInlineContentWrapperTag($parent->tagName)
            || ! $this->hasBoxChromeWrapperStyling($parent)
            || ( ! (array() !== $this->session->authorSelectorProjectionState()->semanticMarkersForElement($element)) && '' === $this->richTextMarker($element) )
        ) {
            return false;
        }

        foreach ( $parent->childNodes as $child ) {
            if ( $child === $element ) {
                continue;
            }
            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }
            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return false;
                }
                continue;
            }

            return false;
        }

        return true;
    }

    public function isAtomicDirectInlineLayoutItem(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( 'a' !== $tagName && ! $this->sourceElementClassifier->isInlineContentElement($tagName) ) {
            return false;
        }

        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement || ! $this->wrapperCoalescer->isStructuralLayoutElement($parent) ) {
            return false;
        }

        $itemCount = 0;
        $allowsAnchors = 'nav' === strtolower($parent->tagName);
        $hasNonAnchor = false;
        foreach ( $parent->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return false;
                }
                continue;
            }
            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }
            if ( ! $child instanceof DOMElement
                || ( ( ! $allowsAnchors || 'a' !== strtolower($child->tagName) ) && ! $this->sourceElementClassifier->isInlineContentElement(strtolower($child->tagName)) )
                || '' === trim($this->runtime->stripAllTags(SourceDom::innerHtml($child)))
            ) {
                return false;
            }
            $hasNonAnchor = $hasNonAnchor || 'a' !== strtolower($child->tagName);
            ++$itemCount;
        }

        // An all-anchor nav is represented by core/navigation. Only mixed-token
        // navigation (for example breadcrumbs) needs atomic inline carriers.
        return 2 <= $itemCount && ( ! $allowsAnchors || $hasNonAnchor );
    }

    public function isCssOwnedFlexElement(DOMElement $element): bool
    {
        $display = CssValueInspector::comparable((string) ($this->styleResolver->structuralPresentationDeclarations($element)['display'] ?? ''));

        return in_array($display, array( 'flex', 'inline-flex' ), true);
    }

    /**
     * Keep demoted flex geometry on CSS carriers so children do not stack.
     * Author-layout-conflicting inline display already rides a non-important
     * carrier; do not force it above authored !important rules.
     *
     * @return array<string, mixed>
     */
    public function cssOwnedFlexAttributes(DOMElement $element, array $excludedGeometryProperties = array()): array
    {
        $inlineDeclarations = $this->styleResolver->cssDeclarations(SourceDom::attr($element, 'style'));
        // Only an author-layout CONFLICT can safely demote the carrier's priority.
        // A display differing from the tag default alone offers no such guarantee.
        if ( $this->styleResolver->inlineDisplayConflictsWithAuthorLayout($element, $inlineDeclarations) ) {
            return $this->styleResolver->presentationAttributes($element, $excludedGeometryProperties);
        }

        // Carry only the inline-present properties so the fallback to
        // mapper-synthesized declarations cannot invent a `gap` that
        // overrides explicit row-gap/column-gap values.
        $carriedProperties = array_values(array_intersect(self::CSS_OWNED_FLEX_CARRIER_PROPERTIES, array_keys($inlineDeclarations)));

        return $this->styleResolver->presentationAttributes($element, $excludedGeometryProperties, $carriedProperties);
    }

    public function isCssOwnedGridElement(DOMElement $element): bool
    {
        $display = CssValueInspector::comparable((string) ($this->styleResolver->structuralPresentationDeclarations($element)['display'] ?? ''));

        return in_array($display, array( 'grid', 'inline-grid' ), true);
    }

    /**
     * Keep grids WordPress cannot express (including grids on lists) CSS-owned.
     * Inline geometry rides a carrier; class geometry stays in author CSS.
     *
     * @return array<string, mixed>
     */
    public function cssOwnedGridAttributes(DOMElement $element, array $excludedGeometryProperties = array()): array
    {
        // Carry only the inline-present properties so the fallback to
        // mapper-synthesized declarations cannot invent a `gap` that
        // overrides explicit row-gap/column-gap values.
        $inlineDeclarations = $this->styleResolver->cssDeclarations(SourceDom::attr($element, 'style'));
        $carriedProperties = array_values(array_intersect(self::CSS_OWNED_GRID_CARRIER_PROPERTIES, array_keys($inlineDeclarations)));
        $attrs = $this->styleResolver->presentationAttributes($element, $excludedGeometryProperties, $carriedProperties);
        unset($attrs['layout']);
        $attrs['className'] = SourceDom::mergeClassNames(
            (string) ($attrs['className'] ?? ''),
            self::CSS_OWNED_LAYOUT_CLASS,
            self::CSS_OWNED_GRID_CLASS
        );

        return $attrs;
    }

    public function authorOwnsChildFlowSpacing(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        foreach ( array( 'gap', 'row-gap', 'column-gap' ) as $property ) {
            if ( '' !== trim((string) ($declarations[$property] ?? '')) ) {
                return true;
            }
        }

        return false;
    }

    public function authoredDisplay(DOMElement $element): string
    {
        $display = '';
        foreach ( $this->styleResolver->styleRuleCandidates($element, 'static') as $rule ) {
            if ( isset($rule['declarations']['display']) && $this->styleResolver->matchesCssSelector($element, $rule['selector']) ) {
                $display = (string) $rule['declarations']['display'];
            }
        }

        $inline = $this->styleResolver->cssDeclarations(SourceDom::attr($element, 'style'));
        return CssValueInspector::comparable((string) ($inline['display'] ?? $display));
    }

    public function isRichTextInlineContext(DOMElement $element): bool
    {
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( in_array(strtolower($parent->tagName), array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li' ), true) ) {
                return true;
            }
            if ( '' !== trim($element->textContent ?? '') && in_array(strtolower($parent->tagName), array( 'a', 'button' ), true) && $this->session->authorSelectorProjectionState()->isControlPath($parent->getNodePath() ?? '') ) {
                return true;
            }
        }

        return false;
    }

    public function richTextMarker(DOMElement $element): string
    {
        $marker = trim(SourceDom::attr($element, 'data-blocks-engine-richtext-marker'));
        return '' !== $marker ? $marker : $this->session->authorSelectorProjectionState()->richTextMarker(SourceDom::sourceElementIdentity($element));
    }

    public function hasBoxChromeWrapperStyling(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        foreach ( array( 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border', 'border-color', 'border-radius', 'width', 'height', 'min-width', 'max-width', 'min-height' ) as $property ) {
            if ( isset($declarations[$property]) && CssValueInspector::isNonZero((string) $declarations[$property]) ) return true;
        }
        return false;
    }
}
