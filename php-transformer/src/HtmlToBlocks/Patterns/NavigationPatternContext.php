<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\MenuVocabulary;
use Automattic\BlocksEngine\PhpTransformer\Css\CssIdent;
use Automattic\BlocksEngine\PhpTransformer\Css\CssLength;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ProjectedNavigationConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\SourceTargetProjectionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EngineSupportCss;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\NavigationStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\CapturedMenuDialogFold;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NavigationToggleSuppressor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use DOMElement;

/** Navigation-only evidence and policy, backed by real collaborators. */
final class NavigationPatternContext
{
    use \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ResolvedPatternStyle;

    public function __construct(
        private readonly ?StyleResolver $styleResolver = null,
        private readonly ?NavigationStyleProjector $navigationStyleProjector = null,
        private readonly ?NavigationToggleSuppressor $navigationToggleSuppressor = null,
        private readonly ?RuntimeIslandAnalyzer $runtimeIslands = null,
        private readonly ?SourceTargetProjectionState $sourceTargetProjection = null,
        private readonly ?HtmlTransformerSession $session = null,
        private readonly ?SvgMaterializer $svgMaterializer = null,
        private readonly ?ProjectedNavigationConverter $projectedNavigation = null,
        private readonly NavigationUnderlineColorResolver $underlineColorResolver = new NavigationUnderlineColorResolver(),
        // A probe recognizes a menu only to classify its source element; the
        // conversion that emits the menu publishes its subjects.
        private readonly bool $publishesSubjects = true
    ) {
    }

    /**
     * Author-selector identities carried by a native navigation part, including
     * a retained label or a source list item replaced by Core's item.
     *
     * @return list<string>
     */
    public function sourcePresentationMarkers(DOMElement $element): array
    {
        $projections = $this->session?->authorSelectorProjectionState();
        if (null === $projections) return array();
        return array_values(array_filter(array_merge(
            $projections->semanticMarkersForPath($element->getNodePath() ?? ''),
            array($projections->tagMarker(strtolower($element->tagName)))
        )));
    }

    /** Block children lowered to spans need the same identity as inline carriers. */
    public function labelPresentationMarkers(DOMElement $element): array
    {
        $projections = $this->session?->authorSelectorProjectionState();
        if (null !== $projections && 1 === preg_match('/^(?:' . NavigationPattern::BLOCK_LEVEL_LABEL_TAGS . ')$/i', $element->tagName)
            // Icon/label composites already project their box onto the item.
            && 0 === $element->getElementsByTagName('svg')->length
            && !$this->ownsLabelTypography($element) && $this->hasNavigationSubjectPresentation($element)) {
            $path = $element->getNodePath() ?? '';
            $projections->ensureSemanticMarker($path);
        }
        $markers = $this->sourcePresentationMarkers($element);
        if (null !== $projections && 1 === preg_match('/^(?:' . NavigationPattern::BLOCK_LEVEL_LABEL_TAGS . ')$/i', $element->tagName)
            && array() !== $projections->semanticMarkersForPath($element->getNodePath() ?? '')) {
            // Retagging changes the UA display from block to inline. Restore
            // only that default, below author CSS (including responsive display).
            $marker = $projections->ensureSemanticMarker($element->getNodePath() ?? '');
            $this->session->generatedSupportStylesheetState()->registerRichTextPresentationalHint(
                $marker, ':where(.' . $marker . '){display:block}'
            );
        }
        // Core KSES filters label style attributes at render time. Use the
        // same generated geometry owner as block boxes so inline offsets (in
        // particular inset and its logical forms) survive that filtering.
        $geometry = $this->styleResolver?->inlineGeometryClassName($element) ?? '';
        if ('' !== $geometry) $markers[] = $geometry;
        return $markers;
    }

    /**
     * core/navigation renders some source elements as something else: a direct
     * anchor inside a list item of its own, a source `<li>` as core's item
     * without the source-type marker, and a menu list as the block itself.
     * Author selector projection needs to know which elements that happened
     * to, by AuthorSelectorProjectionState::NAVIGATION_* role.
     */
    public function recordNavigationSource(DOMElement $element, string $role, bool $rendersSourceSiblings = true): void
    {
        $this->session?->authorSelectorProjectionState()->markNavigationSource($element, $role, $rendersSourceSiblings);
    }

    /**
     * A source element whose presentation lands on one of the block's inner
     * parts: author rules that address it project onto that part instead of
     * the block wrapper.
     */
    public function recordInnerSubject(DOMElement $element, string $selector): void
    {
        $this->session?->authorSelectorProjectionState()->installInnerSubject($element->getNodePath() ?? '', $selector);
    }

    /** Source list identity also survives when its landmark owns another block. */
    public function navigationListHostIdentity(DOMElement $element, ?DOMElement $list = null, ?string $rowGap = null): string
    {
        $boxMarker = $this->navigationListBoxMarker($list);
        if ('' !== $boxMarker && null !== $rowGap && !$this->declaresColumns($list)) {
            // Core's flow layout does not render blockGap as a horizontal gap.
            // This projected list box supplies the flex row, so carry its gap
            // through the same support-style serializer as other rendered boxes.
            $gapStyle = array('spacing' => array('blockGap' => $rowGap));
            $this->projectSourceToNativeTarget($list, '.wp-block-navigation.' . $boxMarker,
                $this->styleResolver->styleAttributeMapper()->serialize($gapStyle)['style']);
        }
        if ('' !== $boxMarker && $list instanceof DOMElement) {
            $this->projectListSeparators($list, $boxMarker);
        }
        $identity = $this->sourceListHostIdentity($element);

        return trim($identity . ' ' . $boxMarker);
    }

    /** Marker for the native root that renders a source list's own box. */
    private function navigationListBoxMarker(?DOMElement $list): string
    {
        if (null === $this->session || !$list instanceof DOMElement || !$this->styleResolver instanceof StyleResolver) return '';
        $columns = $this->declaresColumns($list);
        if (!$columns && !array_filter(\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorSelectorProjectionState::NAVIGATION_LIST_BOX_PROPERTIES, fn(string $property): bool => !$this->styleResolver->declaredPresentation($list, $property)->isEmpty())) {
            return '';
        }

        // Named like the menu's items: by the list and the rules that match
        // it, so the shared header's list carries one marker on every route.
        return $this->session->authorSelectorProjectionState()->installNavigationListBoxSubject($list->getNodePath() ?? '', $columns ? 'block' : 'flex', $this->navigationSubjectMarker($list, 'list-box'));
    }

    /**
     * Decorative link siblings cannot be children of a navigation-link block.
     * A uniform trailing glyph belongs on the native item instead, leaving its
     * label editable and its saved markup entirely owned by Core.
     */
    private function projectListSeparators(DOMElement $list, string $marker): void
    {
        $items = array();
        foreach ($list->childNodes as $item) {
            if ($item instanceof DOMElement) $items[] = $item;
        }
        if (count($items) < 2) return;
        $separators = array();
        $signature = null;
        foreach ($items as $index => $item) {
            $children = array();
            foreach ($item->childNodes as $child) {
                if ($child instanceof DOMElement) $children[] = $child;
                elseif ('' !== trim($child->textContent ?? '')) return;
            }
            if ('li' !== strtolower($item->tagName) || !isset($children[0]) || 'a' !== strtolower($children[0]->tagName)) return;
            if ($index === count($items) - 1 && 1 === count($children)) continue;
            if (2 !== count($children)) return;
            $separator = $children[1];
            $glyph = $separator->textContent ?? '';
            if ('true' !== strtolower($separator->getAttribute('aria-hidden')) || $separator->getElementsByTagName('*')->length > 0 || '' === trim($glyph)) return;
            $style = $this->styleResolver->safeVisualDeclarations($this->styleResolver->resolvedPresentationDeclarations($separator));
            $current = array($glyph, $style);
            if (null !== $signature && $signature !== $current) return;
            $signature = $current;
            $separators[] = $separator;
        }
        if (null === $signature) return;
        $target = 'nav.wp-block-navigation.' . $marker . ' .wp-block-navigation-item:not(:last-child)::after';
        // CSS string escaping also handles whitespace and control characters;
        // never interpolate source text as raw CSS syntax.
        $content = '"' . preg_replace_callback('/["\\\\\x00-\x1f\x7f]/', static fn(array $m): string => '\\' . dechex(ord($m[0])) . ' ', $signature[0]) . '"';
        $this->projectSourceToNativeTarget($separators[0], $target, 'content:' . $content . ';' . $this->styleResolver->cssDeclarationString($signature[1]));
        foreach ($separators as $separator) {
            $this->recordInnerSubject($separator, $target);
        }
    }

    /**
     * Whether an author rule lays the source list's items out in columns.
     * Columns apply only to a block container, so such a list keeps a block
     * box where shown; any other shown list is the native root's flex row.
     * Read from the author rules: the resting cascade models no column layout.
     */
    private function declaresColumns(DOMElement $list): bool
    {
        $styles = $this->session->authorStyleAnalysis();
        foreach ($styles->styleRules() as $rule) {
            $columns = array_filter(array_intersect_key($rule['declarations'] ?? array(), array_flip(array('columns', 'column-count', 'column-width'))),
                static fn (string $value): bool => 1 !== preg_match('/^\s*auto(?:\s+auto)?\s*(?:!\s*important\s*)?$/i', $value));
            if (array() === $columns) continue;
            foreach ($rule['selectors'] ?? array() as $record) {
                $parsed = $record['parsed'] ?? array();
                if (!($parsed['supported'] ?? false)) continue;
                $match = $styles->selectorMatchCache()->matches($list, $record['selector'], $parsed);
                if (($match['supported'] ?? false) && ($match['matches'] ?? false)) return true;
            }
        }
        return false;
    }

    private function sourceListHostIdentity(DOMElement $element): string
    {
        if (null === $this->session || !in_array(strtolower($element->tagName), array('ul', 'ol'), true)) return '';
        $styles = $this->session->authorStyleAnalysis();
        foreach ($styles->styleRules() as $rule) {
            foreach ($rule['selectors'] ?? array() as $record) {
                $parsed = $record['parsed'] ?? array();
                $compounds = $parsed['compounds'] ?? array();
                if (!($parsed['supported'] ?? false) || array() === $compounds) continue;
                $subject = $compounds[array_key_last($compounds)];
                $subjects = array();
                if (in_array(strtolower((string) ($subject['type'] ?? '')), array('ul', 'ol'), true)) {
                    $subjects[] = $element;
                } elseif (count($compounds) > 1 && '>' === ($parsed['combinators'][count($compounds) - 2] ?? null)
                    && in_array(strtolower((string) ($compounds[count($compounds) - 2]['type'] ?? '')), array('ul', 'ol'), true)) {
                    // An item rule through this list (`#menu > ul > li`) addresses
                    // the native root by the list's identity, then core's container.
                    foreach ($element->childNodes as $child) if ($child instanceof DOMElement) $subjects[] = $child;
                }
                foreach ($subjects as $candidate) {
                    $match = $styles->selectorMatchCache()->matches($candidate, $record['selector'], $parsed);
                    if (($match['supported'] ?? false) && ($match['matches'] ?? false)) { $projections = $this->session->authorSelectorProjectionState(); return implode(' ', $projections->semanticCarrierClasses($projections->ensureSemanticMarker($element->getNodePath() ?? ''), $element->getNodePath() ?? '')); }
                }
            }
        }
        return '';
    }

    /** Marks a block element inside a link label that paints the label text itself. */
    public const LABEL_TYPOGRAPHY_BOX_CLASS = 'blocks-engine-label-typography';

    /**
     * Marks a submenu whose source wrapper clips it to nothing at rest
     * (`height:0;overflow:hidden`). Core's single submenu list absorbs that
     * wrapper and the padded list inside it, so the closed list must drop its
     * block-axis padding and border to keep the source's zero footprint.
     */
    public const CLIPPED_SUBMENU_CLASS = 'blocks-engine-clipped-submenu';

    /**
     * Whether a block-level element inside a navigation link declares its own
     * text presentation. Such an element (a logo heading) is what paints the
     * label, so it must survive the label's inline reduction.
     */
    public function serializedInlineStyle(DOMElement $element, ?DOMElement $item = null): string
    {
        $style = $this->styleResolver?->serializedInlineStyle($element) ?? SourceDom::attr($element, 'style');
        if (null === $item || !$this->styleResolver instanceof StyleResolver) return $style;

        // Core replaces the item's label wrappers. Keep the inline tokens they
        // supply in the anchor's scope, without overriding its own definitions.
        $properties = array();
        for ($node = $element->parentNode; $node instanceof DOMElement && !$node->isSameNode($item); $node = $node->parentNode) {
            $properties += $this->styleResolver->consumedInlineCustomProperties($node);
        }
        $properties = array_intersect_key($this->styleResolver->cascadedCustomProperties($element), $properties);
        return $this->styleResolver->cssDeclarationString($properties) . (array() === $properties ? '' : ';') . $style;
    }

    public function ownsLabelTypography(DOMElement $element): bool
    {
        if ( ! $this->styleResolver instanceof StyleResolver || 1 !== preg_match('/^(?:' . NavigationPattern::BLOCK_LEVEL_LABEL_TAGS . ')$/i', $element->tagName) ) {
            return false;
        }
        // Resolve text leaves fully. A composite icon/label surface already has
        // its box projected onto the navigation item; preserve its established
        // presentation boundary instead of replaying that box inside the label.
        $hasElementChildren = false;
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $hasElementChildren = true;
                break;
            }
        }
        $declarations = $hasElementChildren
            ? $this->styleResolver->presentationDeclarations($element)
            : $this->styleResolver->withoutRedundantInheritedTypography($element, $this->styleResolver->cssDeclarations($this->styleResolver->specificityResolvedPresentationStyle($element)));
        foreach ( array( 'font-family', 'font-size', 'font-weight', 'letter-spacing', 'text-transform', 'color', 'line-height', 'font-style' ) as $property ) {
            if ( '' !== trim((string) ($declarations[ $property ] ?? '')) ) {
                return true;
            }
        }
        return false;
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

    /** @return array<string, string> */
    public function resolvedDeclarations(DOMElement $element): array
    {
        return $this->styleResolver?->cssDeclarations($this->resolvedStyle($element)) ?? array();
    }

    /** The element's own presentation as block style attributes (colors, spacing, border). */
    public function resolvedStyleAttributes(DOMElement $element): array
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) {
            return array();
        }

        return $this->styleResolver->styleAttributeMapper()->map($this->resolvedDeclarations($element))['style'] ?? array();
    }

    /**
     * The generated content (`::before`/`::after`) a source rule paints on this
     * element: unquoted, escape-decoded, and empty when nothing paints.
     */
    public function generatedPseudoContent(DOMElement $element, string $pseudo): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver || null === $this->session ) {
            return '';
        }

        foreach ( $this->session->sourceStyleResolutionState()->pseudoElementRules() as $rule ) {
            if ( strtolower($pseudo) !== strtolower((string) ($rule['pseudo'] ?? '')) ) {
                continue;
            }
            $content = trim((string) ($rule['declarations']['content'] ?? ''));
            if ( '' === $content || in_array(strtolower($content), array( 'none', 'normal' ), true) ) {
                continue;
            }
            if ( ! $this->styleResolver->matchesCssSelector($element, (string) ($rule['selector'] ?? '')) ) {
                continue;
            }
            return self::decodeCssContent($content);
        }

        return '';
    }

    /** The source path of the document being compiled, for route-relative resolution. */
    public function sourcePath(): string
    {
        return $this->session?->sourcePath() ?? '';
    }

    private static function decodeCssContent(string $value): string
    {
        if ( 1 === preg_match('/^([\'"])(.*)\1$/s', $value, $match) ) {
            $value = $match[2];
        }

        return (string) preg_replace_callback(
            '/\\\\([0-9a-fA-F]{1,6})(?:\r\n|[ \t\r\n\f])?/',
            static function (array $match): string {
                $code = hexdec($match[1]);
                return $code > 0 && $code < 0x110000 ? mb_chr($code, 'UTF-8') : '';
            },
            $value
        );
    }

    /** Horizontal border-box extent of a content-free box; unknown lengths refuse carry. */
    public function emptyBoxWidth(DOMElement $element): ?float
    {
        if (null === $this->styleResolver) return null;
        $declarations = $this->resolvedDeclarations($element);
        $mapped = $this->styleResolver->styleAttributeMapper()->map($declarations)['style'];
        $extent = 0.0;
        foreach (array('left', 'right') as $side) {
            $padding = CssLength::toPx((string) ($mapped['spacing']['padding'][$side] ?? '0'));
            $border = $mapped['border'][$side] ?? $mapped['border'] ?? array();
            $borderWidth = in_array($border['style'] ?? 'none', array('none', 'hidden'), true)
                ? 0.0 : CssLength::toPx((string) ($border['width'] ?? 'medium'));
            if (null === $padding || null === $borderWidth) return null;
            $extent += $padding + $borderWidth;
        }
        $widthValue = $declarations['width'] ?? 'auto';
        $width = 'auto' === $widthValue ? 0.0 : CssLength::toPx($widthValue);
        if (null === $width) return null;
        return 'border-box' === $this->resolvedBoxSizing($element)
            ? max($extent, $width) : $extent + $width;
    }

    public function resolvedBoxSizing(DOMElement $element): string
    {
        return (string) ($this->styleResolver?->referenceViewportAuthorDeclarations($element, array('box-sizing'))['box-sizing'] ?? 'content-box');
    }

    /**
     * The element's resolved style on routes where its item is not current:
     * rules that address current-item state do not apply, nor does the inline
     * style of an element that carries the current state itself.
     */
    public function restingStyle(DOMElement $element): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver || ! $this->session instanceof HtmlTransformerSession ) {
            return $this->resolvedStyle($element);
        }
        $projections = $this->session->authorSelectorProjectionState();
        $carriesState = $element->hasAttribute('aria-current') || MenuVocabulary::containsCurrentStateToken($element->getAttribute('class'));

        return $this->styleResolver->resolveCssVariablesInValue(
            $this->styleResolver->specificityResolvedPresentationStyle($element, $projections->selectsCurrentNavigationState(...), ! $carriesState),
            $element
        );
    }

    /**
     * Whether a navigation href names the document being compiled. Client
     * routers (a React Router NavLink, for one) often mark the current item
     * only through utility classes, with no aria-current or active/current
     * token; the link targeting its own page is then the only current signal.
     *
     * Site-rooted hrefs (`/about/index.html`) resolve against the artifact's
     * entry root, the single leading segment of the source path.
     *
     * A link with a fragment names a section, not the page: shared chrome
     * linking to `/index.html#services` must compile the same on the route
     * holding that section as on every other route.
     */
    public function targetsCurrentDocument(string $href): bool
    {
        $document = $this->session?->sourcePath() ?? '';
        $href = trim($href);
        if ( ArtifactPath::namesSection($href) ) {
            return false;
        }
        $href = ArtifactPath::stripQueryAndFragment($href);
        if ( '' === $href || ! str_contains($document, '.') || 1 === preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $href) ) {
            return false;
        }
        $rooted = str_starts_with($href, '/');
        $path = ArtifactPath::resolveRelativePath($rooted ? ltrim($href, '/') : $href, $rooted ? '' : $document);
        if ( '' === $path && ! ($rooted || str_ends_with($href, '/')) ) {
            return false;
        }
        $candidates = '' === $path || str_ends_with($href, '/')
            ? array( ltrim($path . '/index.html', '/') )
            : array( $path, $path . '/index.html', $path . '.html' );
        $slash = strpos($document, '/');
        $withinEntryRoot = false === $slash ? '' : substr($document, $slash + 1);
        foreach ( $candidates as $candidate ) {
            if ( $document === $candidate || ($rooted && $withinEntryRoot === $candidate && ! str_contains(substr($document, 0, (int) $slash), '/')) ) {
                return true;
            }
        }

        return false;
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
     * A declaration with media variants is not carried: a static restatement
     * would freeze the base breakpoint's value over every other width.
     *
     * Background and padding are never inherited, so a value resolved here was
     * owned by the source anchor itself: a box authored on the source list
     * item resolves nothing here and stays where the author put it.
     *
     * @param ?DOMElement $sourceItem The source element core's item stands in
     *                                for, when it is not the anchor itself.
     * @param bool $atRest Resolve the box the item has where it is not current.
     */
    public function navigationLinkBoxMarker(DOMElement $anchor, ?DOMElement $sourceItem, bool $atRest = false): string
    {
        $style = $atRest ? $this->restingStyle(...) : $this->resolvedStyle(...);
        if ( ! $this->styleResolver instanceof StyleResolver
            || ! $this->session instanceof HtmlTransformerSession
        ) {
            return '';
        }

        // A side with media variants stays with the author rule: core renders
        // the source class on the item, where those variants still win.
        $declarations = $this->styleResolver->classOwnedResponsiveDeclarations($anchor, $this->styleResolver->cssDeclarations($style($anchor)));
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
            : $this->styleResolver->cssDeclarations($style($sourceItem));
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

    /**
     * Carry a replaced disclosure glyph's own box, not the label or panel box.
     * Only a unique SVG outside the link and submenu is an unambiguous trigger;
     * failing that, a unique SVG trailing the link's label is its indicator.
     * A glyph the source hides at rest paints nothing and stands in for nothing.
     */
    public function submenuIconGeometryMarker(DOMElement $item, DOMElement $anchor, DOMElement $submenu): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver || ! $this->session instanceof HtmlTransformerSession ) return '';
        $candidates = array();
        foreach ( $item->getElementsByTagName('svg') as $svg ) {
            $excluded = false;
            for ( $node = $svg; $node instanceof DOMElement; $node = $node->parentNode ) {
                if ( $node->isSameNode($item) ) break;
                if ( $node->isSameNode($anchor) || $node->isSameNode($submenu) || $this->styleResolver->isVisuallyHiddenAtRest($node) ) { $excluded = true; break; }
            }
            if ( ! $excluded ) $candidates[] = $svg;
        }
        $inAnchor = array() === $candidates;
        if ( $inAnchor ) $candidates = $this->trailingAnchorGlyphs($anchor);
        if ( 1 !== count($candidates) ) return '';
        $svg = $candidates[0];
        $container = $svg->parentNode;
        if ( ! $container instanceof DOMElement || ( ! $inAnchor && ( $container->isSameNode($item) || $container->isSameNode($anchor->parentNode) ) ) ) return '';

        $properties = array('margin', 'padding', 'border', 'border-width', 'width', 'height', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width', 'box-sizing');
        // A glyph directly inside the link has no box of its own: the link's
        // box is the label's, which core's content already carries.
        $box = $container->isSameNode($anchor) ? array() : $this->styleResolver->referenceViewportAuthorDeclarations($container, $properties);
        $glyph = $this->styleResolver->referenceViewportAuthorDeclarations($svg, $properties);
        // CSS dimensions override the SVG's presentational width/height hints.
        foreach ( array('width', 'height') as $dimension ) {
            if ( ! isset($glyph[$dimension]) ) $glyph[$dimension] = $svg->getAttribute($dimension) . 'px';
        }
        $safe = static function (array $values) use ($properties): array {
            $result = array();
            foreach ( $properties as $property ) {
                $value = trim((string) ($values[$property] ?? ''));
                if ( 'border' === $property && 1 === preg_match('/^(0|[0-9]*\\.?[0-9]+px)\\s+solid\\s+(?:transparent|#0000)$/D', $value, $border) ) {
                    $result[$property] = $border[1] . ' solid transparent';
                } elseif ( 1 === preg_match('/^(?:(?:0|[0-9]*\\.?[0-9]+px)(?:\\s+(?:0|[0-9]*\\.?[0-9]+px)){0,3}|auto|border-box|content-box)$/D', $value) ) {
                    $result[$property] = $value;
                }
            }
            return $result;
        };
        $box = $safe($box);
        $glyph = $safe($glyph);
        if ( ! isset($glyph['width'], $glyph['height']) || ( array() === $box && ! $inAnchor ) ) return '';
        $serialize = static function (array $values): string {
            $css = '';
            foreach ( $values as $property => $value ) $css .= $property . ':' . $value . ';';
            return $css;
        };
        // Auto sizing lets the source glyph margins and container border form
        // an intrinsic box when the author did not specify an explicit width.
        $box = array_merge(array('width' => 'auto', 'height' => 'auto', 'margin' => '0', 'padding' => '0', 'border' => '0 solid transparent', 'box-sizing' => 'content-box', 'flex-shrink' => '0', 'display' => 'flex', 'align-items' => 'center', 'justify-content' => 'center'), $box);
        $glyph = array_merge(array('margin' => '0', 'padding' => '0', 'flex-shrink' => '0'), $glyph);
        $placement = $inAnchor ? array() : $this->outOfFlowSubmenuIconPlacement($item, $container);
        $box = array_merge($box, $placement);
        $marker = 'blocks-engine-submenu-icon-' . substr(hash('sha256', $serialize($box) . $serialize($glyph)), 0, 16);
        $this->session->generatedSupportStylesheetState()->registerNavigationSubmenuIconGeometry($marker, $serialize($box), $serialize($glyph));
        if ( array() === $placement ) $this->session->authorSelectorProjectionState()->markNavigationToggleRowItem($item->getNodePath() ?? '');
        return $marker;
    }

    /**
     * An absolutely positioned glyph box sits beside the label, outside its
     * row (Google Sites places the chevron in the item box's left padding).
     * Core's toggle is a child of the item, so the source insets are re-based
     * from the glyph's containing block onto the item. Core's item is a flex
     * item and contains its children's margins, so the containing block sits
     * inside it by the margins, borders and padding of the boxes between them:
     * lengths shift by that offset, and percentages are rescaled from the
     * containing block to the item. A containing block outside the item, or
     * edges that are not px lengths, keep the toggle in Core's row.
     *
     * @return array<string, string>
     */
    private function outOfFlowSubmenuIconPlacement(DOMElement $item, DOMElement $container): array
    {
        $read = fn(DOMElement $element, array $properties): array => $this->styleResolver->referenceViewportAuthorDeclarations($element, $properties);
        $own = $read($container, array('position', 'left', 'right', 'top', 'bottom', 'transform'));
        if ( 'absolute' !== strtolower(trim((string) ($own['position'] ?? ''))) ) return array();

        $chain = array();
        $containingBlock = null;
        for ( $node = $container->parentNode; $node instanceof DOMElement && ! $node->isSameNode($item); $node = $node->parentNode ) {
            $chain[] = $node;
            if ( null === $containingBlock && 'static' !== strtolower(trim((string) ($read($node, array('position'))['position'] ?? 'static'))) ) $containingBlock = $node;
        }
        if ( ! $node instanceof DOMElement ) return array();
        $chain = null === $containingBlock ? array() : array_slice($chain, (int) array_search($containingBlock, $chain, true));

        // Side lengths in px (top, right, bottom, left); null when one is not a px length.
        $sides = static function (array $declarations, string $property): ?array {
            $values = isset($declarations[$property]) ? CssValueInspector::expandBoxShorthand((string) $declarations[$property]) : array('0', '0', '0', '0');
            foreach ( array('top', 'right', 'bottom', 'left') as $index => $side ) {
                $longhand = 'border-width' === $property ? 'border-' . $side . '-width' : $property . '-' . $side;
                if ( isset($declarations[$longhand]) ) $values[$index] = (string) $declarations[$longhand];
            }
            $lengths = array();
            foreach ( $values as $value ) {
                if ( 1 !== preg_match('/^(-?[0-9]*\\.?[0-9]+)px$|^0$/D', trim($value), $match) ) return null;
                $lengths[] = (float) ($match[1] ?? 0);
            }
            return $lengths;
        };
        $edgeProperties = array('margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border-width', 'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width');
        // The item's padding edge is the reference; the containing block's
        // own padding edge is the target.
        $itemPadding = $sides($read($item, $edgeProperties), 'padding');
        if ( null === $itemPadding ) return array();
        $offset = array_combine(array('top', 'right', 'bottom', 'left'), $itemPadding);
        foreach ( $chain as $box ) {
            $declarations = $read($box, $edgeProperties);
            $margin = $sides($declarations, 'margin');
            $padding = $sides($declarations, 'padding');
            $border = $sides($declarations, 'border-width');
            if ( null === $margin || null === $padding || null === $border ) return array();
            foreach ( array('top', 'right', 'bottom', 'left') as $index => $side ) {
                $offset[$side] += $margin[$index] + $border[$index] + ( $box->isSameNode($containingBlock) ? 0 : $padding[$index] );
            }
        }

        $placement = array('position' => 'absolute');
        $number = static fn(float $value): string => rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
        foreach ( array('left' => 'right', 'right' => 'left', 'top' => 'bottom', 'bottom' => 'top') as $side => $opposite ) {
            $value = strtolower(trim((string) ($own[$side] ?? '')));
            if ( '' === $value || 'auto' === $value ) continue;
            if ( 1 === preg_match('/^(-?[0-9]*\\.?[0-9]+)px$|^0$/D', $value, $match) ) {
                $placement[$side] = $number((float) ($match[1] ?? 0) + $offset[$side]) . 'px';
            } elseif ( 1 === preg_match('/^(-?[0-9]*\\.?[0-9]+)%$/D', $value, $match) ) {
                $share = (float) $match[1] / 100;
                $shift = $offset[$side] - $share * ( $offset[$side] + $offset[$opposite] );
                $placement[$side] = 0.0 === $shift ? $value : 'calc(' . $value . ' + ' . $number($shift) . 'px)';
            } else {
                return array();
            }
        }
        $transform = trim((string) ($own['transform'] ?? ''));
        if ( 1 === preg_match('/^(?:translate[XY]?\(\s*-?[0-9.]+(?:px|%)?(?:\s*,\s*-?[0-9.]+(?:px|%)?)?\s*\)\s*)+$/D', $transform) ) $placement['transform'] = $transform;
        return $placement;
    }

    /**
     * The link's SVGs when it has exactly one and no label text follows it.
     *
     * @return list<DOMElement>
     */
    private function trailingAnchorGlyphs(DOMElement $anchor): array
    {
        $svgs = array();
        foreach ( $anchor->getElementsByTagName('svg') as $svg ) $svgs[] = $svg;
        if ( 1 !== count($svgs) ) return array();
        for ( $node = $svgs[0]; $node instanceof DOMElement && ! $node->isSameNode($anchor); $node = $node->parentNode ) {
            for ( $next = $node->nextSibling; null !== $next; $next = $next->nextSibling ) {
                if ( '' !== trim($next->textContent) ) return array();
            }
        }
        return $svgs;
    }

    /** @var array<string, true> Source submenu toggles that paint their own indicator. */
    private array $submenuToggleIndicators = array();

    /** @var array<string, true> Source submenu parents that paint no indicator of their own and carry no disclosure control. */
    private array $unindicatedSubmenuParents = array();

    /**
     * Whether the source submenu takes no space until its item's hover/focus
     * reveals it: a zero height or max-height that clips its content.
     */
    public function clipsSubmenuAtRest(DOMElement $item, DOMElement $submenu): bool
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) return false;
        $zero = false;
        foreach ( array( 'height', 'max-height' ) as $property ) {
            $zero = $zero || CssValueInspector::isZeroLength($this->styleResolver->declaredPresentation($submenu, $property)->base());
        }
        if ( ! $zero ) return false;
        // Overflow is structural, outside the visual rule store; read the author's own declarations.
        $clips = false;
        foreach ( $this->styleResolver->authorDeclaredValuesAtAnyViewport($submenu, array( 'overflow', 'overflow-y' )) as $values ) {
            foreach ( $values as $value ) {
                $clips = $clips || 1 === preg_match('/^(?:hidden|clip)\b/', CssValueInspector::comparable($value));
            }
        }
        return $clips && $this->revealsSubmenuOnItemState($item, $submenu);
    }

    /**
     * Whether the source reveals the submenu through an interaction state on
     * its item (`li:hover .subnav{height:auto}`): the submenu opens on
     * hover/focus, so the item's label is a hover target, not a toggle.
     */
    public function revealsSubmenuOnItemState(DOMElement $item, DOMElement $submenu): bool
    {
        if ( ! $this->styleResolver instanceof StyleResolver || null === $this->session ) return false;
        foreach ( $this->session->sourceStyleResolutionState()->revealStateRules() as $rule ) {
            if ( ! in_array((string) ($rule['state'] ?? ''), array( 'hover', 'focus', 'focus-within' ), true) ) continue;
            $revealed = array_intersect_key((array) ($rule['declarations'] ?? array()), array_flip(array( 'display', 'visibility', 'height', 'max-height', 'opacity' )));
            if ( array() === $revealed
                || ! $this->styleResolver->matchesCssSelector($submenu, (string) ($rule['base_selector'] ?? ''))
                || ! $this->styleResolver->matchesCssSelector($item, (string) ($rule['state_subject_selector'] ?? '')) ) continue;
            return true;
        }
        return false;
    }

    /**
     * Record the source element core's submenu toggle stands in for. When its
     * own generated content (`::after`/`::before`) paints a visible indicator
     * (text, a border (a CSS caret) or a background), that indicator is
     * reproduced on core's content. A parent that paints nothing and carries
     * no disclosure control (a button standing in for the label or opening
     * the submenu beside it) leaves the submenu indicator-free: its submenu
     * opens on hover/focus.
     */
    public function recordSubmenuToggle(DOMElement $toggle, bool $hasDisclosureControl): void
    {
        if ( ! $this->styleResolver instanceof StyleResolver || null === $this->session ) return;
        $targets = array( $toggle );
        foreach ( $toggle->childNodes as $child ) {
            if ( $child instanceof DOMElement ) $targets[] = $child;
        }
        foreach ( $this->session->sourceStyleResolutionState()->pseudoElementRules() as $rule ) {
            if ( ! in_array((string) ($rule['pseudo'] ?? ''), array( 'before', 'after' ), true) || ! $this->paintsGeneratedIndicator($rule['declarations'] ?? array()) ) continue;
            foreach ( $targets as $target ) {
                if ( $this->styleResolver->matchesCssSelector($target, (string) ($rule['selector'] ?? '')) ) {
                    $this->submenuToggleIndicators[$toggle->getNodePath() ?? ''] = true;
                    return;
                }
            }
        }
        if ( ! $hasDisclosureControl ) {
            $this->unindicatedSubmenuParents[$toggle->getNodePath() ?? ''] = true;
        }
    }

    /** Whether the source paints nothing for this control at rest (a focus-only affordance). */
    public function isVisuallyHiddenControl(DOMElement $control): bool
    {
        return $this->styleResolver instanceof StyleResolver && $this->styleResolver->isVisuallyHiddenAtRest($control);
    }

    /** Whether a toggle under this source element paints its own indicator. */
    public function hasSubmenuToggleIndicatorWithin(DOMElement $source): bool
    {
        $root = $source->getNodePath() ?? '';
        if ( '' === $root ) return false;
        foreach ( $this->submenuToggleIndicators as $path => $_ ) {
            if ( $path === $root || str_starts_with((string) $path, $root . '/') ) return true;
        }
        return false;
    }

    /** A submenu parent whose source paints its own indicator glyph, which core's icon would duplicate. */
    public function recordSubmenuIndicator(DOMElement $toggle): void
    {
        $this->submenuToggleIndicators[$toggle->getNodePath() ?? ''] = true;
    }

    /**
     * A form control does not inherit text presentation: the user agent
     * resets these properties on it. A native item standing in for one keeps
     * those resets wherever the control's own rules state nothing.
     */
    public function formControlTextResets(DOMElement $control): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) return '';
        $own = $this->styleResolver->cssDeclarations($this->resolvedStyle($control));
        $resets = array();
        foreach ( array( 'text-transform' => 'none', 'letter-spacing' => 'normal', 'word-spacing' => 'normal', 'text-indent' => '0', 'text-shadow' => 'none' ) as $property => $initial ) {
            if ( ! isset($own[$property]) && '' === $this->styleResolver->conditionalDeclaration($control, $property) ) $resets[] = $property . ':' . $initial;
        }
        return implode(';', $resets);
    }

    /**
     * Font declarations a glyph moved into a control's label needs to keep
     * the font it inherited beside the control, not the control's own.
     */
    public function indicatorTypography(DOMElement $indicator, DOMElement $control): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) return '';
        $declarations = array();
        foreach ( array( 'font-family' => '', 'font-size' => '', 'font-weight' => 'normal', 'font-style' => 'normal' ) as $property => $initial ) {
            $own = $this->styleResolver->resolvedInheritedTypographyValue($indicator, $property);
            $label = $this->styleResolver->resolvedInheritedTypographyValue($control, $property);
            $own = '' === $own ? $initial : $own;
            if ( '' !== $own && '' !== $label && $own !== $label && ! preg_match('/[{}<>;"]/', $own) ) $declarations[] = $property . ':' . $own;
        }
        return implode(';', $declarations);
    }

    /** Whether a submenu parent under this source element paints no indicator and carries no disclosure control. */
    public function hasUnindicatedSubmenuParentWithin(DOMElement $source): bool
    {
        $root = $source->getNodePath() ?? '';
        if ( '' === $root ) return false;
        foreach ( $this->unindicatedSubmenuParents as $path => $_ ) {
            if ( $path === $root || str_starts_with((string) $path, $root . '/') ) return true;
        }
        return false;
    }

    /** @param array<string, mixed> $declarations */
    private function paintsGeneratedIndicator(array $declarations): bool
    {
        $content = trim((string) ($declarations['content'] ?? ''));
        if ( '' === $content || in_array(strtolower($content), array( 'none', 'normal' ), true) ) return false;
        if ( 'none' === strtolower(trim((string) ($declarations['display'] ?? ''))) ) return false;
        $text = trim($content, " \t\"'");
        if ( '' !== $text && 1 !== preg_match('/^(?:no-)?(?:open|close)-quote$/i', $text) ) return true;
        foreach ( $declarations as $property => $value ) {
            $property = strtolower((string) $property);
            $value = strtolower(trim((string) $value));
            if ( in_array($property, array( 'background', 'background-color', 'background-image' ), true) && ! in_array($value, array( '', 'none', 'transparent' ), true) ) return true;
            // A border side paints (a CSS caret) when it is wide, styled and not
            // transparent; longhand widths take their side's or the shared color.
            if ( 1 !== preg_match('/^border(?:-(top|right|bottom|left))?(-width)?$/', $property, $border) ) continue;
            if ( 1 !== preg_match('/(?:^|\s)(?:[0-9]*\.?[0-9]*[1-9][0-9]*(?:px|em|rem)|thin|medium|thick)(?:\s|$)/', $value) || 1 === preg_match('/\b(?:none|hidden)\b/', $value) ) continue;
            $color = isset($border[2]) ? strtolower(trim((string) ($declarations['border-' . ('' !== $border[1] ? $border[1] . '-' : '') . 'color'] ?? $declarations['border-color'] ?? ''))) : $value;
            if ( ! str_contains($color, 'transparent') ) return true;
        }
        return false;
    }

    /** @var array<string, array{marker: string, target: string, source?: string}> */
    private array $pendingNavigationSubjects = array();

    /**
     * Candidate link construction can be abandoned by a later sibling. Only
     * publish a correspondence when its native carrier is actually emitted;
     * equal markers in another header do not prove this source path converted.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public function recordNavigationSubjects(array &$blocks, DOMElement $source): void
    {
        if (!$this->publishesSubjects) {
            return;
        }
        // Keep distinct source presentations on their structural carriers.
        // Equally styled sibling runs share one structural address, which
        // survives menu consolidation even when placement markers differ.
        $targets = $levelCounts = array();
        $collect = static function (array $items, string $parent) use (&$collect, &$targets, &$levelCounts): void {
            $position = 0;
            $levelCounts[$parent] = count(array_filter($items, static fn(array $item): bool => in_array($item['blockName'] ?? '', array('core/navigation-link', 'core/navigation-submenu'), true)));
            foreach ($items as $block) {
                if (!in_array($block['blockName'] ?? '', array('core/navigation-link', 'core/navigation-submenu'), true)) continue;
                $item = $parent . '>.wp-block-navigation-item:nth-child(' . (++$position) . ')';
                foreach (SourceDom::tokens($block['attrs']['className'] ?? '') as $marker) {
                    if (!str_starts_with($marker, 'blocks-engine-navigation-')) continue;
                    $targets[$marker][$item] = true;
                }
                $boxTarget = $item;
                foreach ($block['attrs']['metadata']['blocksEngineNavigationAnchor']['boxes'] ?? array() as $box) {
                    $boxTarget .= '>' . $box['tag'];
                    $targets[$box['marker']][$boxTarget] = true;
                }
                $collect($block['innerBlocks'] ?? array(), $item . '>.wp-block-navigation__submenu-container');
            }
        };
        foreach ($blocks as &$block) {
            if ('core/navigation' !== ($block['blockName'] ?? '')) {
                if (!empty($block['innerBlocks'])) {
                    $this->recordNavigationSubjects($block['innerBlocks'], $source);
                }
                continue;
            }
            $current = $this->withoutCurrentItemPresentation($block);
            $targets = $levelCounts = array();
            $collect($block['innerBlocks'] ?? array(), '');
            foreach ($targets as $marker => $pathSet) {
                // A carrier can repeat a marker through inherited presentation.
                // Count distinct siblings, not marker occurrences, when deciding
                // whether a complete level can use an unindexed address.
                $paths = array_keys($pathSet);
                foreach (array_reverse($levelCounts, true) as $parent => $count) {
                    $pattern = '/^' . preg_quote($parent, '/') . '>\\.wp-block-navigation-item:nth-child\\([0-9]+\\)(.*)$/';
                    $groups = array();
                    foreach ($paths as $path) {
                        if (preg_match($pattern, $path, $match)) $groups[$match[1]][] = $path;
                    }
                    foreach ($groups as $suffix => $members) {
                        if (count($members) !== $count || $count < 2) continue;
                        $paths = array_values(array_diff($paths, $members));
                        $paths[] = $parent . '>.wp-block-navigation-item' . $suffix;
                    }
                }
                $targets[$marker] = str_starts_with($marker, 'blocks-engine-navigation-anchor-') && count($pathSet) > 1
                    ? array(' .wp-block-navigation-item.' . $marker)
                    : $paths;
            }
            if (array() === $targets && array() === $current) {
                $this->publishInheritedNavigationPresentation($block, $source, '');
                continue;
            }
            // The instance names the resting menu: per-item link colours follow
            // the served route, and current-item presentation is lifted above.
            $resting = array_filter($targets, static fn(string $marker): bool => 1 !== preg_match('/^blocks-engine-navigation-(?:current-color|link-color(?:-states)?)-/', $marker), ARRAY_FILTER_USE_KEY);
            // An ancestor may record this same emitted block again. Its first
            // correspondence owns the instance and all subsequent projections.
            preg_match('/(?:^|\s)(blocks-engine-navigation-instance-[a-f0-9]{12}-[0-9]+)(?:\s|$)/', (string) ($block['attrs']['className'] ?? ''), $existing);
            // Instance identity describes resting subjects, not how many of
            // their structural addresses collapsed on this captured route.
            foreach ($resting as $marker => $addresses) {
                $resting[$marker] = array(' .wp-block-navigation-item' . (str_starts_with($marker, 'blocks-engine-navigation-box-') ? ' .' : '.') . $marker);
            }
            // Subjects form a set: swapping the current item can change their
            // discovery order without changing the menu that shared chrome keeps.
            ksort($resting);
            $scope = $existing[1] ?? $this->session->authorStyleAnalysis()->allocateStableMarker('navigation-instance', $this->navigationSourceIdentity($source) . json_encode($resting));
            $block['attrs']['className'] = SourceDom::mergeClassNames($block['attrs']['className'] ?? '', $scope);
            $this->recordCurrentItemPresentation($current, $scope, $source);
            $markup = (string) json_encode($block);
            // Without a submenu no item holds another item's links.
            $hasSubmenus = str_contains($markup, '"core\/navigation-submenu"');
            $root = $source->getNodePath() ?? '';
            $this->publishInheritedNavigationPresentation($block, $source, $scope);
            foreach ($this->pendingNavigationSubjects as $path => $subject) {
                if ($path !== $root && !str_starts_with($path, $root . '/')) continue;
                $marker = $subject['marker'];
                if (1 !== preg_match('/(?<![A-Za-z0-9_-])' . preg_quote($marker, '/') . '(?![A-Za-z0-9_-])/', $markup)) continue;
                $target = $subject['target'];
                if (isset($targets[$marker])) {
                    $itemTargets = array_map(static fn(string $path): string => '.wp-block-navigation.' . $scope . ' .wp-block-navigation__container' . $path, array_unique($targets[$marker]));
                    $itemTarget = count($itemTargets) > 1 ? ':is(' . implode(',', $itemTargets) . ')' : $itemTargets[0];
                    // Anchor subjects add Core's content anchor to the item target;
                    // projected source boxes already have their structural path.
                    $target = str_contains($target, '.wp-block-navigation-item__content')
                        ? self::itemContentTarget($itemTarget, $hasSubmenus)
                        : $itemTarget;
                }
                $this->session?->authorSelectorProjectionState()->installNavigationSubject($path, $target);
                if (isset($subject['source'])) {
                    // Restore the source anchor's initial stacking below authored
                    // paint, but only when Core really supplies its replacement.
                    $this->sourceTargetProjection?->record($subject['source'], $target, 'z-index:auto');
                }
            }
        }
        unset($block);
    }

    /**
     * Chrome can be compiled alone or inside a route, without a landmark.
     * Name its menu by source-owned identity, never sibling offsets. Stop at
     * the landmark or top-level chrome root, excluding route/body wrappers.
     * Resting subjects further distinguish menus with different contents.
     */
    private function navigationSourceIdentity(DOMElement $source): string
    {
        $identity = array();
        $projections = $this->session->authorSelectorProjectionState();
        for ($node = $source; $node instanceof DOMElement; $node = $node->parentNode) {
            $tag = strtolower($node->tagName);
            if (in_array($tag, array('html', 'body'), true)) break;
            $classes = SourceDom::tokens($node->getAttribute('class'));
            $classes = array_values(array_filter($classes, static fn(string $class): bool => '' !== $class && !str_starts_with($class, 'blocks-engine-') && !$projections->isCurrentNavigationClass($class, $node) && !$projections->selectsCurrentNavigationState('.' . $class, $node)));
            sort($classes);
            $role = SourceDom::role($node);
            $identity[] = array($tag, $node->getAttribute('id'), $classes, $role);
            if (in_array($tag, array('header', 'footer'), true) || in_array($role, array('banner', 'contentinfo'), true)) break;
        }
        return (string) json_encode($identity);
    }

    /** @var array<string, array{content: string, reset: string, state_mask: int}> Current-item presentation by item marker. */
    private array $currentItemPresentation = array();

    /**
     * The anchor box a current item's state adds to its resting box: every
     * background and padding declaration whose current winner differs from
     * the resting one, shorthands expanded, with the item padding reset that
     * {@see self::navigationLinkBoxMarker()} applies to a carried side.
     *
     * @param ?DOMElement $sourceItem The source element core's item stands in for.
     * @return array{content: string, item_reset: string}
     */
    public function currentItemBox(DOMElement $anchor, ?DOMElement $sourceItem): array
    {
        $box = function (string $style): array {
            $declarations = $this->styleResolver?->cssDeclarations($style) ?? array();
            $sides = array( 'padding-top', 'padding-right', 'padding-bottom', 'padding-left' );
            if ( isset($declarations['padding']) ) {
                $declarations += array_combine($sides, CssValueInspector::expandBoxShorthand((string) $declarations['padding']));
            }
            return array_intersect_key($declarations, array_flip(array_merge(array( 'background-color' ), $sides)));
        };
        $resting = $box($this->restingStyle($anchor));
        $content = array();
        $reset = array();
        $item = null === $sourceItem ? array() : $box($this->restingStyle($sourceItem));
        foreach ( $box($this->resolvedStyle($anchor)) as $property => $value ) {
            $value = trim((string) $value);
            if ( $value === trim((string) ($resting[$property] ?? '')) || ! $this->safeNavigationBoxValue($property, $value) ) continue;
            $content[] = $property . ':' . $value;
            if ( 'background-color' === $property || null === $sourceItem ) continue;
            $itemValue = trim((string) ($item[$property] ?? ''));
            $reset[] = $property . ':' . ( $this->safeNavigationBoxValue($property, $itemValue) ? $itemValue : '0' );
        }

        return array( 'content' => implode(';', $content), 'item_reset' => implode(';', $reset) );
    }

    /**
     * Marker for what an authored current item adds to its resting
     * presentation. It only locates the item's level for
     * {@see self::recordNavigationSubjects()}, which lifts it off the item.
     *
     * @param int $stateMask Interaction states the item's resting colour rule excludes.
     */
    public function currentItemPresentationMarker(string $content, string $reset, int $stateMask = 0): string
    {
        $marker = 'blocks-engine-navigation-current-item-' . substr(hash('sha256', $content . "\0" . $reset . "\0" . $stateMask), 0, 16);
        $this->currentItemPresentation[$marker] = array('content' => $content, 'reset' => $reset, 'state_mask' => $stateMask);
        return $marker;
    }

    /**
     * Lift current-item presentation markers off the current item, so every
     * route serializes the same menu and navigation markup.
     *
     * @param array<string, mixed> $navigation
     * @return array<string, array{content: list<string>, reset: string, state_mask: int}> Presentation by item level.
     */
    private function withoutCurrentItemPresentation(array &$navigation): array
    {
        if (array() === $this->currentItemPresentation) return array();
        $presentation = array();
        $strip = function (array &$items, string $level) use (&$strip, &$presentation): void {
            foreach ($items as &$item) {
                $classes = SourceDom::tokens((string) ($item['attrs']['className'] ?? ''));
                foreach ($classes as $index => $class) {
                    if (!isset($this->currentItemPresentation[$class])) continue;
                    unset($classes[$index]);
                    $presentation[$level]['content'][] = $this->currentItemPresentation[$class]['content'];
                    if ('' !== $this->currentItemPresentation[$class]['reset']) $presentation[$level]['reset'] = $this->currentItemPresentation[$class]['reset'];
                    $presentation[$level]['state_mask'] = ($presentation[$level]['state_mask'] ?? 0) | $this->currentItemPresentation[$class]['state_mask'];
                    $item['attrs']['className'] = implode(' ', $classes);
                }
                if (!empty($item['innerBlocks'])) $strip($item['innerBlocks'], 'submenu');
            }
            unset($item);
        };
        $strip($navigation['innerBlocks'], 'top');
        return array_map(static fn(array $level): array => array('content' => array_values(array_unique($level['content'])), 'reset' => $level['reset'] ?? '', 'state_mask' => $level['state_mask'] ?? 0), $presentation);
    }

    /**
     * Emit current-item presentation once per navigation instance, keyed on
     * what core renders on the current item at runtime (`current-menu-item`,
     * `aria-current="page"`) at the level the source's current item sat.
     *
     * Every item also carries its resting colour and box for the routes where
     * it is not current, so the rule doubles the instance scope and excludes
     * the interaction states those resting rules exclude: it outranks them as
     * the source's current-state rule outranks its resting rule.
     *
     * @param array<string, array{content: list<string>, reset: string, state_mask: int}> $presentation
     */
    private function recordCurrentItemPresentation(array $presentation, string $scope, DOMElement $source): void
    {
        foreach ($presentation as $level => $declarations) {
            $item = '.wp-block-navigation.' . $scope . '.' . $scope . ' ' . ('top' === $level ? '.wp-block-navigation__container' : '.wp-block-navigation__submenu-container') . '>.wp-block-navigation-item';
            $states = NavigationStyleProjector::navigationColorRestingSuffix($declarations['state_mask']);
            $anchor = '>.wp-block-navigation-item__content[aria-current="page"]';
            $this->sourceTargetProjection?->record(SourceDom::elementSelector($source), $item . '.current-menu-item>.wp-block-navigation-item__content' . $states . ',' . $item . $anchor . $states, implode(';', $declarations['content']));
            $this->sourceTargetProjection?->record(SourceDom::elementSelector($source), $item . '.current-menu-item,' . $item . ':has(' . $anchor . ')', $declarations['reset']);
        }
    }

    /**
     * An item's own content, through any retained boxes, but not the links in
     * its submenu: those are other items. The exclusion adds no specificity,
     * and a menu without submenus has nothing to exclude.
     */
    private static function itemContentTarget(string $item, bool $hasSubmenus = true): string
    {
        $content = $item . ' :where(.wp-block-navigation-item__content)';
        return $hasSubmenus ? $content . ':not(:where(' . $item . ' .wp-block-navigation__submenu-container *))' : $content;
    }

    /** The source anchor remains a distinct CSS subject inside Core's item. */
    public function navigationAnchorSubjectMarker(DOMElement $anchor, ?DOMElement $item = null): string
    {
        if (!$this->session instanceof HtmlTransformerSession || !$this->styleResolver instanceof StyleResolver
            || $this->isRuntimeDomTarget($anchor)
        ) return '';
        $presented = $this->hasNavigationSubjectPresentation($anchor) || ($item instanceof DOMElement && $this->hasNavigationSubjectPresentation($item));
        for ($node = $anchor->parentNode; !$presented && $node instanceof DOMElement && $item instanceof DOMElement && !$node->isSameNode($item); $node = $node->parentNode) {
            $presented = $this->hasNavigationSubjectPresentation($node);
        }
        if (!$presented) return '';
        $marker = $this->navigationSubjectMarker($anchor, 'anchor');
        $target = self::itemContentTarget('.wp-block-navigation .wp-block-navigation-item.' . $marker);
        $this->pendingNavigationSubjects[$anchor->getNodePath() ?? ''] = array('marker' => $marker, 'target' => $target, 'source' => SourceDom::elementSelector($anchor));
        if ($this->inlineAnchorCarriesPercentageHeight($anchor)) {
            // Core inserts a block anchor with auto height and a label span.
            // Neither existed as a containing block in the source. Let the
            // percentage-sized block child keep sizing through both carriers
            // against the source ancestor (including retained navigation boxes).
            $this->sourceTargetProjection?->record(SourceDom::elementSelector($anchor), $target, 'height:100%');
            $this->sourceTargetProjection?->record(SourceDom::elementSelector($anchor), $target . '>.wp-block-navigation-item__label', 'display:block;height:100%');
        }
        return $marker;
    }

    /** Carry an out-of-flow label's sizing independently of anchor subject splitting. */
    public function navigationLabelWidthMarker(DOMElement $anchor): string
    {
        if (!$this->session instanceof HtmlTransformerSession || !$this->styleResolver instanceof StyleResolver
            || $this->isRuntimeDomTarget($anchor)) return '';
        $width = $this->absoluteLabelSizingWidth($anchor);
        if ('' === $width) return '';
        $marker = $this->navigationSubjectMarker($anchor, 'label-width');
        $target = self::itemContentTarget('.wp-block-navigation .wp-block-navigation-item.' . $marker);
        // Out-of-flow layers contribute no intrinsic width to Core's flex
        // item. Size the new carriers without changing placement or height.
        $source = SourceDom::elementSelector($anchor);
        $this->sourceTargetProjection?->record($source, '.wp-block-navigation .wp-block-navigation-item.' . $marker, 'width:' . $width . ';flex-shrink:0');
        $this->sourceTargetProjection?->record($source, $target, 'width:' . $width);
        $this->sourceTargetProjection?->record($source, $target . '>.wp-block-navigation-item__label', 'display:block;width:100%');
        return $marker;
    }

    /**
     * Restore an authored sizing box only when the label has no intrinsic
     * in-flow content. Reach an item-local ancestor through stretching boxes,
     * never crossing a list boundary or a box shared with another item.
     */
    private function absoluteLabelSizingWidth(DOMElement $anchor): string
    {
        $anchorStyle = $this->styleResolver->referenceViewportAuthorDeclarations($anchor, array('width'));
        if (isset($anchorStyle['width']) && 'auto' !== $anchorStyle['width']) return '';
        $outOfFlow = false;
        if ($this->labelHasIntrinsicContent($anchor, $outOfFlow) || !$outOfFlow) return '';
        for ($node = $anchor; $node instanceof DOMElement; $node = $node->parentNode) {
            $style = $this->styleResolver->referenceViewportAuthorDeclarations($node, array('display', 'width', 'position', 'left', 'right'));
            $width = trim((string) ($style['width'] ?? ''));
            if (CssValueInspector::isAbsoluteLength($width)) return $width;
            if (!$this->isStretchingLabelBox($node, $style)) break;
            $parent = $node->parentNode;
            if (!$parent instanceof DOMElement
                || in_array(strtolower($parent->tagName), array('nav', 'ul', 'ol', 'menu', 'body', 'header'), true)
                || in_array(strtolower($parent->getAttribute('role')), array('navigation', 'menubar', 'menu', 'list'), true)) break;
            // A wrapper with another element or in-flow text is not an
            // exclusive sizing box for this item, even if it has a width.
            foreach ($parent->childNodes as $child) {
                if ($child->isSameNode($node) || XML_COMMENT_NODE === $child->nodeType) continue;
                if ($child instanceof DOMElement && 'none' === ($this->styleResolver->referenceViewportAuthorDeclarations($child, array('display'))['display'] ?? '')) continue;
                if ($child instanceof DOMElement || '' !== trim($child->textContent)) break 2;
            }
        }
        // Some captures put the definite sizing box inside the anchor instead.
        return $this->absoluteDescendantLabelWidth($anchor, false);
    }

    /** Ignore out-of-flow paint, but never force a width over in-flow text or artwork. */
    private function labelHasIntrinsicContent(DOMElement $node, bool &$outOfFlow): bool
    {
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                if ('' !== trim($child->textContent)) return true;
                continue;
            }
            $style = $this->styleResolver->referenceViewportAuthorDeclarations($child, array('position', 'display'));
            if ('none' === ($style['display'] ?? '')) continue;
            if (in_array($style['position'] ?? '', array('absolute', 'fixed'), true)) {
                $outOfFlow = true;
                continue;
            }
            if (in_array(strtolower($child->tagName), array('img', 'svg', 'video', 'canvas', 'input'), true)
                || $this->labelHasIntrinsicContent($child, $outOfFlow)) return true;
        }
        return false;
    }

    /** @param array<string, string> $style */
    private function isStretchingLabelBox(DOMElement $node, array $style): bool
    {
        $width = trim((string) ($style['width'] ?? ''));
        if ('100%' === $width) return true;
        if (!in_array($width, array('', 'auto'), true)) return false;
        if (in_array($style['position'] ?? '', array('absolute', 'fixed'), true)) {
            return in_array($style['left'] ?? '', array('0', '0px'), true) && in_array($style['right'] ?? '', array('0', '0px'), true);
        }
        $display = $style['display'] ?? (1 === preg_match('/^(?:' . NavigationPattern::BLOCK_LEVEL_LABEL_TAGS . ')$/i', $node->tagName) ? 'block' : 'inline');
        return in_array($display, array('block', 'flex', 'grid', 'flow-root'), true);
    }

    /** Find a definite descendant sizing box without assuming a single-child chain. */
    private function absoluteDescendantLabelWidth(DOMElement $node, bool $outOfFlow): string
    {
        $widths = array();
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            $style = $this->styleResolver->referenceViewportAuthorDeclarations($child, array('width', 'position', 'display'));
            if ('none' === ($style['display'] ?? '')) continue;
            $absolute = $outOfFlow || in_array($style['position'] ?? '', array('absolute', 'fixed'), true);
            $width = trim((string) ($style['width'] ?? ''));
            if (CssValueInspector::isAbsoluteLength($width)) {
                if ($absolute) $widths[] = $width;
                continue;
            }
            if (!in_array($width, array('', 'auto', '100%'), true)) continue;
            $width = $this->absoluteDescendantLabelWidth($child, $absolute);
            if ('' !== $width) $widths[] = $width;
        }
        $widths = array_values(array_unique($widths));
        return 1 === count($widths) ? $widths[0] : '';
    }

    /** Only an inline source link lets a block child's height bypass it. */
    private function inlineAnchorCarriesPercentageHeight(DOMElement $anchor): bool
    {
        if ('a' !== strtolower($anchor->tagName)) return false;
        $anchorStyle = $this->styleResolver->referenceViewportAuthorDeclarations($anchor, array('display', 'position'));
        if ('inline' !== ($anchorStyle['display'] ?? 'inline')
            || in_array($anchorStyle['position'] ?? 'static', array('absolute', 'fixed'), true)) return false;
        foreach ($anchor->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            $style = $this->styleResolver->referenceViewportAuthorDeclarations($child, array('height', 'display', 'position'));
            $display = $style['display'] ?? (1 === preg_match('/^(?:' . NavigationPattern::BLOCK_LEVEL_LABEL_TAGS . ')$/i', $child->tagName) ? 'block' : 'inline');
            if (in_array($display, array('block', 'flex', 'grid', 'flow-root'), true)
                && !in_array($style['position'] ?? 'static', array('absolute', 'fixed'), true)
                && 1 === preg_match('/^\d+(?:\.\d+)?%$/D', trim((string) ($style['height'] ?? '')))) return true;
        }
        return false;
    }

    private function hasNavigationSubjectPresentation(DOMElement $element): bool
    {
        if ('' !== trim($element->getAttribute('style')) && array() !== $this->styleResolver->presentationDeclarations($element)) return true;
        foreach ($this->session->authorStyleAnalysis()->styleRules() as $rule) {
            if (array() === $rule['declarations']) continue;
            foreach ($rule['selectors'] as $selector) {
                $text = (string) ($selector['selector'] ?? '');
                $suffix = $selector['parsed']['pseudo_state_suffix_span'] ?? null;
                if (is_array($suffix)) $text = substr($text, 0, (int) $suffix['start']);
                // Current-only paint does not make an item a presented subject;
                // its resting peers decide, so every route shapes it alike.
                if ('' !== $text && !$this->session->authorSelectorProjectionState()->selectsCurrentNavigationState($text, $element) && $this->styleResolver->matchesCssSelector($element, $text)) return true;
            }
        }
        return false;
    }

    /**
     * The served route's item is one of its menu's items, not a different
     * one. Record what marks it current, so its identity and its current-only
     * paint can follow the served route instead of this snapshot: a state
     * class its item or anchor carries that its peers do not. A peer-unique
     * class such as a per-item language hook is the item's own, not state,
     * unless it names state or replaces resting classes: when peers share
     * one class list and the served item keeps part of it but swaps the rest
     * for classes no peer carries (atomic utility classes, say), those
     * classes are its state. Explicit aria-current on that same element also
     * identifies its peer-unique classes as state, even with no shared token
     * or multiple resting peers (a complete utility-class swap).
     */
    public function recordCurrentNavigationItem(DOMElement $item, DOMElement $anchor): void
    {
        if (!$this->session instanceof HtmlTransformerSession || !$item->parentNode instanceof DOMElement) return;
        $peerItems = array();
        foreach ($item->parentNode->childNodes as $sibling) {
            if ($sibling instanceof DOMElement && !$sibling->isSameNode($item) && $sibling->tagName === $item->tagName) $peerItems[] = $sibling;
        }
        if (array() === $peerItems) return;
        $peerAnchors = array();
        foreach ($peerItems as $peer) {
            $peerAnchor = $item->isSameNode($anchor) ? $peer : ($peer->getElementsByTagName($anchor->tagName)->item(0));
            if ($peerAnchor instanceof DOMElement) $peerAnchors[] = $peerAnchor;
        }
        $classes = static fn(DOMElement $element): array => array_values(array_unique(array_filter(SourceDom::tokens($element->getAttribute('class')), static fn(string $class): bool => '' !== $class)));
        $restingClasses = array();
        $own = static function (DOMElement $element, array $peers) use ($classes, &$restingClasses): array {
            $lists = array();
            foreach ($peers as $peer) {
                $list = $classes($peer);
                sort($list);
                $lists[implode(' ', $list)][] = $list;
            }
            $current = $classes($element);
            $resting = array_filter($lists, static fn(array $group): bool => count($group) > 1 && array() !== array_intersect($group[0], $current) && array() !== array_diff($group[0], $current));
            $replaces = array() !== $resting;
            // A peer wearing every class the item swaps in over its resting
            // peers shares the served route's state (an overflow parent holding
            // the served item), so it is no evidence those classes rest.
            $swapped = $replaces ? array_diff($current, reset($resting)[0]) : array();
            // What the resting peers wear in place of those classes is resting
            // state: the served item does not have it.
            if ($replaces) foreach (array_diff(reset($resting)[0], $current) as $class) $restingClasses[] = $class;
            $peerClasses = array();
            foreach ($lists as $group) {
                if (array() !== $swapped && array() === array_diff($swapped, $group[0])) continue;
                foreach ($group[0] as $class) $peerClasses[$class] = true;
            }
            return array_values(array_filter($current, static fn(string $class): bool => !isset($peerClasses[$class])
                && ($replaces || ($element->hasAttribute('aria-current') && 'false' !== strtolower(trim($element->getAttribute('aria-current')))) || MenuVocabulary::containsCurrentStateToken($class))));
        };
        // Source boxes between the item and its link can carry the state
        // (Google Sites marks its item box `aria-current`): compare each with
        // the box at the same depth in every peer.
        $boxClasses = array();
        $depth = 0;
        for ($box = $anchor->parentNode; $box instanceof DOMElement && !$box->isSameNode($item); $box = $box->parentNode) {
            $depth++;
            $peerBoxes = array();
            foreach ($peerAnchors as $peerAnchor) {
                $peerBox = $peerAnchor;
                for ($step = 0; $step < $depth && $peerBox instanceof DOMElement; $step++) $peerBox = $peerBox->parentNode;
                if ($peerBox instanceof DOMElement && $peerBox->tagName === $box->tagName) $peerBoxes[] = $peerBox;
            }
            if (array() !== $peerBoxes) $boxClasses = array_merge($boxClasses, $own($box, $peerBoxes));
        }
        $projections = $this->session->authorSelectorProjectionState();
        $projections->markCurrentNavigationClasses(array_merge($own($item, $peerItems), $item->isSameNode($anchor) ? array() : $own($anchor, $peerAnchors), $boxClasses));
        $projections->markRestingNavigationClasses($restingClasses);
    }

    public function navigationItemSubjectMarker(DOMElement $item): string
    {
        if (!$this->session instanceof HtmlTransformerSession) return '';
        $marker = $this->navigationSubjectMarker($item, 'item');
        $this->pendingNavigationSubjects[$item->getNodePath() ?? ''] = array('marker' => $marker, 'target' => '.wp-block-navigation .wp-block-navigation-item.' . $marker);
        // The item's inline style travels as render-time metadata, which the
        // editor never applies. The marker's identity includes that style, so
        // one marker always names one declaration list.
        $declarations = array();
        if ($this->styleResolver instanceof StyleResolver && '' !== trim($item->getAttribute('style'))) {
            foreach ($this->styleResolver->cssDeclarations($item->getAttribute('style')) as $property => $value) {
                $value = trim(CssValueInspector::withoutImportant((string) $value));
                if (!preg_match('/^-{0,2}[a-z][a-z0-9-]*$/i', (string) $property) || '' === $value || preg_match('/[{};<>\\@]|url\s*\(/i', $value)) continue;
                // Inline declarations outrank every normal author rule.
                $declarations[] = strtolower((string) $property) . ':' . $value . '!important';
            }
        }
        $this->session->generatedSupportStylesheetState()->registerNavigationEditorItemStyle($marker, implode(';', $declarations));
        return $marker;
    }

    /**
     * A submenu parent's boxes may also hold the source disclosure glyph beside
     * the link; Core's submenu icon renders in its stead, so the glyph does not
     * stop its box from carrying the label's presentation.
     *
     * @return list<array{tag: string, marker: string, style: string, className: string}>
     */
    public function navigationAnchorBoxes(DOMElement $anchor, DOMElement $item, bool $submenuParent = false): array
    {
        if (!$this->session instanceof HtmlTransformerSession) return array();
        $boxes = array();
        for ($node = $anchor->parentNode; $node instanceof DOMElement && !$node->isSameNode($item); $node = $node->parentNode) {
            $tag = strtolower($node->tagName);
            // A `<label>` holding the link is an inline box like a span; Core's
            // anchor cannot sit inside a label, so it keeps its paint as one.
            if ('label' === $tag) $tag = 'span';
            if (!in_array($tag, array('div', 'span'), true)) return array();
            $children = array();
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    // The item's own submenu toggle shares the row; it is not a second label.
                    if ('button' === strtolower($child->tagName) && in_array(strtolower($child->getAttribute('aria-haspopup')), array('true', 'menu'), true)) continue;
                    if ($submenuParent && self::isDisclosureGlyph($child, $anchor)) continue;
                    $children[] = $child;
                } elseif (XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '')) return array();
            }
            if (1 !== count($children)) return array();
            $marker = $this->navigationSubjectMarker($node, 'box');
            $boxes[] = array('tag' => $tag, 'marker' => $marker, 'style' => $node->getAttribute('style'), 'className' => SourceDom::mergeClassNames($node->getAttribute('class'), implode(' ', $this->sourcePresentationMarkers($node))));
        }
        if (array() !== $boxes && $this->styleResolver instanceof StyleResolver) {
            // These carriers exist only at frontend render time. Resolve the
            // link's own cascade too, so a link override beats box inheritance.
            foreach (array_merge(array(array()), $this->styleResolver->navigationEditorTypographyConditions($anchor)) as $conditions) {
                $typography = array();
                foreach (array('font-family', 'font-size', 'font-weight', 'letter-spacing', 'line-height', 'color', 'text-transform', 'text-align') as $property) {
                    $value = $this->styleResolver->authoredInheritedPropertyWinner($anchor, $property, true, $conditions);
                    if ('' !== $value) $typography[$property] = $value;
                }
                $typography = $this->styleResolver->safeVisualDeclarations($typography);
                if (array() !== $typography) {
                    $this->session->generatedSupportStylesheetState()->registerNavigationEditorTypography(
                        $this->navigationSubjectMarker($anchor, 'anchor'),
                        $this->styleResolver->cssDeclarationString($typography),
                        $conditions
                    );
                }
            }
        }
        $node = $anchor->parentNode;
        foreach ($boxes as $box) {
            $this->pendingNavigationSubjects[$node->getNodePath() ?? ''] = array('marker' => $box['marker'], 'target' => '.wp-block-navigation .wp-block-navigation-item .' . $box['marker']);
            $node = $node->parentNode;
        }
        return array_reverse($boxes);
    }

    /** Empty item siblings still own flex gaps and authored divider paint. */
    public function navigationItemEmptySiblings(DOMElement $anchor, DOMElement $item): array
    {
        $branch = $anchor;
        while ($branch->parentNode instanceof DOMElement && !$branch->parentNode->isSameNode($item)) $branch = $branch->parentNode;
        $result = array();
        $after = false;
        foreach ($item->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            if ($child->isSameNode($branch)) { $after = true; continue; }
            if (!in_array(strtolower($child->tagName), array('span', 'div'), true) || $child->childElementCount || '' !== trim($child->textContent ?? '') || $child->hasAttribute('role') || $child->hasAttribute('tabindex')) continue;
            $result[] = array('tag' => strtolower($child->tagName), 'after' => $after, 'className' => SourceDom::mergeClassNames($child->getAttribute('class'), implode(' ', $this->sourcePresentationMarkers($child))), 'style' => $child->getAttribute('style'));
        }
        return $result;
    }

    /** A text-free, link-free sibling of the link that paints only an SVG glyph. */
    private static function isDisclosureGlyph(DOMElement $element, DOMElement $anchor): bool
    {
        return !SourceDom::elementContains($element, $anchor)
            && '' === trim($element->textContent ?? '')
            && 0 === $element->getElementsByTagName('a')->length
            && ('svg' === strtolower($element->tagName) || 0 < $element->getElementsByTagName('svg')->length);
    }

    /** Equivalent menu occurrences share identity, while different source cascades do not. */
    private function navigationSubjectMarker(DOMElement $element, string $kind): string
    {
        $rules = array();
        foreach ($this->session->authorStyleAnalysis()->styleRules() as $rule) {
            foreach ($rule['selectors'] as $selector) {
                $text = (string) ($selector['selector'] ?? '');
                // Route state is not item identity: the same item on every route
                // carries one marker, whichever page the snapshot served.
                if ('' !== $text && $this->styleResolver->matchesCssSelector($element, $text) && !$this->session->authorSelectorProjectionState()->selectsCurrentNavigationState($text, $element)) {
                    $rules[] = array($text, $rule['declarations'], $rule['conditions'] ?? array());
                }
            }
        }
        $projections = $this->session->authorSelectorProjectionState();
        $classes = SourceDom::tokens($element->getAttribute('class'));
        $classes = array_values(array_filter(array_diff($classes, SourceClassIdentity::routeState($element)), static fn(string $class): bool => !$projections->isCurrentNavigationClass($class, $element) && !$projections->selectsCurrentNavigationState('.' . $class, $element)));
        // Core's submenu row can require a different rendered display from
        // ordinary links even when their source declarations are identical.
        $hasSubmenu = false;
        if ('item' === $kind) {
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), array('ul', 'ol'), true)) $hasSubmenu = true;
            }
        }
        $identity = array(strtolower($element->tagName), $element->getAttribute('id'), implode(' ', $classes), $element->getAttribute('style'), $rules);
        if ($hasSubmenu) $identity[] = 'submenu-row';
        return $this->session->authorStyleAnalysis()->allocateStableMarker('navigation-' . $kind, (string) json_encode($identity));
    }

    /** Preserve a single passive icon as artwork inside Core's native anchor. */
    public function navigationAnchorArtwork(DOMElement $anchor): string
    {
        if (!$this->session instanceof HtmlTransformerSession || !$this->svgMaterializer instanceof SvgMaterializer || '' !== trim($anchor->textContent ?? '')) return '';
        $children = array_values(array_filter(iterator_to_array($anchor->childNodes), static fn($node): bool => $node instanceof DOMElement));
        if (1 !== count($children) || 'svg' !== strtolower($children[0]->tagName)) return '';
        $svg = $children[0];
        if (!SvgMaterializer::isPassiveSvgMarkup($svg) || !SourceDom::svgHasDrawableContent($svg)) return '';
        $nodes = array($svg);
        foreach ($svg->getElementsByTagName('*') as $node) {
            if (!in_array(strtolower($node->tagName), array('g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon'), true)) return '';
            $nodes[] = $node;
        }
        $subjects = array();
        foreach ($nodes as $node) {
            $marker = $this->navigationSubjectMarker($node, 'artwork');
            $subjects[] = array($node->getNodePath() ?? '', $marker);
            $node->setAttribute('class', SourceDom::mergeClassNames($node->getAttribute('class'), $marker));
        }
        $markup = $this->svgMaterializer->inlineSvgMarkup($svg);
        if ('' === $markup || !SourceDom::isSafeSvgContent($markup)) return '';
        foreach ($subjects as [$path, $marker]) $this->pendingNavigationSubjects[$path] = array('marker' => $marker, 'target' => '.wp-block-navigation .wp-block-navigation-item .' . $marker);
        return $markup;
    }

    /** Core's item can interrupt an anchor's authored or safely inherited line-height. */
    public function navigationAnchorLineHeightMarker(array $anchorAttrs, DOMElement $anchor, DOMElement $sourceItem): string
    {
        $value = $anchorAttrs['style']['typography']['lineHeight'] ?? null;
        if (! $this->session instanceof HtmlTransformerSession) {
            return '';
        }
        if (!is_string($value) || '' === trim($value)) {
            if (! $this->styleResolver instanceof StyleResolver || $anchor->isSameNode($sourceItem)) {
                return '';
            }
            // Relative units are computed on the source owner. Repeating them
            // on the anchor is equivalent only when both used the same font size.
            if ($this->styleResolver->authoredInheritedPropertyWinner($anchor, 'font-size')
                !== $this->styleResolver->authoredInheritedPropertyWinner($sourceItem, 'font-size')
                || '' === $this->styleResolver->authoredInheritedPropertyWinner($anchor, 'line-height')
            ) {
                return '';
            }
            $value = '';
            for ($node = $anchor; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null) {
                $declarations = $this->styleResolver->cssDeclarations($this->styleResolver->specificityResolvedPresentationStyle($node));
                if (isset($declarations['line-height'])) {
                    $value = trim((string) $declarations['line-height']);
                    break;
                }
                if ($node->isSameNode($sourceItem)) break;
            }
        }
        $value = trim((string) $value);
        if ('' === $value || preg_match('~[{}<>;]|/\*|(?:expression|url)\s*\(|javascript\s*:~i', $value)) {
            return '';
        }

        $marker = 'blocks-engine-navigation-anchor-line-height-' . hash('sha256', $value);
        $this->session->generatedSupportStylesheetState()->registerNavigationAnchorLineHeight($marker, $value);
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

    /**
     * Every value the author states for these properties at any viewport.
     * A recognition signal only, never projected.
     *
     * @param array<int, string> $properties
     * @return array<string, list<string>>
     */
    public function declaredValuesAtAnyViewport(DOMElement $element, array $properties): array
    {
        return $this->styleResolver?->authorDeclaredValuesAtAnyViewport($element, $properties) ?? array();
    }

    /** Whether the author cascade hides the element at every viewport its display rules distinguish. */
    public function isDisplayNoneAtEveryViewport(DOMElement $element): bool
    {
        return $this->styleResolver?->isDisplayNoneAtEveryViewport($element) ?? true;
    }

    /** Whether the element is, or contains, the toggle recognized for this navigation. */
    public function holdsNavigationToggle(DOMElement $navigation, DOMElement $element): bool
    {
        $toggle = $this->navigationToggleSuppressor?->navigationToggleControl($navigation);
        return $toggle instanceof DOMElement && SourceDom::elementContains($element, $toggle);
    }

    public function resolvedDisplay(DOMElement $element): string
    {
        return $this->styleResolver?->resolvedConditionalDisplay($element) ?? '';
    }

    /**
     * The display the browser cascade gives the element at the reference
     * viewport: static and applying conditional rules, ranked by specificity
     * and then source order, optionally falling back to the UA tag default.
     * A recognition signal only, never projected.
     */
    public function cascadeDisplay(DOMElement $element, bool $includeUaDefault = false): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver
            || 1 !== preg_match('/(?:^|;)\s*display\s*:\s*([^;]+)/i', $this->styleResolver->controlSurfaceResolvedStyle($element), $match)
        ) {
            return $includeUaDefault ? ($this->styleResolver?->defaultTagDisplay($element) ?? '') : '';
        }

        return strtolower(trim((string) preg_replace('/\s*!important\s*$/i', '', trim($match[1]))));
    }

    public function isFloated(DOMElement $element): bool
    {
        $float = $this->styleResolver?->referenceViewportAuthorDeclarations($element, array( 'float' ))['float'] ?? 'none';
        return ! in_array(strtolower(trim((string) preg_replace('/\s*!important\s*$/i', '', $float))), array( '', 'none' ), true);
    }

    /** Compare the authored display contracts, including breakpoints outside the reference viewport. */
    public function hasDistinctDisplayBoxes(DOMElement $landmark, DOMElement $list): bool
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) {
            return false;
        }
        $landmarkDisplay = $this->styleResolver->declaredPresentation($landmark, 'display');
        $listDisplay = $this->styleResolver->declaredPresentation($list, 'display');
        // An unstyled block landmark can still fold a block-level flex/grid
        // list. An inline list changes that outer formatting contract.
        $usesDefaults = $landmarkDisplay->isEmpty() || $listDisplay->isEmpty();
        $outer = $usesDefaults
            ? static fn(string $display): string => in_array($display, array('block', 'flex', 'grid', 'flow-root'), true) ? 'block' : $display
            : static fn(string $display): string => $display;
        $landmarkBase = $this->styleResolver->resolveCssVariablesInValue($landmarkDisplay->base(), $landmark) ?: $this->styleResolver->defaultTagDisplay($landmark);
        $listBase = $this->styleResolver->resolveCssVariablesInValue($listDisplay->base(), $list) ?: $this->styleResolver->defaultTagDisplay($list);
        $landmarkConditions = $landmarkDisplay->conditional();
        $listConditions = $listDisplay->conditional();
        if ($usesDefaults) {
            // Visibility alone belongs to the existing overlay state projection,
            // not to a new landmark box around the native responsive control.
            if ('none' === $landmarkBase) $landmarkBase = $this->styleResolver->defaultTagDisplay($landmark);
            if ('none' === $listBase) $listBase = $this->styleResolver->defaultTagDisplay($list);
            $visibleBox = static fn(string $display): bool => 'none' !== $display;
            $landmarkConditions = array_filter($landmarkConditions, $visibleBox);
            $listConditions = array_filter($listConditions, $visibleBox);
        }
        return $outer($landmarkBase) !== $outer($listBase)
            || array_map($outer, $landmarkConditions) !== array_map($outer, $listConditions);
    }

    public function isOutOfFlow(DOMElement $element): bool
    {
        return 1 === preg_match('/(?:^|;)\s*position\s*:\s*(?:fixed|absolute|sticky)\b/i', $this->resolvedStyle($element));
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
        $display = CssValueInspector::declared($declarations, 'display');
        if ( 'none' === $display ) {
            return true;
        }
        $visibility = CssValueInspector::declared($declarations, 'visibility');
        if ( in_array($visibility, array( 'hidden', 'collapse' ), true) ) {
            return true;
        }
        $opacity = CssValueInspector::declared($declarations, 'opacity');

        return is_numeric($opacity) && 0.0 === (float) $opacity;
    }

    /** @return list<string> */
    public function colorInteractionStates(DOMElement $element): array
    {
        return $this->navigationStyleProjector?->navigationColorInteractionStates($element) ?? array();
    }

    public function inlineMirrorMarker(DOMElement $element): string
    {
        return $this->navigationToggleSuppressor?->inlineMirrorMarker($element) ?? '';
    }

    public function overlayMenu(DOMElement $element): string
    {
        $overlayMenu = $this->navigationToggleSuppressor?->navigationOverlayMenu($element) ?? 'never';

        // A script that addresses both the menu and its toggle keeps that
        // toggle and opens the menu itself. Core's overlay would add a second
        // toggle and hide the links where the script never looks.
        if ( 'mobile' === $overlayMenu && $this->isRuntimeDomTarget($element) ) {
            $toggle = $this->navigationToggleSuppressor?->navigationToggleControl($element);
            if ( $toggle instanceof DOMElement && $this->isRuntimeDomTarget($toggle) ) {
                return 'never';
            }
        }

        return $overlayMenu;
    }

    public function capturedMenuFold(DOMElement $row): ?CapturedMenuDialogFold
    {
        return $this->navigationToggleSuppressor?->capturedMenuFoldForRow($row);
    }

    public function releaseCapturedMenuFold(CapturedMenuDialogFold $fold): void
    {
        $this->navigationToggleSuppressor?->releaseCapturedMenuFold($fold);
    }

    /** The width (px) at and below which the source shows the hamburger instead of the row. */
    public function menuCollapseBreakpoint(DOMElement $row, DOMElement $trigger): ?float
    {
        return $this->navigationToggleSuppressor?->sourceCollapseBreakpoint($row, $trigger);
    }

    public function registerMenuFoldRule(string $marker, string $rule): void
    {
        $this->session?->generatedSupportStylesheetState()->registerNativeNavigationOverlay($marker, $rule);
    }

    public function navigationToggleControl(DOMElement $element): ?DOMElement
    {
        return $this->navigationToggleSuppressor?->navigationToggleControl($element);
    }

    /** The overlay mode a projected opener gives its navigation (never|mobile|always). */
    public function projectedOverlayMenu(DOMElement $toggle): string
    {
        return $this->navigationToggleSuppressor?->projectedOverlayMenu($toggle) ?? 'never';
    }

    public function responsiveToggleMarker(DOMElement $element): string
    {
        return $this->projectedNavigation?->responsiveNavigationToggleMarker($element) ?? '';
    }

    /** @return array<string, mixed> */
    public function responsiveOverlayAttributes(DOMElement $element): array
    {
        return $this->projectedNavigation?->responsiveNavigationOverlayAttributes($element) ?? array();
    }

    public function responsiveOverlayMarker(DOMElement $element): string
    {
        $this->releaseResponsiveOverlayContainingBlocks($element);
        return $this->projectedNavigation?->responsiveNavigationOverlayMarker($element) ?? '';
    }

    /** Keep fixed native overlays viewport-sized, without changing closed headers. */
    private function releaseResponsiveOverlayContainingBlocks(DOMElement $element): void
    {
        if (null === $this->styleResolver || null === $this->session) return;

        $properties = array('backdrop-filter', '-webkit-backdrop-filter', 'filter', 'transform', 'translate', 'rotate', 'scale', 'perspective', 'contain', 'will-change');
        for ($ancestor = $element->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            $author = $this->styleResolver->referenceViewportAuthorDeclarations($ancestor, $properties);
            $resets = array();
            foreach ($author as $property => $value) {
                $value = strtolower(trim($value));
                if (in_array($value, array('', 'none', 'normal', 'auto', 'initial', 'inherit', 'unset', 'revert', 'revert-layer'), true)) continue;
                if ('contain' === $property && !preg_match('/\b(?:paint|layout|strict|content)\b/', $value)) continue;
                if ('will-change' === $property && !array_intersect(preg_split('/[\s,]+/', $value) ?: array(), array_diff($properties, array('will-change')))) continue;
                // will-change has no `none` value; auto removes its containing block.
                $resets[$property] = 'will-change' === $property ? 'auto' : 'none';
            }
            if (array() === $resets) continue;

            $marker = $this->session->authorSelectorProjectionState()->ensureSemanticMarker($ancestor->getNodePath() ?? '');
            $this->session->generatedSupportStylesheetState()->registerNativeNavigationOverlay(
                $marker,
                EngineSupportCss::responsiveOverlayContainingBlockRule($marker, $resets)
            );
        }
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

        $markup = $this->svgMaterializer->standaloneSvgMarkup($svg);
        if ( '' === $markup || ! SourceDom::isSafeSvgContent($markup) ) {
            return '';
        }
        $markup = SvgMaterializer::ensureSvgImageNamespace($markup);

        $box = $this->navigationLinkIconBox($svg);
        if ( '' === $box ) {
            return '';
        }

        $responsiveBoxes = array();
        foreach ($this->styleResolver->inheritedBoxMediaConditions($svg) as $conditions) {
            $responsiveBox = $this->navigationLinkIconBox($svg, $conditions);
            if ('' !== $responsiveBox && $responsiveBox !== $box) {
                $responsiveBoxes[] = array('conditions' => $conditions, 'box' => $responsiveBox);
            }
        }

        // This background is replacement DOM artwork, not an authored anchor
        // background. Projected anchor resets (including shared route styles)
        // must not erase the SVG that Core cannot persist in its label.
        $scale = $this->navigationLinkIconScale($svg, $element);
        $background = ';background-image:url("data:image/svg+xml,' . rawurlencode($markup) . '")'
            . ';background-repeat:no-repeat;background-position:center;background-size:'
            . (1.0 === $scale ? 'contain' : round($scale * 100, 4) . '% ' . round($scale * 100, 4) . '%');

        if ( '' === trim($element->textContent ?? '') ) {
            // The icon is the anchor's only content. A source item can keep its
            // own text line box (e.g. `display:inline-block` with an inherited
            // line-height), where a baseline-aligned box sits below the item
            // and its `overflow:hidden` clips the icon away.
            $declarations = 'display:inline-block;vertical-align:top;' . $box . $background . ';font-size:0;line-height:0;color:transparent';
            $marker = 'blocks-engine-navigation-link-icon-' . substr(hash('sha256', $declarations . json_encode($responsiveBoxes)), 0, 12);
            $this->session->generatedSupportStylesheetState()->registerNavigationLinkIcon($marker, $declarations);
            $this->session->generatedSupportStylesheetState()->registerNavigationIconBoxes($marker, $responsiveBoxes);

            return $marker;
        }

        $gap = $this->navigationLinkLeadingIconGap($svg);
        $declarations = 'content:"";display:inline-block;vertical-align:middle;margin-inline-end:' . $gap . ';' . $box . $background;
        $marker = 'blocks-engine-navigation-link-leading-icon-' . substr(hash('sha256', $declarations . json_encode($responsiveBoxes)), 0, 12);
        $this->session->generatedSupportStylesheetState()->registerNavigationLinkLeadingIcon($marker, $declarations);
        $this->session->generatedSupportStylesheetState()->registerNavigationIconBoxes($marker, $responsiveBoxes);

        return $marker;
    }

    /** @param array<string,mixed> $attrs @return array<string,mixed> */
    public function withSourceOpener(array $attrs, DOMElement $element): array
    {
        return $this->projectedNavigation?->withSourceOpener($attrs, $element) ?? $attrs;
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
    public function recordInheritedPresentation(DOMElement $element, array $authorClasses, bool $recoverItemPresentation = true): void
    {
        if ($recoverItemPresentation) {
            $this->recordInheritedNavigationPresentation($element, $authorClasses);
        }
    }

    /**
     * Translate inherited inline-item packing without freezing media variants.
     *
     * @param array<string,mixed> $attrs
     */
    public function inheritedListPackingAlignment(DOMElement $list, array &$attrs): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) return '';

        $alignment = strtolower($this->styleResolver->authoredInheritedPropertyWinner($list, 'text-align'));
        $justify = static fn (string $value): string => match ( strtolower($value) ) {
            'right', 'end' => 'flex-end',
            'center' => 'center',
            default => 'flex-start',
        };
        // Only a media stack that changes the packing needs projection; an
        // unrelated conditional ancestor rule (a motion custom property) would
        // otherwise add a per-document marker that splits shared chrome.
        $base = $justify($this->styleResolver->authoredInheritedPropertyWinner($list, 'text-align', true));
        $packings = array();
        foreach ( $this->styleResolver->inheritedBoxMediaConditions($list) as $stack ) {
            $packing = $justify($this->styleResolver->authoredInheritedPropertyWinner($list, 'text-align', true, $stack));
            if ( $packing !== $base ) $packings[] = array($stack, $packing);
        }
        if ( array() === $packings ) return $alignment;

        $marker = 'blocks-engine-navigation-packing-' . substr(hash('sha256', SourceDom::elementSelector($list)), 0, 12);
        $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' ' . $marker);
        $target = '.wp-block-navigation.' . $marker . '>.wp-block-navigation__container';
        foreach ( $packings as [$stack, $packing] ) {
            $this->sourceTargetProjection?->record(SourceDom::elementSelector($list), $target, 'justify-content:' . $packing . '!important', $stack);
        }

        return $alignment;
    }

    /** Core's vertical layout must still follow authored breakpoint changes. */
    public function projectNavigationDirection(DOMElement $element, string $marker): void
    {
        if (!$this->styleResolver instanceof StyleResolver) return;
        $direction = $this->styleResolver->declaredPresentation($element, 'flex-direction');
        if (!$direction->isConditional()) return;
        $target = ':root .wp-block-navigation.' . $marker . ' .wp-block-navigation__container';
        foreach ($direction->conditional() as $queries => $value) {
            $this->sourceTargetProjection?->record(SourceDom::elementSelector($element), $target, 'flex-direction:' . preg_replace('/\s*!important\s*$/i', '', $value) . '!important', explode('{', $queries));
        }
    }

    /** Record unsupported source residue on the native element replacing it. */
    public function projectSourceToNativeTarget(DOMElement $element, string $targetSelector, string $declarations): void
    {
        $this->sourceTargetProjection?->record(SourceDom::elementSelector($element), $targetSelector, $declarations);
    }

    /**
     * Uniform `scale()` transforms between the anchor and its SVG enlarge the
     * artwork around its center (an icon set scaled by a custom property, say).
     * The replacement background carries that factor; any other transform is
     * left as authored artwork size.
     */
    private function navigationLinkIconScale(DOMElement $svg, DOMElement $anchor): float
    {
        $scale = 1.0;
        for ( $node = $svg; $node instanceof DOMElement && ! $node->isSameNode($anchor); $node = $node->parentNode ) {
            $transform = trim((string) ($this->styleResolver->referenceViewportAuthorDeclarations($node, array( 'transform' ))['transform'] ?? ''));
            $transform = trim(preg_replace('/\s*!\s*important\s*$/i', '', $this->styleResolver->resolveCssVariablesInValue($transform, $node)) ?? '');
            if ( '' === $transform || 'none' === strtolower($transform) ) {
                continue;
            }
            if ( 1 !== preg_match('/^scale\(\s*(\d+(?:\.\d+)?)\s*\)$/i', $transform, $match) ) {
                return 1.0;
            }
            $scale *= (float) $match[1];
        }

        return $scale > 0 ? $scale : 1.0;
    }

    private function navigationLinkIconBox(DOMElement $svg, ?array $conditions = null): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) {
            return '';
        }

        $presentation = fn (DOMElement $node): array => null === $conditions
            ? $this->styleResolver->resolvedPresentationDeclarations($node)
            : $this->styleResolver->conditionedBoxPresentation($node, $conditions);
        $declarations = $presentation($svg);
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
            // Percentages belong to the SVG's source containing box, not the
            // replacement anchor (whose size would then depend on itself).
            $node = $svg;
            $scale = 1.0;
            while ( 1 === preg_match('/^(\d+(?:\.\d+)?)%$/', $value, $percentage) ) {
                $node = $node->parentNode;
                if ( ! $node instanceof DOMElement ) {
                    return '';
                }
                $parentDeclarations = $presentation($node);
                if ( 'contents' === ($parentDeclarations['display'] ?? '') ) {
                    continue;
                }
                $parentValue = trim((string) ($parentDeclarations[$property] ?? ''));
                if ( '' === $parentValue || preg_match('/[{}<>;]/', $parentValue) || in_array(strtolower($parentValue), array( 'auto', 'none' ), true) ) {
                    return '';
                }
                $scale *= (float) $percentage[1] / 100;
                $value = $parentValue;
            }
            if ( 1.0 !== $scale ) {
                $value = 'calc(' . $value . ' * ' . $scale . ')';
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

        $declarations = $projected = array();
        // `font` first: builders commonly state menu type as the shorthand, and
        // a longhand found further out should not silently outrank it.
        foreach ( array( 'font', 'color', 'font-family', 'font-size', 'font-weight', 'font-style', 'letter-spacing', 'text-transform' ) as $property ) {
            if ($this->nativeNavigationParentOwnsPresentation($anchors, $navigation, $property)) continue;
            $value = $this->sharedNavigationItemPresentationValue($anchors, $navigation, $property);
            // The source component resolves this formula against its own width.
            // Replaying it on Core's replacement anchor changes that reference
            // and inflates the label. The promoted item retains the source
            // wrapper class, so the anchor can inherit the original value.
            if ( in_array($property, array( 'font', 'font-size' ), true) && str_contains($value, '--scaling-factor') ) {
                continue;
            }
            if ( 'color' === $property && CssValueInspector::isTransparentColor($value) ) {
                continue;
            }
            if ( '' !== $value ) {
                $declarations[] = $property . ':' . $this->navigationProjectionValue($value);
                array_push($projected, ...('font' === $property ? array( 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height' ) : array( $property )));
            }
        }
        if ( array() === $declarations ) {
            return;
        }

        // Equal author classes do not make equal menus: a captured dialog
        // copy carries the inline menu's classes but not its cascade. The
        // rule is published once the emitted block has its instance scope.
        $this->pendingInheritedPresentations[] = array('path' => $navigation->getNodePath() ?? '', 'source' => SourceDom::elementSelector($navigation), 'classes' => $authorClasses, 'declarations' => implode(';', $declarations), 'variants' => $this->inheritedNavigationPresentationVariants($anchors, $navigation, array_unique($projected)));
    }

    /**
     * The recovered rule outranks the menu's own breakpoint rules on Core's
     * anchors, so a value the links inherit differently under a screen media
     * condition must be restated under that condition too.
     *
     * @param list<DOMElement> $anchors
     * @param array<int, string> $properties
     * @return list<array{conditions: list<string>, declarations: string}>
     */
    private function inheritedNavigationPresentationVariants(array $anchors, DOMElement $navigation, array $properties): array
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) {
            return array();
        }
        $stacks = array();
        foreach ( $anchors as $anchor ) {
            for ( $node = $anchor; $node instanceof DOMElement; $node = $node->parentNode ) {
                foreach ( $this->styleResolver->screenMediaConditionsDeclaring($node, $properties) as $conditions ) {
                    $stacks[ serialize($conditions) ] = $conditions;
                }
                if ( $node->isSameNode($navigation) ) {
                    break;
                }
            }
        }
        $variants = array();
        foreach ( $stacks as $conditions ) {
            $declarations = array();
            foreach ( $properties as $property ) {
                $values = $resting = array();
                foreach ( $anchors as $anchor ) {
                    $values[] = $this->styleResolver->authoredInheritedPropertyWinner($anchor, $property, true, $conditions);
                    $resting[] = $this->styleResolver->authoredInheritedPropertyWinner($anchor, $property);
                }
                $values = array_unique($values);
                if ( 1 === count($values) && '' !== $values[0] && array( $values[0] ) !== array_unique($resting) ) {
                    $declarations[ $property ] = $this->navigationProjectionValue($values[0]);
                }
            }
            $declarations = $this->styleResolver->safeVisualDeclarations($declarations);
            if ( array() !== $declarations ) {
                $variants[] = array( 'conditions' => $conditions, 'declarations' => $this->styleResolver->cssDeclarationString($declarations) );
            }
        }

        return $variants;
    }

    /** @var list<array{path: string, source: string, classes: array<int, string>, declarations: string, variants: list<array{conditions: list<string>, declarations: string}>}> */
    private array $pendingInheritedPresentations = array();

    /**
     * Publish recovered type only on the emitted menu that owns it.
     *
     * @param array<string, mixed> $block
     */
    private function publishInheritedNavigationPresentation(array &$block, DOMElement $source, string $scope): void
    {
        if (!$this->sourceTargetProjection instanceof SourceTargetProjectionState) return;
        $root = $source->getNodePath() ?? '';
        $classes = SourceDom::tokens((string) ($block['attrs']['className'] ?? ''));
        foreach ($this->pendingInheritedPresentations as $index => $pending) {
            // The correspondence is rooted at the list, while the recovered
            // inheritance may belong to its enclosing landmark.
            if ($pending['path'] !== $root && !str_starts_with($pending['path'], $root . '/') && !str_starts_with($root, $pending['path'] . '/')) continue;
            if (array() !== array_diff($pending['classes'], $classes)) continue;
            if ('' === $scope && !$this->session instanceof HtmlTransformerSession) {
                $this->recordInheritedNavigationPresentationRules($pending, '.wp-block-navigation' . CssIdent::compoundClassSelector($pending['classes']) . ' .wp-block-navigation-item__content');
                unset($this->pendingInheritedPresentations[$index]);
                continue;
            }
            if ('' === $scope) {
                preg_match('/(?:^|\s)(blocks-engine-navigation-instance-[a-f0-9]{12}-[0-9]+)(?:\s|$)/', (string) ($block['attrs']['className'] ?? ''), $existing);
                $scope = $existing[1] ?? $this->session->authorStyleAnalysis()->allocateStableMarker('navigation-instance', $this->navigationSourceIdentity($source) . $pending['declarations']);
                $block['attrs']['className'] = SourceDom::mergeClassNames($block['attrs']['className'] ?? '', $scope);
            }
            $this->recordInheritedNavigationPresentationRules($pending, '.wp-block-navigation.' . $scope . ' .wp-block-navigation-item__content');
            unset($this->pendingInheritedPresentations[$index]);
        }
    }

    /** @param array{source: string, declarations: string, variants: list<array{conditions: list<string>, declarations: string}>} $pending */
    private function recordInheritedNavigationPresentationRules(array $pending, string $target): void
    {
        $this->sourceTargetProjection?->record($pending['source'], $target, $pending['declarations']);
        foreach ($pending['variants'] as $variant) {
            $this->sourceTargetProjection?->record($pending['source'], $target, $variant['declarations'], $variant['conditions']);
        }
    }

    /** A retained item/wrapper owns its inherited presentation, including state and query changes. */
    private function nativeNavigationParentOwnsPresentation(array $anchors, DOMElement $navigation, string $property): bool
    {
        if (!$this->session instanceof HtmlTransformerSession || !$this->styleResolver instanceof StyleResolver) return false;
        $properties = 'font' === $property ? array('font', 'font-family', 'font-size', 'font-weight', 'font-style') : array($property);
        if (str_starts_with($property, 'font-')) $properties[] = 'font';
        foreach ($anchors as $anchor) {
            for ($node = $anchor->parentNode; $node instanceof DOMElement && !$node->isSameNode($navigation); $node = $node->parentNode) {
                // This runs while the menu is still being recognised, before its
                // subjects are published, so the boxes this conversion retains
                // are its pending subjects; published ones alone would depend on
                // an earlier document having converted the same DOM path.
                $path = $node->getNodePath() ?? '';
                if (!isset($this->pendingNavigationSubjects[$path]) && '' === $this->session->authorSelectorProjectionState()->navigationSubject($path)) continue;
                foreach ($this->styleResolver->cssDeclarations($node->getAttribute('style')) as $name => $value) {
                    if (in_array($name, $properties, true) && !in_array(strtolower(trim($value)), array('inherit', 'unset', 'revert', 'revert-layer'), true)) return true;
                }
                foreach ($this->session->authorStyleAnalysis()->styleRules() as $rule) {
                    $owned = array_filter(array_intersect_key($rule['declarations'], array_flip($properties)), static fn(string $value): bool => !in_array(strtolower(CssValueInspector::withoutImportant($value)), array('inherit', 'unset', 'revert', 'revert-layer'), true));
                    if (array() === $owned) continue;
                    foreach ($rule['selectors'] as $selector) {
                        $text = (string) ($selector['selector'] ?? '');
                        if (!preg_match('/::?(?:before|after)\b/i', $text) && $this->styleResolver->matchesCssSelector($node, $text)) return true;
                    }
                }
            }
        }
        return false;
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
     * Keep a painted, framed, or placed menu stated once.
     *
     * WordPress builds the inner `wp-block-navigation__container` list with the
     * block's own wrapper attributes, so every class the emitted block carries —
     * the source's classes and this engine's source markers alike — is copied
     * onto the list, and a source rule that styles the menu through any of them
     * matches twice. Paint renders stacked on itself; a frame — the menu's own
     * margin, padding, and rules — is charged twice and doubles the menu's
     * height; a placement (position, offsets, transform) turns the `nav` into a
     * zero-size positioned box with the list placed once more inside it, where
     * it shrinks to its longest word and wraps every item onto its own line.
     * Where the source states any of these, neutralise it on the inner container
     * so the `nav` keeps the single source declaration.
     *
     * A list the source places itself — a panel dropping below its nav — keeps
     * the placement families it states: the nav's position is then the list's
     * containing block, not a declaration the list must shed. Families only the
     * nav states are still reset, so a nav transform does not move the list
     * twice.
     *
     * @param DOMElement         $navigation The source element whose classes core/navigation carries.
     * @param array<int, string> $classes    Classes the emitted block carries, so copied onto the container: the source's own, or the engine's markers for a class-less source.
     * @param DOMElement|null    $list       The source list the container stands in for, when it is a distinct element.
     */
    public function recordNavigationContainerReset(DOMElement $navigation, array $classes, ?DOMElement $list = null): void
    {
        if ( array() === $classes || ! $this->sourceTargetProjection instanceof SourceTargetProjectionState ) {
            return;
        }

        $resets = array();
        // Reset each placement family the navigation states, except one the
        // source list states for itself (a panel dropping below its nav keeps
        // its own position; the nav's transform must still not reach it twice).
        $navigationPlacement = $this->placementFamilies($navigation);
        $listPlacement = $list instanceof DOMElement && ! $list->isSameNode($navigation) ? $this->placementFamilies($list) : array();
        foreach ( self::PLACEMENT_FAMILY_RESETS as $family => $declarations ) {
            if ( isset($navigationPlacement[ $family ]) && ! isset($listPlacement[ $family ]) ) {
                array_push($resets, ...$declarations);
            }
        }
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
        $selector = '.wp-block-navigation' . CssIdent::compoundClassSelector($classes) . ' .wp-block-navigation__container';
        $this->sourceTargetProjection->record(SourceDom::elementSelector($navigation), $selector, implode(';', $resets));
    }

    /**
     * Placement properties by family, and what neutralises each family on the
     * container: position and offsets, the transform (with its prefixed
     * form), the individual `translate`, `rotate` and `scale`, and a motion
     * path.
     */
    private const PLACEMENT_FAMILY_PROPERTIES = array(
        'position' => array( 'position', 'top', 'right', 'bottom', 'left', 'inset', 'inset-inline', 'inset-block', 'inset-inline-start', 'inset-inline-end', 'inset-block-start', 'inset-block-end' ),
        'transform' => array( 'transform', '-webkit-transform' ),
        'translate' => array( 'translate' ),
        'rotate' => array( 'rotate' ),
        'scale' => array( 'scale' ),
        'offset' => array( 'offset', 'offset-path' ),
    );

    private const PLACEMENT_FAMILY_RESETS = array(
        'position' => array( 'position:static!important', 'inset:auto!important' ),
        'transform' => array( 'transform:none!important', '-webkit-transform:none!important' ),
        'translate' => array( 'translate:none!important' ),
        'rotate' => array( 'rotate:none!important' ),
        'scale' => array( 'scale:none!important' ),
        'offset' => array( 'offset-path:none!important' ),
    );

    /**
     * Which placement families the source states for this element at any
     * viewport, ignoring values that place nothing (`static`, `none`, `auto`
     * and the CSS-wide keywords).
     *
     * Read from the author analysis rather than the resting cascade: transforms
     * are runtime-animatable and therefore kept out of the static presentation
     * collection, and a menu placed only behind a width query is still placed.
     *
     * @return array<string, true>
     */
    private function placementFamilies(DOMElement $element): array
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) {
            return array();
        }

        $familyOf = array();
        foreach ( self::PLACEMENT_FAMILY_PROPERTIES as $family => $properties ) {
            foreach ( $properties as $property ) {
                $familyOf[ $property ] = $family;
            }
        }
        $declared = $this->styleResolver->authorDeclaredValuesAtAnyViewport($element, array_keys($familyOf));
        $families = array();
        foreach ( $declared as $property => $values ) {
            foreach ( $values as $value ) {
                $value = strtolower(trim((string) preg_replace('/\s*!\s*important\s*$/i', '', $value)));
                if ( '' !== $value
                    && ! in_array($value, array( 'auto', 'none', 'inherit', 'initial', 'unset', 'revert', 'revert-layer' ), true)
                    && ! ( 'position' === $property && 'static' === $value )
                ) {
                    $families[ $familyOf[ $property ] ] = true;
                }
            }
        }

        return $families;
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
                $this->styleResolver->specificityResolvedPresentationStyle($node),
                $node
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
