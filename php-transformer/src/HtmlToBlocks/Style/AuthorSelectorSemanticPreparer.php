<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorCompoundInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\StylesheetActivation;
use DOMElement;

/** Prepares source identities needed to project author selectors onto canonical blocks. */
final class AuthorSelectorSemanticPreparer
{
    /** @var list<string> Executable source scripts outside the body DOM, such as head runtimes. */
    private array $runtimeScriptSources = array();

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
            : implode("\n\n", array_column(array_filter($stylesheetAssets, static fn(array $asset): bool => StylesheetActivation::active($asset)), 'content'));
        $authorStyles = new AuthorStyleAnalysis($html, $combinedAuthorCss, $stylesheetAssets, $sourceBody);
        $this->runtimeScriptSources = array_values(array_filter(
            array_column($session->runtimeBehaviorState()->runtimeProjectionScriptAssets(), 'content'),
            'is_string'
        ));
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
            // Attribute-carrying native hosts and document roots remain live
            // source subjects; a captured-state marker would freeze their CSS.
            if (SourceAttributeSubjects::retainsDataPredicates($parsed, $authorStyles)) continue;
            if ($this->retainsRuntimeOwnedDataState($authorSelector['selector'], $parsed, $authorStyles, $projections)) {
                $projections->retainRuntimeOwnedSelector($authorSelector['selector']);
                continue;
            }
            $this->discoverNegatedDataAttributeState($authorSelector['selector'], $authorStyles, $projections);
            $this->discoverAncestorAttributeState($authorSelector['selector'], $authorStyles, $projections);
            $pseudoHost = CssSelectorMatcher::pseudoElementHost($authorSelector['selector']);
            $selector = $pseudoHost['selector'] ?? $authorSelector['selector'];
            $parsed = $pseudoHost['parsed'] ?? $parsed;
            if (SourceAttributeSubjects::retainsDataPredicates($parsed, $authorStyles)) continue;
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
                            $marker = $projections->ensureAttributeMarker($parentPath, AuthorSelectorProjectionState::parentAttributeIdentity($selector));
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

    /**
     * A data predicate that a source script writes is runtime state, not
     * captured state. Device documents show this: the head selection script sets
     * `data-dla-selected-document` on <html> after capture, so
     * `html:not([data-dla-selected-document]) [data-dla-device-document="desktop"]`
     * must stay a live attribute test. A marker frozen from the captured
     * root (which never had the attribute) would match on every device and
     * show the desktop document beside the selected one.
     *
     * The selector is kept as authored only when every source subject is a
     * declared document variant root, whose source attributes the output
     * keeps, so the authored selector still finds it.
     *
     * @param array<string, mixed> $parsed
     */
    private function retainsRuntimeOwnedDataState(string $selector, array $parsed, AuthorStyleAnalysis $authorStyles, AuthorSelectorProjectionState $projections): bool
    {
        if ( ! ($parsed['supported'] ?? false) || null !== ($parsed['pseudo_state_suffix_span'] ?? null) ) {
            return false;
        }
        $runtimeOwned = false;
        foreach ( $parsed['compounds'] ?? array() as $compound ) {
            foreach ( CssSelectorCompoundInspector::dataAttributeNames($compound) as $name ) {
                if ( $this->sourceScriptsWriteAttribute($authorStyles, $projections, strtolower($name)) ) {
                    $runtimeOwned = true;
                    break 2;
                }
            }
        }
        if ( ! $runtimeOwned ) {
            return false;
        }
        $subjects = $this->matchingSourceElements($authorStyles, $selector, $parsed);
        foreach ( $subjects as $subject ) {
            if ( ! $subject->hasAttribute('data-dla-document-scope') || ! SourceDom::isDocumentVariantRoot($subject) ) {
                return false;
            }
        }
        return array() !== $subjects;
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
        $candidates = $authorStyles->selectorCandidates($candidateSelector);
        // Candidate pools index body descendants only, so a state owned by the
        // document root is never evaluated: the projected `:root:not(.marker)`
        // would address a marker no route ever materializes. The root itself
        // carries the state (`data-launched` on `<html>`), so it joins the
        // evaluation and receives the marker exactly when the positive
        // predicate matches it.
        $documentRoot = $authorStyles->sourceBody()->ownerDocument?->documentElement;
        if ( $documentRoot instanceof DOMElement && ! in_array($documentRoot, $candidates, true) ) {
            $candidates[] = $documentRoot;
        }
        foreach ( $candidates as $element ) {
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
     * paints its fill and border this way).
     *
     * For each ancestor compound carrying such a condition, the holders are
     * the elements matching that compound on the ancestor chain of an element
     * the full selector styles (dynamic pseudo-classes are ignored for this
     * static match). Holders that convert to groups receive
     * a stable class through the class-only marker channel, which never
     * changes wrapper preservation or layout decisions. The projector then
     * derives a sibling selector from the original's projected output with
     * each condition replaced by its class, so every per-element safeguard the
     * original received carries over. A class has the specificity of the
     * attribute it replaces.
     *
     * Skipped: pseudo-element rules (projected outside the selector path the
     * class form is derived from), `data-*` conditions (their own projection),
     * conditions also used inside functional pseudo-classes, runtime-toggled
     * states, conditions an executable source script writes, and selectors whose ancestry keeps
     * another condition that would be lost (the class form could never match).
     */
    private function discoverAncestorAttributeState(string $selector, AuthorStyleAnalysis $authorStyles, AuthorSelectorProjectionState $projections): void
    {
        $selector = trim($selector);
        if ( ! str_contains($selector, '[') || $projections->hasAncestorAttributeStateDecision($selector) ) {
            return;
        }
        $projections->installAncestorAttributeStateConditions($selector, array());
        $rightmostStart = self::rightmostCompoundStart($selector);
        if ( null === $rightmostStart || 0 === $rightmostStart || 1 === preg_match(self::PSEUDO_ELEMENT, $selector) ) {
            return;
        }
        $ancestry = substr($selector, 0, $rightmostStart);
        if ( ! preg_match_all(self::ANCESTOR_ATTRIBUTE_CONDITION, $ancestry, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) ) {
            return;
        }
        // The projector swaps every copy of a condition for its class. A copy
        // inside :not()/:is() would turn `.a[r=g] .b:not([r=g])` into
        // `.a.M .b:not(.M)`, which over-matches once the inner holder also
        // carries the class, so such selectors keep only the original.
        $nested = array();
        foreach ( $matches as $match ) {
            if ( 0 !== substr_count($ancestry, '(', 0, $match[0][1]) - substr_count($ancestry, ')', 0, $match[0][1]) ) {
                $nested[$match[0][0]] = true;
            }
        }
        $conditions = array();
        foreach ( $matches as $match ) {
            $condition = $match[0][0];
            $name = strtolower($match['name'][0]);
            $depthBefore = substr_count($ancestry, '(', 0, $match[0][1]) - substr_count($ancestry, ')', 0, $match[0][1]);
            if ( in_array($name, array( 'class', 'id', 'style' ), true) && 0 === $depthBefore ) {
                continue; // Serialized blocks keep these; the condition still matches.
            }
            if ( 0 !== $depthBefore ) {
                continue; // Inside :not()/:is(); left to the original selector.
            }
            if ( str_starts_with($name, 'data-') || in_array($name, self::RUNTIME_STATE_ATTRIBUTES, true)
                || isset($nested[$condition])
                || str_contains(substr($selector, $rightmostStart), $condition)
                || $this->sourceScriptsWriteAttribute($authorStyles, $projections, $name)
            ) {
                return; // A condition the class form cannot carry: emitting it would be dead or frozen.
            }
            $parsedCondition = CssSelectorMatcher::parse($condition);
            if ( ! $parsedCondition['supported'] ) {
                return;
            }
            $compound = self::staticSelector(self::enclosingCompound($ancestry, $match[0][1]));
            $parsedCompound = CssSelectorMatcher::parse($compound);
            if ( ! $parsedCompound['supported'] ) {
                return;
            }
            $conditions[] = array(
                'text'     => $condition,
                'compound' => $parsedCompound,
                'compound_text' => $compound,
                'marker'   => $authorStyles->allocateStableMarker('attribute-state', 'ancestor-attribute:' . self::conditionIdentity($match)),
            );
        }
        if ( array() === $conditions ) {
            return;
        }
        $static = self::staticSelector($selector);
        $parsedStatic = CssSelectorMatcher::parse($static);
        if ( ! $parsedStatic['supported'] ) {
            return;
        }
        $subjects = $this->matchingSourceElements($authorStyles, $static, $parsedStatic);
        $installed = array();
        foreach ( $conditions as $condition ) {
            $marker = $condition['marker'];
            $holderKey = $marker . "\n" . $condition['compound_text'];
            foreach ( $subjects as $subject ) {
                for ( $element = $subject->parentNode; $element instanceof DOMElement; $element = $element->parentNode ) {
                    $path = $element->getNodePath() ?? '';
                    // Ancestors above an already visited holder were walked
                    // for this marker by an earlier subject.
                    if ( '' === $path || ! $projections->visitAncestorAttributeStatePath($holderKey, $path) ) {
                        break;
                    }
                    if ( ! in_array(strtolower($element->tagName), self::GROUPED_CONTAINER_TAGS, true)
                        || ! CssSelectorMatcher::matches($element, $condition['compound'], true, $authorStyles->selectorMatchCache())['matches']
                    ) {
                        continue;
                    }
                    $projections->addAncestorAttributeStateMarker($path, $marker, $holderKey);
                }
            }
            if ( ! $projections->hasAncestorAttributeStateHolder($holderKey) ) {
                return; // No holder of this condition is emitted in this document.
            }
            $installed[$condition['text']] = $marker;
        }
        $projections->installAncestorAttributeStateConditions($selector, $installed);
    }

    /**
     * Generic containers that convert to blocks serializing only id, class
     * and style. Other holders (custom elements and `details` kept as layout
     * shells, inline anchors) retain their attributes and the original
     * selector keeps matching them.
     */
    private const GROUPED_CONTAINER_TAGS = array( 'div', 'section', 'article', 'aside', 'main', 'header', 'footer', 'nav', 'figure', 'form', 'ul', 'ol', 'li' );

    /**
     * Attributes a runtime or the page toggles (`details[open]`,
     * `[aria-expanded=true]`, per-page `[aria-current=page]` in shared
     * chrome). A class frozen from the captured state would pin the rule on.
     */
    private const RUNTIME_STATE_ATTRIBUTES = array( 'open', 'checked', 'selected', 'hidden', 'inert', 'aria-expanded', 'aria-selected', 'aria-checked', 'aria-pressed', 'aria-hidden', 'aria-current' );

    /** Attribute conditions; nested ones are excluded by the caller's parenthesis-depth check. */
    private const ANCESTOR_ATTRIBUTE_CONDITION = '/\[\s*(?<name>[a-z_][a-z0-9_:-]*)\s*(?:(?<operator>[~|^$*]?=)\s*(?:"(?<dq>[^"]*)"|\'(?<sq>[^\']*)\'|(?<bare>[^\]\s]+))(?:\s+(?<flag>[is]))?)?\s*\]/i';

    /** Dynamic pseudo-classes do not change which source elements a rule addresses. */
    private const DYNAMIC_PSEUDO = '/(?<!:):(?:hover|active|focus|focus-visible|focus-within|visited|link|any-link|target)\b(?!\()/i';

    /** Pseudo-element rules are emitted outside the selector projection the class form is derived from. */
    private const PSEUDO_ELEMENT = '/::?(?:before|after|first-letter|first-line|marker|placeholder|selection|backdrop|file-selector-button)\b/i';

    /** @param array<int|string, array{0:string,1:int}> $match */
    private static function conditionIdentity(array $match): string
    {
        $present = static fn (string $group): bool => isset($match[$group]) && $match[$group][1] >= 0;
        $value = null;
        foreach ( array( 'dq', 'sq', 'bare' ) as $group ) {
            if ( $present($group) ) {
                $value = $match[$group][0];
                break;
            }
        }
        return json_encode(array(
            strtolower($match['name'][0]),
            $present('operator') ? $match['operator'][0] : '',
            $value,
            $present('flag') ? strtolower($match['flag'][0]) : '',
        )) ?: '';
    }

    private static function staticSelector(string $selector): string
    {
        return trim((string) preg_replace(self::DYNAMIC_PSEUDO, '', $selector));
    }

    /** The top-level compound of `$ancestry` containing byte `$offset`. */
    private static function enclosingCompound(string $ancestry, int $offset): string
    {
        $start = self::rightmostCompoundStart(substr($ancestry, 0, $offset)) ?? 0;
        $end = $offset;
        $depth = 0;
        $length = strlen($ancestry);
        for ( ; $end < $length; ++$end ) {
            $char = $ancestry[ $end ];
            if ( '(' === $char || '[' === $char ) {
                ++$depth;
            } elseif ( ')' === $char || ']' === $char ) {
                --$depth;
            } elseif ( 0 === $depth && ( ctype_space($char) || '>' === $char || '+' === $char || '~' === $char ) ) {
                break;
            }
        }
        return substr($ancestry, $start, $end - $start);
    }

    /**
     * Whether an executable source script writes `$name` on elements, so a
     * class frozen from the captured value would contradict the runtime.
     * Data blocks (JSON, ld+json, templates) are ignored, and only write
     * forms count: setAttribute/toggleAttribute/removeAttribute, jQuery
     * `.attr(name, value)`, and the ARIA reflection property (`.ariaDisabled =`).
     */
    private function sourceScriptsWriteAttribute(AuthorStyleAnalysis $authorStyles, AuthorSelectorProjectionState $projections, string $name): bool
    {
        $runtimeScriptSources = $this->runtimeScriptSources;
        return $projections->scriptWritesAttribute($name, static function () use ($authorStyles, $name, $runtimeScriptSources): bool {
            $document = $authorStyles->sourceBody()->ownerDocument;
            if ( null === $document ) {
                return false;
            }
            $quoted = '[\'"`]' . preg_quote($name, '/') . '[\'"`]';
            $patterns = array(
                '/\b(?:setAttribute|toggleAttribute|removeAttribute)\s*\(\s*' . $quoted . '/i',
                '/\.attr\s*\(\s*' . $quoted . '\s*,/i',
            );
            if ( str_starts_with($name, 'aria-') ) {
                $property = 'aria' . str_replace(' ', '', ucwords(str_replace('-', ' ', substr($name, 5))));
                $patterns[] = '/\.' . preg_quote($property, '/') . '\s*=(?!=)/';
            }
            // Head runtimes are not in the body DOM; their bound assets are.
            $sources = $runtimeScriptSources;
            foreach ( $document->getElementsByTagName('script') as $script ) {
                $type = strtolower(trim(explode(';', $script instanceof \DOMElement ? $script->getAttribute('type') : '')[0]));
                if ( '' === $type || in_array($type, self::EXECUTABLE_SCRIPT_TYPES, true) ) {
                    $sources[] = $script->textContent;
                }
            }
            foreach ( $sources as $source ) {
                if ( false === stripos($source, $name) && ! str_starts_with($name, 'aria-') ) {
                    continue;
                }
                foreach ( $patterns as $pattern ) {
                    if ( 1 === preg_match($pattern, $source) ) {
                        return true;
                    }
                }
            }
            return false;
        });
    }

    private const EXECUTABLE_SCRIPT_TYPES = array( 'module', 'text/javascript', 'application/javascript', 'application/x-javascript', 'text/ecmascript', 'application/ecmascript', 'text/jscript', 'text/livescript' );

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
                $layoutTable = 'table' === strtolower($element->tagName) ? $element : $this->ancestorElement($element, 'table');
                if (in_array(strtolower($element->tagName), array('table', 'tr', 'td'), true)
                    && $layoutTable instanceof DOMElement
                    && (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\TableClassificationPolicy())->lowersToColumns($layoutTable)
                ) {
                    $projections->ensureSemanticMarker($element->getNodePath() ?? '');
                    continue;
                }
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
                    $projections->ensureTableMarker($path, $table);
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
