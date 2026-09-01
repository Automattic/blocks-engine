<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
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
            return '' !== $class && (bool) preg_match('/\.' . preg_quote($class, '/') . '(?:\b|(?=[.#:\[]))/', $authorStyles->combinedCss());
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
            $controls = array_filter(
                $this->matchingSourceElements($authorStyles, $selector, $parsed),
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
                    && ! $projections->isControlPath($parentPath)
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
            if ( ! $parsed['supported'] || null !== $parsed['pseudo_state_suffix_span'] ) {
                continue;
            }

            $rightmostSpan = $parsed['rightmost_compound_span'] ?? null;
            $ancestry = is_array($rightmostSpan) ? substr($authorSelector['selector'], 0, (int) $rightmostSpan['start']) : '';
            if ( preg_match('/\[\s*data-[a-z0-9_-]+(?:\s*[~|^$*]?=|\s*\])/i', $ancestry) ) {
                foreach ( $this->matchingSourceElements($authorStyles, $authorSelector['selector'], $parsed) as $element ) {
                    if ( self::hasSafeAnchor($element->getAttribute('id')) ) {
                        continue;
                    }
                    $path = $element->getNodePath() ?? '';
                    if ( '' !== $path ) {
                        $marker = $projections->ensureAttributeMarker($path);
                        $element->setAttribute('class', self::mergeClassNames($element->getAttribute('class'), $marker));
                    }
                }
            }

            $compounds = $parsed['compounds'] ?? array();
            $rightmost = $compounds[array_key_last($compounds)] ?? array();
            $hasDataAttribute = array_filter($rightmost['attributes'] ?? array(), static fn (array $attribute): bool => str_starts_with($attribute['name'] ?? '', 'data-'));
            if ( array() === $hasDataAttribute ) {
                continue;
            }
            foreach ( $this->matchingSourceElements($authorStyles, $authorSelector['selector'], $parsed) as $element ) {
                $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
                $hasBoxGeometry = array() !== array_intersect_key($declarations, array_flip(array(
                    'display', 'position', 'inset', 'top', 'right', 'bottom', 'left',
                    'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
                    'margin', 'padding', 'flex', 'flex-basis', 'flex-grow', 'flex-shrink', 'grid', 'grid-area',
                )));
                if ( ! $hasBoxGeometry && 'img' !== strtolower($element->tagName) ) {
                    continue;
                }
                $path = $element->getNodePath() ?? '';
                if ( '' !== $path ) {
                    $marker = $projections->ensureAttributeMarker($path);
                    $element->setAttribute('class', self::mergeClassNames($element->getAttribute('class'), $marker));
                }
            }
        }
    }

    private function discoverNegatedDataAttributeState(string $selector, AuthorStyleAnalysis $authorStyles, AuthorSelectorProjectionState $projections): void
    {
        if ( 1 !== preg_match_all(
            '/:not\(\s*(\[\s*data-[a-z0-9_-]+(?:\s*[~|^$*]?=\s*(?:"[^"]*"|\'[^\']*\'|[^\]\s]+))?\s*\])\s*\)/i',
            $selector,
            $matches
        ) || 1 !== count($matches[1] ?? array()) ) {
            return;
        }

        $attributeSelector = CssSelectorMatcher::parse($matches[1][0]);
        if ( ! $attributeSelector['supported'] ) {
            return;
        }

        $marker = '';
        foreach ( $authorStyles->selectorCandidates($attributeSelector) as $element ) {
            if ( ! CssSelectorMatcher::matches($element, $attributeSelector, true, $authorStyles->selectorMatchCache())['matches'] ) {
                continue;
            }
            $path = $element->getNodePath() ?? '';
            if ( '' !== $path ) {
                $marker = '' === $marker ? $authorStyles->allocateMarker('attribute-state') : $marker;
                $projections->addAttributeStateMarker($path, $marker);
                $element->setAttribute('class', self::mergeClassNames($element->getAttribute('class'), $marker));
            }
        }
        if ( '' !== $marker ) {
            $projections->installAttributeNegationMarker($selector, $marker);
        }
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
                $element->setAttribute('class', self::mergeClassNames($element->getAttribute('class'), $marker));
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

    private static function mergeClassNames(string ...$classNames): string
    {
        $classes = array();
        foreach ( $classNames as $className ) {
            foreach ( preg_split('/\s+/', trim($className)) ?: array() as $class ) {
                if ( '' !== $class && ! in_array($class, $classes, true) ) {
                    $classes[] = $class;
                }
            }
        }
        return implode(' ', $classes);
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
