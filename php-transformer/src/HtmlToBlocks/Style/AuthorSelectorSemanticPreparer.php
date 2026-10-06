<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorCompoundInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** Prepares source identities needed to project author selectors onto canonical blocks. */
final class AuthorSelectorSemanticPreparer
{
    public function __construct(
        private readonly AuthorSelectorSemanticContext $context,
        private readonly StylesheetAnalysisComposer $stylesheetAnalysisComposer,
        private readonly StyleResolver $styleResolver,
        private readonly HtmlTransformerAnalysisCache $analysisCache
    ) {}

    /** @param array<string, mixed> $options */
    public function prepare(
        string $html,
        string $staticCss,
        DOMElement $sourceBody,
        array $options,
        HtmlTransformerSession $session
    ): void {
        $stylesheetAssets = $this->stylesheetAnalysisComposer->authorStylesheetAssetsFromOptions($options);
        if ( array() === $stylesheetAssets ) {
            $stylesheetAssets = $this->stylesheetAnalysisComposer->inlineAuthorStylesheetAssets($html);
            $staticCss = trim($staticCss);
            if ( '' !== $staticCss ) {
                $stylesheetAssets[] = array(
                    'path' => 'static-style.css',
                    // static_css predates explicit source assets and reports as
                    // inline-style in the public fallback provenance contract.
                    'source_path' => 'inline-style',
                    'content' => $staticCss,
                    'source_hash' => hash('sha256', $staticCss),
                    'media' => '',
                    'type' => '',
                );
            }
        }
        $combinedAuthorCss = array() === $stylesheetAssets
            ? $this->stylesheetAnalysisComposer->combinedAuthorStylesheet($html, $staticCss)
            : implode("\n\n", array_column($stylesheetAssets, 'content'));
        $authorStyles = new AuthorStyleAnalysis($html, $combinedAuthorCss, $stylesheetAssets, $sourceBody);
        $session->installAuthorStyleAnalysis($authorStyles);
        $sourceStyles = $session->sourceStyleResolutionState();
        $projections = $session->authorSelectorProjectionState();
        $sourceStyles->setFormLayoutCss($combinedAuthorCss);
        $this->discoverRuntimeAttributeSelectorPaths($options, $sourceStyles, $authorStyles, $projections);

        if ( '' === $combinedAuthorCss ) {
            return;
        }

        $authorAnalysis = $this->stylesheetAnalysisComposer->composedAuthorSelectorAnalysis(
            $this->stylesheetAnalysisComposer->authorStylesheetPayloads($html, $staticCss, $authorStyles)
        );
        $authorStyleRules = $authorAnalysis['rules'];
        $authorSelectors = array_merge(...array_column($authorStyleRules, 'selectors'));
        foreach ( array_keys($authorAnalysis['source_tags']) as $tagName ) {
            $projections->ensureTagMarker($tagName);
        }
        $this->discoverAuthorControlPaths($authorSelectors, $authorStyles, $projections);
        $applicableAuthorStyleRules = array();
        foreach ( $authorStyleRules as $rule ) {
            $rule['selectors'] = array_values(array_filter(
                $rule['selectors'],
                static fn (array $selector): bool => $authorStyles->selectorCanMatch($selector['parsed'])
            ));
            if ( array() !== $rule['selectors'] ) {
                $applicableAuthorStyleRules[] = $rule;
            }
        }
        $authorStyles->installStyleRules($applicableAuthorStyleRules);
        $sourceStyles->retainMatchableRules(static function (array $rule) use ($sourceStyles, $authorStyles): bool {
            $parsed = $sourceStyles->parsedSelector((string) ($rule['selector'] ?? ''));
            return null === $parsed || ! ($parsed['supported'] ?? false) || $authorStyles->selectorCanMatch($parsed);
        });
        $this->discoverAuthorInlineSemanticPaths($authorSelectors, $authorStyles, $projections);
        $this->discoverInlineLayoutCarrierPaths($authorSelectors, $authorStyles, $projections);
        $this->discoverAuthorAttributePaths($authorSelectors, $authorStyles, $projections);
        $this->discoverAuthorRootChildPaths($authorSelectors, $authorStyles, $projections);
        $this->discoverAuthorTablePaths($authorSelectors, $authorStyles, $projections);
        $authorStyles->setSourceBodyProjectionClasses($this->referencedSourceBodyClasses($sourceBody, $authorStyles));
        $matchCache = $authorStyles->releaseSelectorMatchCache();
        $this->analysisCache->authorSelectorClassTokenBuilds += $matchCache->classTokenBuilds;
        $this->analysisCache->authorSelectorClassTokenHits += $matchCache->classTokenHits;
        $this->analysisCache->authorSelectorAttributeReads += $matchCache->attributeReads;
    }

    /** @param array<string, mixed> $parsed @return list<DOMElement> */
    public function matchingSourceElements(AuthorStyleAnalysis $authorStyles, string $selector, array $parsed): array
    {
        if ( $authorStyles->hasSelectorMatches($selector) ) {
            ++$this->analysisCache->authorSelectorMatchResultHits;
            return $authorStyles->selectorMatches($selector);
        }
        ++$this->analysisCache->authorSelectorMatchResultBuilds;
        if ( ! $authorStyles->selectorCanMatch($parsed) ) {
            return $authorStyles->rememberSelectorMatches($selector, array());
        }
        $matches = array();
        foreach ( $authorStyles->selectorCandidates($parsed) as $element ) {
            if ( CssSelectorMatcher::matches($element, $parsed, true, $authorStyles->selectorMatchCache())['matches'] ) {
                $matches[] = $element;
            }
        }
        return $authorStyles->rememberSelectorMatches($selector, $matches);
    }

    /** @param array<string, mixed> $parsed */
    public static function isRootChildSelector(array $parsed): bool
    {
        $compounds = $parsed['compounds'] ?? array();
        $combinators = $parsed['combinators'] ?? array();
        $last = count($compounds) - 1;

        return $last >= 1
            && 'body' === strtolower((string) ($compounds[$last - 1]['type'] ?? ''))
            && '>' === ($combinators[$last - 1] ?? '');
    }

    /** @return list<string> */
    private function referencedSourceBodyClasses(DOMElement $sourceBody, AuthorStyleAnalysis $authorStyles): array
    {
        $classes = preg_split('/\s+/', trim($sourceBody->getAttribute('class'))) ?: array();
        return array_values(array_filter(array_unique($classes), static function (string $class) use ($authorStyles): bool {
            return ColorSchemeVariant::cssContainsClassSelector($authorStyles->combinedCss(), $class);
        }));
    }

    /** @param list<array{selector:string,parsed:array<string,mixed>}> $authorSelectors */
    private function discoverAuthorControlPaths(array $authorSelectors, AuthorStyleAnalysis $authorStyles, AuthorSelectorProjectionState $projections): void
    {
        foreach ( $authorSelectors as $authorSelector ) {
            $selector = $authorSelector['selector'];
            $parsed = $authorSelector['parsed'];
            if ( ! $parsed['supported'] ) {
                continue;
            }
            $matches = $this->matchingSourceElements($authorStyles, $selector, $parsed);
            if ( array() === $matches ) {
                $rightmost = $parsed['rightmost_compound_span'] ?? null;
                if ( is_array($rightmost) ) {
                    $leafSelector = substr($selector, (int) $rightmost['start']);
                    $leafParsed = CssSelectorMatcher::parse($leafSelector);
                    if ( $leafParsed['supported'] ) {
                        $matches = $this->matchingSourceElements($authorStyles, $leafSelector, $leafParsed);
                    }
                }
            }
            $controls = array_filter(
                $matches,
                static fn (DOMElement $element): bool => in_array(strtolower($element->tagName), array( 'a', 'button' ), true)
            );
            foreach ( $controls as $control ) {
                $path = $control->getNodePath() ?? '';
                if ( '' !== $path ) {
                    $projections->markControlPath($path);
                }
            }
        }
    }

    /** @param list<array{selector:string,parsed:array<string,mixed>}> $authorSelectors */
    private function discoverAuthorInlineSemanticPaths(array $authorSelectors, AuthorStyleAnalysis $authorStyles, AuthorSelectorProjectionState $projections): void
    {
        foreach ( $authorSelectors as $authorSelector ) {
            $selector = $authorSelector['selector'];
            $parsed = $authorSelector['parsed'];
            if ( ! $parsed['supported'] ) {
                continue;
            }
            foreach ( $this->matchingSourceElements($authorStyles, $selector, $parsed) as $element ) {
                $path = $element->getNodePath() ?? '';
                $inlineTag = strtolower($element->tagName);
                $directChildSelector = '>' === ($parsed['combinators'][count($parsed['combinators']) - 1] ?? null);
                $directAuthorLayoutItem = $directChildSelector && $this->context->isDirectChildOfAuthorOwnedLayout($element);
                if ( ! $this->context->isInlineContentElement($inlineTag) || ('span' !== $inlineTag && ! $directAuthorLayoutItem) ) {
                    continue;
                }
                if ( '' === $path ) {
                    continue;
                }
                $listItem = $this->ancestorElement($element, 'li');
                $structuralListItem = $listItem instanceof DOMElement && $this->context->isStructuralListItem($listItem);
                if ( $listItem instanceof DOMElement && ! $structuralListItem && self::richTextSelectorNeedsHook($parsed) ) {
                    $marker = $projections->ensureRichTextMarker($path);
                    $element->setAttribute('data-blocks-engine-richtext-marker', $marker);
                } elseif ( 'span' === $inlineTag
                    && $this->ancestorElement($element, 'label') instanceof DOMElement
                    && self::richTextSelectorNeedsHook($parsed)
                ) {
                    // A label's text is emitted by the input block's RichText
                    // label carrier. Keep selector-addressable inline spans on
                    // that carrier even when their authored block display made
                    // them look like independent layout wrappers in source.
                    $marker = $projections->ensureRichTextMarker($path);
                    $element->setAttribute('data-blocks-engine-richtext-marker', $marker);
                } elseif ( $directAuthorLayoutItem
                    || ($structuralListItem && self::richTextSelectorNeedsHook($parsed))
                    || $this->context->requiresIndependentSemanticWrapper($element)
                ) {
                    $projections->ensureSemanticMarker($path);
                } elseif ( self::richTextSelectorNeedsHook($parsed) ) {
                    $marker = $projections->ensureRichTextMarker($path);
                    $element->setAttribute('data-blocks-engine-richtext-marker', $marker);
                }
            }
        }
    }

    /** @param list<array{selector:string,parsed:array<string,mixed>}> $authorSelectors */
    private function discoverInlineLayoutCarrierPaths(array $authorSelectors, AuthorStyleAnalysis $authorStyles, AuthorSelectorProjectionState $projections): void
    {
        foreach ( $authorSelectors as $authorSelector ) {
            if ( ! $authorSelector['parsed']['supported'] ) {
                continue;
            }
            foreach ( $this->matchingSourceElements($authorStyles, $authorSelector['selector'], $authorSelector['parsed']) as $element ) {
                $path = $element->getNodePath() ?? '';
                $parentPath = $element->parentNode instanceof DOMElement ? ($element->parentNode->getNodePath() ?? '') : '';
                if ( '' !== $path
                    && $this->context->requiresInlineLayoutCarrier($element)
                    && ! $this->isPhrasingWithinHeading($element)
                    && ! $projections->isControlPath($parentPath)
                    && ! ('' !== $projections->richTextMarker($path) && $this->ancestorElement($element, 'label') instanceof DOMElement)
                ) {
                    $projections->markInlineLayoutCarrierPath($path);
                }
            }
        }
    }

    /** @param list<array{selector:string,parsed:array<string,mixed>}> $authorSelectors */
    private function discoverAuthorAttributePaths(array $authorSelectors, AuthorStyleAnalysis $authorStyles, AuthorSelectorProjectionState $projections): void
    {
        foreach ( $authorSelectors as $authorSelector ) {
            $parsed = $authorSelector['parsed'];
            $this->discoverNegatedDataAttributeState($authorSelector['selector'], $authorStyles, $projections);
            $this->discoverAncestorAttributeState($authorSelector['selector'], $authorStyles, $projections);
            $pseudoHost = CssSelectorMatcher::pseudoElementHost($authorSelector['selector']);
            $selector = $pseudoHost['selector'] ?? $authorSelector['selector'];
            $parsed = $pseudoHost['parsed'] ?? $parsed;
            if ( ! $parsed['supported'] || null !== $parsed['pseudo_state_suffix_span'] ) {
                continue;
            }

            $rightmostSpan = $parsed['rightmost_compound_span'] ?? null;
            $ancestry = is_array($rightmostSpan) ? substr($selector, 0, (int) $rightmostSpan['start']) : '';
            if ( preg_match('/\[\s*data-[a-z0-9_-]+(?:\s*[~|^$*]?=|\s*\])/i', $ancestry) ) {
                foreach ( $this->matchingSourceElements($authorStyles, $selector, $parsed) as $element ) {
                    $parent = $element->parentNode;
                    if ( preg_match('/>\s*$/', trim($ancestry)) && $parent instanceof DOMElement ) {
                        $parentPath = $parent->getNodePath() ?? '';
                        if ( '' !== $parentPath ) {
                            $marker = $projections->ensureAttributeMarker($parentPath, $selector);
                            $parent->setAttribute('class', SourceDom::mergeClassNames($parent->getAttribute('class'), $marker));
                        }
                    }
                    if ( self::hasSafeAnchor($element->getAttribute('id')) ) {
                        continue;
                    }
                    $path = $element->getNodePath() ?? '';
                    if ( '' !== $path ) {
                        $marker = $projections->ensureAttributeMarker($path, $selector);
                        $element->setAttribute('class', SourceDom::mergeClassNames($element->getAttribute('class'), $marker));
                    }
                }
            }

            $compounds = $parsed['compounds'] ?? array();
            $rightmost = $compounds[array_key_last($compounds)] ?? array();
            if ( ! CssSelectorCompoundInspector::containsDataAttribute($rightmost) ) {
                continue;
            }
            foreach ( $this->matchingSourceElements($authorStyles, $selector, $parsed) as $element ) {
                $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
                $hasBoxGeometry = array() !== array_intersect_key($declarations, array_flip(array(
                    'display', 'position', 'inset', 'top', 'right', 'bottom', 'left',
                    'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
                    'margin', 'padding', 'flex', 'flex-basis', 'flex-grow', 'flex-shrink', 'grid', 'grid-area',
                )));
                if ( null === $pseudoHost && ! $hasBoxGeometry
                    && ! $this->selectorRuleDeclaresBoxGeometry($authorSelector['selector'], $authorStyles)
                    && 'img' !== strtolower($element->tagName)
                ) {
                    continue;
                }
                $path = $element->getNodePath() ?? '';
                if ( '' !== $path ) {
                    $marker = $projections->ensureAttributeMarker($path, $selector);
                    $element->setAttribute('class', SourceDom::mergeClassNames($element->getAttribute('class'), $marker));
                }
            }
        }
    }

    private function selectorRuleDeclaresBoxGeometry(string $selector, AuthorStyleAnalysis $authorStyles): bool
    {
        $geometry = array_fill_keys(array(
            'display', 'position', 'inset', 'top', 'right', 'bottom', 'left',
            'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
            'margin', 'padding', 'flex', 'flex-basis', 'flex-grow', 'flex-shrink', 'grid', 'grid-area',
        ), true);
        foreach ($authorStyles->styleRules() as $rule) {
            foreach ($rule['selectors'] ?? array() as $candidate) {
                if ($selector !== ($candidate['selector'] ?? null)) {
                    continue;
                }
                if (array_intersect_key($rule['declarations'] ?? array(), $geometry) !== array()) {
                    return true;
                }
            }
        }
        return false;
    }

    private function discoverNegatedDataAttributeState(string $selector, AuthorStyleAnalysis $authorStyles, AuthorSelectorProjectionState $projections): void
    {
        if ( 1 !== preg_match_all(
            '/:not\(\s*(\[\s*data-[a-z0-9_-]+(?:\s*[~|^$*]?=\s*(?:"[^"]*"|\'[^\']*\'|[^\]\s]+))?\s*\])\s*\)/i',
            $selector,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        ) ) {
            return;
        }

        $negation = $matches[0][0][0];
        $attributeSelectorText = $matches[0][1][0];
        $attributeSelector = CssSelectorMatcher::parse($attributeSelectorText);
        if ( ! $attributeSelector['supported'] ) {
            return;
        }

        // State belongs to the element carrying the negated attribute, not
        // necessarily the rightmost element matched by the full selector. A
        // selector such as `.media-inner:not([data-ratio="original"])
        // .list-image` matches the image, but the generated :not(marker) must
        // inspect the media-inner wrapper. Stop at the negation's closing
        // parenthesis before resolving the owner element.
        $negationOffset = $matches[0][0][1];
        $stateOwnerPrelude = substr($selector, 0, $negationOffset + strlen($negation));
        $stateOwnerSelectorText = preg_replace(
            '/:not\(\s*' . preg_quote($attributeSelectorText, '/') . '\s*\)/i',
            $attributeSelectorText,
            $stateOwnerPrelude,
            1
        ) ?? $stateOwnerPrelude;
        $stateOwnerSelector = CssSelectorMatcher::parse($stateOwnerSelectorText);
        $candidateSelector = $stateOwnerSelector['supported'] ? $stateOwnerSelector : $attributeSelector;
        // Project the positive state even when this source document has no
        // element in that state. The emitted selector is the negation of this
        // marker: with zero marked elements it must still match every source
        // element that did not carry the negated attribute value. Leaving the
        // original attribute selector behind is incorrect once editable block
        // serialization drops that presentation-only data attribute.
        $marker = $authorStyles->allocateStableMarker('attribute-state', $stateOwnerSelectorText);
        foreach ( $authorStyles->selectorCandidates($candidateSelector) as $element ) {
            if ( ! CssSelectorMatcher::matches($element, $candidateSelector, true, $authorStyles->selectorMatchCache())['matches'] ) {
                continue;
            }
            $path = $element->getNodePath() ?? '';
            if ( '' !== $path ) {
                $projections->addAttributeStateMarker($path, $marker);
                $element->setAttribute('class', SourceDom::mergeClassNames($element->getAttribute('class'), $marker));
            }
        }
        $projections->installAttributeNegationMarker($selector, $marker);
    }

    /**
     * Ancestor attribute conditions such as Wix's
     * `.mu5PoX[aria-disabled=false] .twJknM` select on a container whose
     * block serialization keeps only id, class and style. Once the container
     * becomes a group the condition can never match again, so the descendant
     * loses every declaration the rule owned (the default Wix button skin
     * paints its fill and border this way). Carry the condition as a stable
     * class on each ancestor that satisfies it for an element the rule can
     * style, and record a sibling selector that reads the class instead of
     * the attribute. The projector emits both: the class form keeps converted
     * containers matching, the original keeps wrappers that retain their
     * attributes (preserved layout shells, inline anchors) matching, and a
     * class carries the same specificity as the attribute it replaces.
     * `data-*` conditions keep their existing per-element projection.
     */
    private function discoverAncestorAttributeState(string $selector, AuthorStyleAnalysis $authorStyles, AuthorSelectorProjectionState $projections): void
    {
        $selector = trim($selector);
        if ( ! str_contains($selector, '[') || '' !== $projections->ancestorAttributeStateSelector($selector) ) {
            return;
        }
        $rightmostStart = self::rightmostCompoundStart($selector);
        if ( null === $rightmostStart || 0 === $rightmostStart ) {
            return;
        }
        $ancestry = substr($selector, 0, $rightmostStart);
        if ( ! preg_match_all(self::ANCESTOR_ATTRIBUTE_CONDITION, $ancestry, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) ) {
            return;
        }
        // Only ancestors of an element the rule can style carry the marker;
        // the condition's other holders (the inner anchor also reads
        // aria-disabled="false") keep their attribute and need no class.
        $subjects = $this->ancestorAttributeSubjects(substr($selector, $rightmostStart), $authorStyles);
        if ( null === $subjects ) {
            return;
        }
        $projected = $ancestry;
        $marked = false;
        foreach ( array_reverse($matches) as $match ) {
            $condition = $match[0][0];
            $name = strtolower($match[1][0]);
            if ( in_array($name, array( 'class', 'id', 'style' ), true) || str_starts_with($name, 'data-')
                || in_array($name, self::RUNTIME_STATE_ATTRIBUTES, true)
                || substr_count($ancestry, '(', 0, $match[0][1]) !== substr_count($ancestry, ')', 0, $match[0][1])
            ) {
                continue;
            }
            $parsedCondition = CssSelectorMatcher::parse($condition);
            if ( ! $parsedCondition['supported'] ) {
                continue;
            }
            $marker = $authorStyles->allocateStableMarker('attribute-state', 'ancestor-attribute:' . preg_replace('/\s+/', '', $condition));
            foreach ( $subjects as $subject ) {
                for ( $element = $subject->parentNode; $element instanceof DOMElement; $element = $element->parentNode ) {
                    if ( ! in_array(strtolower($element->tagName), self::GROUPED_CONTAINER_TAGS, true)
                        || ! CssSelectorMatcher::matches($element, $parsedCondition, true, $authorStyles->selectorMatchCache())['matches']
                    ) {
                        continue;
                    }
                    $path = $element->getNodePath() ?? '';
                    if ( '' === $path ) {
                        continue;
                    }
                    $marked = true;
                    if ( in_array($marker, $projections->attributeStateMarkers($path), true) ) {
                        continue;
                    }
                    $projections->addAttributeStateMarker($path, $marker);
                    $element->setAttribute('class', SourceDom::mergeClassNames($element->getAttribute('class'), $marker));
                }
            }
            $projected = substr_replace($projected, '.' . $marker, $match[0][1], strlen($condition));
        }
        if ( $marked ) {
            $projections->installAncestorAttributeStateSelector($selector, $projected . substr($selector, $rightmostStart));
        }
    }

    /**
     * Generic containers that convert to blocks serializing only id, class
     * and style. Other holders (custom elements and `details` kept as layout
     * shells, inline anchors) retain their attributes and the original
     * selector keeps matching them.
     */
    private const GROUPED_CONTAINER_TAGS = array( 'div', 'section', 'article', 'aside', 'main', 'header', 'footer', 'nav', 'figure', 'form', 'ul', 'ol', 'li' );

    /**
     * Attributes a runtime toggles (`details[open]`, `[aria-expanded=true]`).
     * A class frozen from the captured state would pin the rule on, so these
     * stay with their own projections.
     */
    private const RUNTIME_STATE_ATTRIBUTES = array( 'open', 'checked', 'selected', 'hidden', 'inert', 'aria-expanded', 'aria-selected', 'aria-checked', 'aria-pressed', 'aria-hidden' );

    /**
     * Attribute conditions written directly in a compound. Conditions nested
     * inside a functional pseudo-class argument (`:not([hidden])`) are
     * skipped by the caller's parenthesis-depth check.
     */
    private const ANCESTOR_ATTRIBUTE_CONDITION = '/\[\s*([a-z_][a-z0-9_:-]*)\s*(?:[~|^$*]?=\s*(?:"[^"]*"|\'[^\']*\'|[^\]\s]+)(?:\s+[is])?)?\s*\]/i';

    /**
     * Source elements matching the rightmost compound, ignoring its trailing
     * pseudo-classes and pseudo-elements (`.twJknM:hover`, `.x::before`).
     *
     * @return list<DOMElement>|null Null when the compound cannot be matched.
     */
    private function ancestorAttributeSubjects(string $rightmost, AuthorStyleAnalysis $authorStyles): ?array
    {
        $parsed = CssSelectorMatcher::parse($rightmost);
        if ( ! $parsed['supported'] ) {
            $rightmost = (string) preg_replace('/^([^:]*?)(?<!\\\\):.*$/s', '$1', $rightmost);
            $parsed = '' === trim($rightmost) ? $parsed : CssSelectorMatcher::parse($rightmost);
        }
        return $parsed['supported'] ? $this->matchingSourceElements($authorStyles, $rightmost, $parsed) : null;
    }

    /** Byte offset where the rightmost compound starts, or null when the selector cannot be split safely. */
    private static function rightmostCompoundStart(string $selector): ?int
    {
        $start = 0;
        $depth = 0;
        $quote = '';
        $length = strlen($selector);
        for ( $offset = 0; $offset < $length; ++$offset ) {
            $char = $selector[ $offset ];
            if ( '' !== $quote ) {
                if ( '\\' === $char ) {
                    ++$offset;
                } elseif ( $quote === $char ) {
                    $quote = '';
                }
                continue;
            }
            if ( '"' === $char || "'" === $char ) {
                $quote = $char;
            } elseif ( '\\' === $char ) {
                ++$offset;
            } elseif ( '(' === $char || '[' === $char ) {
                ++$depth;
            } elseif ( ')' === $char || ']' === $char ) {
                if ( 0 === $depth ) {
                    return null;
                }
                --$depth;
            } elseif ( 0 === $depth && ( ctype_space($char) || '>' === $char || '+' === $char || '~' === $char ) ) {
                $start = $offset + 1;
            } elseif ( 0 === $depth && ',' === $char ) {
                return null;
            }
        }
        return 0 === $depth && '' === $quote ? $start : null;
    }

    /** @param array<string, mixed> $options */
    private function discoverRuntimeAttributeSelectorPaths(
        array $options,
        SourceStyleResolutionState $sourceStyles,
        AuthorStyleAnalysis $authorStyles,
        AuthorSelectorProjectionState $projections
    ): void {
        $selectors = is_array($options['runtime_projection_selectors'] ?? null) ? $options['runtime_projection_selectors'] : array();
        foreach ( $selectors as $selector ) {
            if ( ! is_string($selector) || ! preg_match('/\[\s*data-[a-z0-9_-]+(?:\s*[~|^$*]?=|\s*\])/i', $selector) ) {
                continue;
            }
            $parsed = $sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                continue;
            }
            $markers = array();
            foreach ( $this->matchingSourceElements($authorStyles, $selector, $parsed) as $element ) {
                $path = $element->getNodePath() ?? '';
                if ( '' === $path ) {
                    continue;
                }
                $marker = $projections->ensureAttributeMarker($path);
                $element->setAttribute('class', SourceDom::mergeClassNames($element->getAttribute('class'), $marker));
                $markers[] = $marker;
            }
            if ( array() !== $markers ) {
                $projections->installRuntimeAttributeSelectorMarkers($selector, $markers);
            }
        }
    }

    /** @param list<array{selector:string,parsed:array<string,mixed>}> $authorSelectors */
    private function discoverAuthorRootChildPaths(array $authorSelectors, AuthorStyleAnalysis $authorStyles, AuthorSelectorProjectionState $projections): void
    {
        foreach ( $authorSelectors as $authorSelector ) {
            $selector = $authorSelector['selector'];
            $parsed = $authorSelector['parsed'];
            if ( ! $parsed['supported'] || ! self::isRootChildSelector($parsed) ) {
                continue;
            }
            foreach ( $this->matchingSourceElements($authorStyles, $selector, $parsed) as $element ) {
                if ( in_array(strtolower($element->tagName), array( 'link', 'meta', 'script', 'style', 'template', 'title' ), true) ) {
                    continue;
                }
                $path = $element->getNodePath() ?? '';
                if ( '' !== $path ) {
                    $projections->ensureRootChildMarker($path);
                }
            }
        }
    }

    /** @param list<array{selector:string,parsed:array<string,mixed>}> $authorSelectors */
    private function discoverAuthorTablePaths(array $authorSelectors, AuthorStyleAnalysis $authorStyles, AuthorSelectorProjectionState $projections): void
    {
        foreach ( $authorSelectors as $authorSelector ) {
            $selector = $authorSelector['selector'];
            $parsed = $authorSelector['parsed'];
            if ( ! $parsed['supported'] ) {
                continue;
            }
            foreach ( $this->matchingSourceElements($authorStyles, $selector, $parsed) as $element ) {
                if ( ! in_array(strtolower($element->tagName), array( 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th' ), true)
                    || ! $this->context->tableSelectorNeedsStructuralProjection($parsed, $element)
                ) {
                    continue;
                }
                $table = $this->ancestorElement($element, 'table');
                if ( ! $table instanceof DOMElement || ! $this->context->isRepresentableTable($table) ) {
                    continue;
                }
                $path = $table->getNodePath() ?? '';
                if ( '' !== $path ) {
                    $projections->ensureTableMarker($path);
                }
            }
        }
    }

    /** @param array<string, mixed> $parsed */
    private static function richTextSelectorNeedsHook(array $parsed): bool
    {
        foreach ( $parsed['compounds'] as $compound ) {
            if ( array() !== $compound['classes'] || array() !== $compound['ids'] || array() !== $compound['attributes'] ) {
                return true;
            }
        }
        return false;
    }

    private static function hasSafeAnchor(string $id): bool
    {
        return 1 === preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', trim($id));
    }
    /**
     * A heading's phrasing content is its RichText value, never a set of
     * sibling paragraph carriers: a block-display span inside a heading stays an
     * inline element of the heading, so its rules must not be scoped behind a
     * carrier paragraph that is never emitted.
     */
    private function isPhrasingWithinHeading(DOMElement $element): bool
    {
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            $tag = strtolower($parent->tagName);
            if ( 1 === preg_match('/^h[1-6]$/', $tag) ) {
                return true;
            }
            if ( ! $this->context->isInlineContentElement($tag) ) {
                return false;
            }
        }

        return false;
    }

    private function ancestorElement(DOMElement $element, string $tagName): ?DOMElement
    {
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( $tagName === strtolower($parent->tagName) ) {
                return $parent;
            }
        }
        return null;
    }
}
