<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationEvidenceState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;
use WeakMap;

/** Projects authored rule bodies whose source geometry changes under block wrappers. */
final class AuthorStyleRuleProjector
{
    private const ROOT_FONT_SIZE_PX = 16;

    /** Bounded ancestor walk for stretch-derived percentage-height resolution. */
    private const MAX_STRETCH_ANCESTOR_DEPTH = 12;

    /** Declared sizes that make a box's border box depend on its box model. */
    private const BOX_SIZE_PROPERTIES = array( 'width', 'height' );

    /** Declared padding and border widths that grow a content-box box. */
    private const BOX_CHROME_PROPERTIES = array(
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'padding-block', 'padding-block-start', 'padding-block-end', 'padding-inline', 'padding-inline-start', 'padding-inline-end',
        'border', 'border-width', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
        'border-block', 'border-block-width', 'border-block-start', 'border-block-end', 'border-block-start-width', 'border-block-end-width',
        'border-inline', 'border-inline-width', 'border-inline-start', 'border-inline-end', 'border-inline-start-width', 'border-inline-end-width',
    );

    /** @var WeakMap<AuthorStyleAnalysis, bool> */
    private WeakMap $universalBorderBoxResets;

    /** @var WeakMap<AuthorStyleAnalysis, array<string, list<array{declarations: array<string, string>, conditions: list<string>, specificity: list<int>, order: int}>>> */
    private WeakMap $boxModelFacts;

    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly AuthorSelectorSemanticPreparer $semanticPreparer
    ) {
        $this->universalBorderBoxResets = new WeakMap();
        $this->boxModelFacts = new WeakMap();
    }

    public function project(
        string $prelude,
        string $body,
        AuthorStyleAnalysis $authorStyles,
        SourceStyleResolutionState $sourceStyles,
        TransformationEvidenceState $evidence
    ): string {
        return $this->projectWithDeclarations($prelude, $body, $authorStyles, $sourceStyles, $evidence)['body'];
    }

    /**
     * @param list<string> $conditions At-rules the rule sits inside, outermost first.
     * @return array{body: string, declarations: array<string, string>}
     */
    public function projectWithDeclarations(
        string $prelude,
        string $body,
        AuthorStyleAnalysis $authorStyles,
        SourceStyleResolutionState $sourceStyles,
        TransformationEvidenceState $evidence,
        array $conditions = array()
    ): array {
        $declarations = $this->styleResolver->verbatimCssDeclarations($body);
        $this->acceptProjectedBody($body, $declarations, $this->projectResponsiveCanvasMinimumWidth($prelude, $body, $declarations, $authorStyles, $sourceStyles, $evidence));
        $this->acceptProjectedBody($body, $declarations, $this->projectAutoSizedStructuralPercentageHeight($prelude, $body, $declarations, $authorStyles, $sourceStyles, $evidence, $conditions));
        $this->acceptProjectedBody($body, $declarations, $this->projectSourceContentBoxSizing($prelude, $body, $declarations, $authorStyles, $sourceStyles, $conditions));
        $this->acceptProjectedBody($body, $declarations, $this->projectIntrinsicGridRowTracks($prelude, $body, $declarations, $authorStyles, $sourceStyles));
        return array('body' => $body, 'declarations' => $declarations);
    }

    /** @param array<string, string> $declarations */
    private function acceptProjectedBody(string &$body, array &$declarations, string $projected): void
    {
        if ( $projected === $body ) {
            return;
        }
        $body = $projected;
        $declarations = $this->styleResolver->verbatimCssDeclarations($body);
    }

    /**
     * @param array<string, string> $declarations
     * @param list<string>          $conditions At-rules the rule sits inside, outermost first.
     */
    private function projectSourceContentBoxSizing(string $prelude, string $body, array $declarations, AuthorStyleAnalysis $authorStyles, SourceStyleResolutionState $sourceStyles, array $conditions = array()): string
    {
        // A box sized on either axis is 2×(padding+border) smaller under the
        // WordPress border-box reset; a `height` band loses its bottom padding
        // just as a `width` column loses its side padding. The size and the
        // chrome often come from different rules (an id rule sizes a component
        // while a shared class pads it), so every rule contributing either half
        // restates the content-box model once the matched element's cascade,
        // within this rule's condition domain, carries the other half.
        $ruleSized = self::declaresDefiniteBoxSize($declarations);
        $ruleChrome = self::declaresBoxChrome($declarations);
        if ( isset($declarations['box-sizing']) || ( ! $ruleSized && ! $ruleChrome ) ) {
            return $body;
        }

        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors || $this->authorStylesUseUniversalBorderBoxReset($authorStyles) ) {
            return $body;
        }
        $affected = false;
        foreach ( $selectors as $selector ) {
            $parsed = $sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                return $body;
            }
            foreach ( $this->semanticPreparer->matchingSourceElements($authorStyles, $selector, $parsed) as $element ) {
                if ( 'a' === strtolower($element->tagName)
                    || FormControlClassifier::isControlElement($element)
                    || 'button' === strtolower(trim($element->getAttribute('role')))
                ) {
                    return $body;
                }
                $box = $this->cascadedBoxModel($authorStyles, $element, $conditions);
                $resolved = CssValueInspector::comparable((string) ($box['box-sizing'] ?? ''));
                if ( ! in_array($resolved, array( '', 'content-box', 'initial', 'unset', 'revert', 'revert-layer' ), true) ) {
                    return $body;
                }
                $affected = $affected || (
                    ( $ruleSized || self::declaresDefiniteBoxSize($box) )
                    && ( $ruleChrome || self::declaresBoxChrome($box) )
                );
            }
        }
        if ( ! $affected ) {
            return $body;
        }
        return $body . ( str_ends_with(rtrim($body), ';') ? '' : ';' ) . 'box-sizing:content-box';
    }

    /**
     * The element's cascaded box-model declarations wherever a rule under
     * `$conditions` applies: author declarations whose condition stack holds
     * within that domain (a device-gated stylesheet or media block), plus the
     * inline style. Read from the author rules themselves, which keep
     * `box-sizing` and every border/padding longhand the resting style
     * collections do not classify.
     *
     * @param list<string> $conditions
     * @return array<string, string>
     */
    private function cascadedBoxModel(AuthorStyleAnalysis $authorStyles, DOMElement $element, array $conditions): array
    {
        $held = self::restatableConditions($conditions);
        $facts = array();
        foreach ( $this->boxModelFacts($authorStyles)[ (string) $element->getNodePath() ] ?? array() as $fact ) {
            if ( array() !== array_diff($fact['conditions'], $held) ) {
                continue;
            }
            foreach ( $fact['declarations'] as $property => $value ) {
                CssCascade::apply($facts, (string) $property, array( 'value' => $value, 'important' => CssValueInspector::isImportant($value), 'inline' => false, 'specificity' => $fact['specificity'], 'order' => $fact['order'] ));
            }
        }
        $inline = array_intersect_key($this->styleResolver->cssDeclarations(SourceDom::attr($element, 'style')), self::boxModelProperties());
        foreach ( $inline as $property => $value ) {
            CssCascade::apply($facts, (string) $property, array( 'value' => $value, 'important' => CssValueInspector::isImportant($value), 'inline' => true, 'specificity' => array( 0, 0, 0 ), 'order' => PHP_INT_MAX ));
        }

        $resolved = array();
        foreach ( $facts as $property => $fact ) {
            // A shorthand that wins the cascade resets the longhands it covers.
            foreach ( self::boxModelShorthands($property) as $shorthand ) {
                if ( isset($facts[ $shorthand ]) && CssCascade::wins($facts[ $shorthand ], $fact) ) {
                    continue 2;
                }
            }
            $resolved[ $property ] = (string) $fact['value'];
        }
        return $resolved;
    }

    /**
     * Box-model declarations of every author rule, indexed by the source
     * element each selector matches, built once per author style analysis.
     *
     * @return array<string, list<array{declarations: array<string, string>, conditions: list<string>, specificity: list<int>, order: int}>>
     */
    private function boxModelFacts(AuthorStyleAnalysis $authorStyles): array
    {
        if ( isset($this->boxModelFacts[ $authorStyles ]) ) {
            return $this->boxModelFacts[ $authorStyles ];
        }
        $index = array();
        foreach ( $authorStyles->styleRules() as $rule ) {
            $declarations = array_intersect_key(is_array($rule['declarations'] ?? null) ? $rule['declarations'] : array(), self::boxModelProperties());
            if ( array() === $declarations ) {
                continue;
            }
            $conditions = self::restatableConditions(array_map(
                static fn (mixed $condition): string => (string) preg_replace('#/\*.*?\*/#s', '', (string) $condition),
                is_array($rule['conditions'] ?? null) ? $rule['conditions'] : array()
            ));
            foreach ( is_array($rule['selectors'] ?? null) ? $rule['selectors'] : array() as $record ) {
                $selector = (string) ($record['selector'] ?? '');
                $parsed = is_array($record['parsed'] ?? null) ? $record['parsed'] : array();
                if ( '' === $selector || ! ($parsed['supported'] ?? false) || StyleResolver::selectorCarriesPseudoState($selector) ) {
                    continue;
                }
                $fact = array(
                    'declarations' => $declarations,
                    'conditions' => $conditions,
                    'specificity' => array_values(CssSelectorMatcher::specificityCounts($parsed)),
                    'order' => (int) ($rule['order'] ?? 0),
                );
                foreach ( $this->semanticPreparer->matchingSourceElements($authorStyles, $selector, $parsed) as $element ) {
                    $index[ (string) $element->getNodePath() ][] = $fact;
                }
            }
        }
        return $this->boxModelFacts[ $authorStyles ] = $index;
    }

    /** @return array<string, true> */
    private static function boxModelProperties(): array
    {
        return array_fill_keys(array_merge(array( 'box-sizing' ), self::BOX_SIZE_PROPERTIES, self::BOX_CHROME_PROPERTIES), true);
    }

    /** @return list<string> Shorthands whose declaration resets `$property`. */
    private static function boxModelShorthands(string $property): array
    {
        if ( str_starts_with($property, 'padding-') ) {
            return array( 'padding' );
        }
        if ( 1 === preg_match('/^border-(top|right|bottom|left|block|inline)(?:-(start|end))?-width$/', $property, $match) ) {
            $side = 'border-' . $match[1] . ( isset($match[2]) && '' !== $match[2] ? '-' . $match[2] : '' );
            return array( 'border', 'border-width', $side );
        }
        if ( in_array($property, array( 'border-width', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-block', 'border-inline' ), true) ) {
            return array( 'border' );
        }
        if ( 1 === preg_match('/^border-(block|inline)-(start|end)$/', $property, $match) ) {
            return array( 'border', 'border-' . $match[1] );
        }
        return array();
    }

    /** @param array<string, string> $declarations */
    private static function declaresDefiniteBoxSize(array $declarations): bool
    {
        return ( isset($declarations['width']) && CssValueInspector::hasDefiniteWidth('width:' . $declarations['width']) )
            || ( isset($declarations['height']) && CssValueInspector::hasDefiniteHeight('height:' . $declarations['height']) );
    }

    /** @param array<string, string> $declarations */
    private static function declaresBoxChrome(array $declarations): bool
    {
        foreach ( $declarations as $property => $value ) {
            $property = (string) $property;
            $isBorderWidth = 1 === preg_match('/^border(?:-(?:top|right|bottom|left|block(?:-(?:start|end))?|inline(?:-(?:start|end))?))?(?:-width)?$/', $property);
            if ( ( 'padding' === $property || str_starts_with($property, 'padding-') || $isBorderWidth )
                && CssValueInspector::isNonZero($value)
            ) {
                return true;
            }
        }
        return false;
    }

    private function authorStylesUseUniversalBorderBoxReset(AuthorStyleAnalysis $authorStyles): bool
    {
        if ( isset($this->universalBorderBoxResets[$authorStyles]) ) {
            return $this->universalBorderBoxResets[$authorStyles];
        }

        $usesBorderBox = false;
        $rootUsesBorderBox = false;
        $universalInheritsBoxSizing = false;
        ( new CssStylesheetTransformer() )->visitStyleRules(
            $authorStyles->combinedCss(),
            function (string $prelude, string $body, array $ancestors) use (&$usesBorderBox, &$rootUsesBorderBox, &$universalInheritsBoxSizing): void {
                if ( $usesBorderBox || array() !== $ancestors ) {
                    return;
                }
                $boxSizing = CssValueInspector::comparable((string) ($this->styleResolver->cssDeclarations($body)['box-sizing'] ?? ''));
                if ( ! in_array($boxSizing, array( 'border-box', 'inherit' ), true) ) {
                    return;
                }
                foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                    $selector = trim((string) preg_replace('/\/\*.*?\*\//s', '', $selector));
                    if ( '*' === $selector && 'border-box' === $boxSizing ) {
                        $usesBorderBox = true;
                        return;
                    }
                    if ( '*' === $selector && 'inherit' === $boxSizing ) {
                        $universalInheritsBoxSizing = true;
                    }
                    if ( in_array(strtolower($selector), array( 'html', ':root' ), true) && 'border-box' === $boxSizing ) {
                        $rootUsesBorderBox = true;
                    }
                }
            }
        );
        // The common reset sets the root to border-box and makes every element
        // inherit it. Materialized core Groups preserve that inherited model.
        return $this->universalBorderBoxResets[$authorStyles] = $usesBorderBox
            || ( $rootUsesBorderBox && $universalInheritsBoxSizing );
    }

    /** @param array<string, string> $declarations */
    private function projectResponsiveCanvasMinimumWidth(
        string $prelude,
        string $body,
        array $declarations,
        AuthorStyleAnalysis $authorStyles,
        SourceStyleResolutionState $sourceStyles,
        TransformationEvidenceState $evidence
    ): string {
        $minimumWidth = (string) ($declarations['min-width'] ?? '');
        if ( '' === $minimumWidth ) {
            return $body;
        }
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return $body;
        }
        $matchedSurface = false;
        foreach ( $selectors as $selector ) {
            $parsed = $sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                return $body;
            }
            // Route-owned document predicates now survive on the native root.
            // A minimum width explicitly gated by that state is authored
            // behavior, not an orphaned desktop shell constraint to repair.
            foreach (array_slice($parsed['compounds'] ?? array(), 0, -1) as $compound) {
                if (in_array(strtolower((string) ($compound['type'] ?? '')), array('html', 'body'), true)
                    && (array() !== ($compound['classes'] ?? array()) || array() !== ($compound['ids'] ?? array())
                        || array() !== ($compound['attributes'] ?? array()) || array() !== ($compound['not'] ?? array())
                        || array() !== ($compound['any'] ?? array()))) return $body;
            }
            $matches = $this->semanticPreparer->matchingSourceElements($authorStyles, $selector, $parsed);
            if ( array() === $matches ) {
                continue;
            }
            $matchedSurface = true;
            foreach ( $matches as $element ) {
                $scope = SourceDom::documentVariantRoot($element);
                if ($scope instanceof DOMElement && $scope->hasAttribute('data-dla-document-scope')) return $body;
                if ( ! $this->isWideAbsoluteMinimumWidth($this->styleResolver->resolveCssVariablesInValue($minimumWidth, $element)) ) {
                    return $body;
                }
            }
            $shellMatches = array_filter($matches, fn (DOMElement $element): bool => $this->isPageShellOrSectionSurface($element, $authorStyles));
            if ( count($shellMatches) !== count($matches) ) {
                if ( array() !== $shellMatches ) {
                    $evidence->recordResponsiveGeometryAmbiguity($selector, $minimumWidth);
                }
                return $body;
            }
        }
        if ( ! $matchedSurface ) {
            return $body;
        }
        $important = CssValueInspector::isImportant($minimumWidth) ? '!important' : '';
        $retained = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            if ( 'min-width' !== strtolower(trim((string) strtok($declaration, ':'))) ) {
                $retained[] = $declaration;
            }
        }
        $retained[] = 'min-width:0' . $important;
        $retained[] = 'max-width:100%' . $important;
        return implode(';', $retained);
    }

    /** @param array<string, string> $declarations */
    private function projectIntrinsicGridRowTracks(string $prelude, string $body, array $declarations, AuthorStyleAnalysis $authorStyles, SourceStyleResolutionState $sourceStyles): string
    {
        $rows = (string) ($declarations['grid-template-rows'] ?? '');
        if ( ! $this->gridTemplateRowsContainFractionalTrack($rows) ) {
            return $body;
        }
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return $body;
        }
        $matchedSurface = false;
        foreach ( $selectors as $selector ) {
            $parsed = $sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                return $body;
            }
            $matches = $this->semanticPreparer->matchingSourceElements($authorStyles, $selector, $parsed);
            if ( array() === $matches ) {
                continue;
            }
            $matchedSurface = true;
            foreach ( $matches as $element ) {
                if ( ! $this->isIntrinsicallySizedGridContainer($element, $declarations) ) {
                    return $body;
                }
            }
        }
        if ( ! $matchedSurface ) {
            return $body;
        }
        $rowsWithoutImportant = CssValueInspector::withoutImportant($rows);
        $collapsed = $this->collapseFractionalGridRowTracks($rowsWithoutImportant);
        if ( $collapsed === $rowsWithoutImportant ) {
            return $body;
        }
        $important = CssValueInspector::isImportant($rows) ? '!important' : '';
        $retained = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            if ( 'grid-template-rows' !== strtolower(trim((string) strtok($declaration, ':'))) ) {
                $retained[] = $declaration;
            }
        }
        $retained[] = 'grid-template-rows:' . $collapsed . $important;
        return implode(';', $retained);
    }

    /** @param array<string, string> $ruleDeclarations */
    private function isIntrinsicallySizedGridContainer(DOMElement $element, array $ruleDeclarations): bool
    {
        $declarations = $this->styleResolver->mergeCssDeclarationMaps(
            $this->styleResolver->structuralPresentationDeclarations($element),
            $ruleDeclarations
        );
        $display = strtolower(CssValueInspector::withoutImportant((string) ($declarations['display'] ?? '')));
        if ( 'none' === $display ) {
            return true;
        }
        $position = strtolower(CssValueInspector::withoutImportant((string) ($declarations['position'] ?? '')));
        if ( in_array($position, array( 'absolute', 'fixed' ), true) && $this->hasDefiniteBlockAxisInsets($declarations) ) {
            return false;
        }
        // `$declarations` (unlike the ancestor lookups {@see receivesDefiniteBlockSize}
        // performs) is merged with the specific rule under evaluation, which
        // matters most for a conditional (`@media`) rule: its declarations
        // never reach the unconditional `structuralPresentationDeclarations()`
        // stream on their own.
        $height = $this->styleResolver->resolveStructuralCssVariablesInValue((string) ($declarations['height'] ?? ''), $element);
        $minHeight = $this->styleResolver->resolveStructuralCssVariablesInValue((string) ($declarations['min-height'] ?? ''), $element);
        if ( $this->isDefiniteBlockSize($height) || $this->isDefiniteBlockSize($minHeight) ) {
            return false;
        }

        return ! $this->receivesDefiniteBlockSize($element, 0, $height, $minHeight);
    }

    /** @param array<string, string> $declarations */
    private function hasDefiniteBlockAxisInsets(array $declarations): bool
    {
        foreach ( array( 'inset', 'inset-block' ) as $property ) {
            $value = strtolower(CssValueInspector::withoutImportant((string) ($declarations[$property] ?? '')));
            if ( '' !== $value && ! str_contains($value, 'auto') ) {
                return true;
            }
        }
        foreach ( array( array( 'top', 'bottom' ), array( 'inset-block-start', 'inset-block-end' ) ) as $properties ) {
            $start = strtolower(CssValueInspector::withoutImportant((string) ($declarations[$properties[0]] ?? '')));
            $end = strtolower(CssValueInspector::withoutImportant((string) ($declarations[$properties[1]] ?? '')));
            if ( '' !== $start && 'auto' !== $start && '' !== $end && 'auto' !== $end ) {
                return true;
            }
        }
        return false;
    }

    private function isDefiniteBlockSize(string $value): bool
    {
        $value = strtolower(CssValueInspector::withoutImportant($value));
        if ( '' === $value || in_array($value, array( 'auto', 'none', 'unset', 'inherit', 'initial', '0', '0px', '0%', 'min-content', 'max-content', 'fit-content' ), true) ) {
            return false;
        }
        if ( 1 === preg_match('/^-?[\d.]+%$/', $value) ) {
            return false;
        }
        if ( 1 === preg_match('/^-?[\d.]+(?:px|r?em|vh|dvh|svh|lvh|vw|vmin|vmax|ch|ex|cm|mm|in|pt|pc)$/', $value) ) {
            return 0.0 < (float) $value;
        }
        return 1 === preg_match('/^(?:calc|min|max|clamp|var)\(/', $value)
            && (bool) preg_match('/(?:vh|dvh|svh|lvh|px|r?em)\b/', $value);
    }

    private function isPercentageBlockSize(string $value): bool
    {
        return 1 === preg_match('/^[\d.]+%$/', strtolower(CssValueInspector::withoutImportant($value)));
    }

    /**
     * Whether `$value` is functionally equivalent to the CSS-initial `auto` for
     * this heuristic's purposes: genuinely unset/auto, and therefore eligible
     * to receive a size from default grid/flex stretch alignment. A literal
     * zero is deliberately excluded -- it is a real, non-rescuable value, not
     * an absence of one.
     */
    private function isAutoOrUnsetBlockSize(string $value): bool
    {
        return in_array(strtolower(CssValueInspector::withoutImportant($value)), array( '', 'auto', 'unset', 'inherit', 'initial' ), true);
    }

    /**
     * Whether `$value` is a literal zero length (`0`, `0px`, `0%`, ...).
     * Meaningful only for `min-height` in {@see receivesDefiniteBlockSize}:
     * a zero minimum imposes no floor at all, so -- unlike a zero `height`,
     * which specifies the block axis outright and rules out stretch -- it
     * can never itself prevent an item's height from being resolved by the
     * default grid/flex stretch alignment. Page builders commonly pair it
     * with `height:auto` specifically to opt out of the browser's default
     * `min-height:auto` (a content-based automatic minimum) while still
     * relying on stretch for the actual size.
     */
    private function isZeroBlockSize(string $value): bool
    {
        $value = CssValueInspector::withoutImportant($value);
        return '' !== $value && ! CssValueInspector::isNonZero($value);
    }

    /**
     * Whether `$element` ends up with a definite block size once its ancestor
     * chain is accounted for, even though no single declaration on it states
     * one directly. Two indirect routes reach a definite size the same way a
     * real browser's layout does:
     *
     *  - a percentage height/min-height resolves once its containing block
     *    (the parent) itself has a definite size, transitively;
     *  - an unset/`auto` height on a CSS Grid/Flex item defaults to filling
     *    its parent's track via `align-items: normal` (which computes to
     *    `stretch`), the same way, as long as the item does not opt out with
     *    its own non-stretch `align-self`.
     *
     * A bounded, generic model of these two CSS Grid/Flex behaviors --
     * deliberately not a full layout engine -- is enough to recognize the
     * common "100% all the way up, one definite size far above" wrapper stack
     * (e.g. a repeater/slideshow item) that {@see isIntrinsicallySizedGridContainer}
     * would otherwise treat as unsized at every level, collapsing every
     * `1fr` row track it owns to `min-content` and losing the whole subtree's
     * box even where the eventual size is genuinely resolvable.
     */
    private function receivesDefiniteBlockSize(DOMElement $element, int $depth = 0, ?string $height = null, ?string $minHeight = null): bool
    {
        if ( null === $height || null === $minHeight ) {
            $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
            $height = $this->styleResolver->resolveStructuralCssVariablesInValue((string) ($declarations['height'] ?? ''), $element);
            $minHeight = $this->styleResolver->resolveStructuralCssVariablesInValue((string) ($declarations['min-height'] ?? ''), $element);
        }
        if ( $this->isDefiniteBlockSize($height) || $this->isDefiniteBlockSize($minHeight) ) {
            return true;
        }
        if ( $this->establishesOwnDefiniteBlockSize($element, $height) ) {
            return true;
        }
        if ( self::MAX_STRETCH_ANCESTOR_DEPTH <= $depth ) {
            return false;
        }
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }

        $isPercentage = $this->isPercentageBlockSize($height) || $this->isPercentageBlockSize($minHeight);
        if ( $isPercentage ) {
            return $this->receivesDefiniteBlockSize($parent, $depth + 1);
        }
        if ( ! $this->isAutoOrUnsetBlockSize($height) ) {
            // A genuinely intrinsic-sizing keyword or an explicit length on
            // `height` itself governs the block axis outright, ruling out
            // stretch regardless of `min-height`.
            return false;
        }
        if ( ! $this->isAutoOrUnsetBlockSize($minHeight) && ! $this->isZeroBlockSize($minHeight) ) {
            // A genuinely intrinsic-sizing keyword (min-content, max-content,
            // fit-content, ...) or a real non-zero minimum: not a stretch
            // candidate. A literal zero minimum is excluded from this check
            // {@see isZeroBlockSize}.
            return false;
        }

        // Wix/Squarespace-style page builders commonly gate `display` behind a
        // `var(--token, var(--fallback-token))` indirection (an author-facing
        // override hook that resolves to a literal keyword like `grid` once
        // its own custom property is declared on the very same rule). Resolve
        // it against the parent so that indirection does not hide a real grid
        // container from this check.
        $parentDeclarations = $this->styleResolver->structuralPresentationDeclarations($parent);
        $parentDisplayRaw = (string) ($parentDeclarations['display'] ?? '');
        $parentDisplay = strtolower($this->styleResolver->resolveStructuralCssVariablesInValue(CssValueInspector::withoutImportant($parentDisplayRaw), $parent));
        if ( ! in_array($parentDisplay, array( 'grid', 'inline-grid', 'flex', 'inline-flex' ), true) ) {
            return false;
        }
        $flexDirectionRaw = (string) ($parentDeclarations['flex-direction'] ?? '');
        $flexDirection = strtolower($this->styleResolver->resolveStructuralCssVariablesInValue(CssValueInspector::withoutImportant($flexDirectionRaw), $parent));
        if ( in_array($parentDisplay, array( 'flex', 'inline-flex' ), true)
            && in_array($flexDirection, array( 'column', 'column-reverse' ), true) ) {
            // Stretch only governs the cross axis. A column flex parent sizes
            // its children along the block axis via flex-basis/grow instead:
            // a growing item's post-flexing main size is definite exactly when
            // the container's main size is (CSS Flexbox 9.8), so it fills the
            // container's definite height. A non-growing item keeps its
            // content height and is no size source.
            return $this->growsAlongFlexMainAxis($element)
                && $this->receivesDefiniteBlockSize($parent, $depth + 1);
        }
        $alignSelfRaw = (string) ($this->styleResolver->structuralPresentationDeclarations($element)['align-self'] ?? '');
        $alignSelf = strtolower($this->styleResolver->resolveStructuralCssVariablesInValue(CssValueInspector::withoutImportant($alignSelfRaw), $element));
        if ( '' !== $alignSelf && ! in_array($alignSelf, array( 'stretch', 'auto', 'normal' ), true) ) {
            // The element opted out of the default stretch alignment, so it
            // does not receive a size from its parent's track this way.
            return false;
        }

        return $this->receivesDefiniteBlockSize($parent, $depth + 1);
    }

    /**
     * Whether a percentage height fills a stretched grid or row-flex item.
     *
     * Equal-height cards size the row from content, then stretch each item to
     * that used size. `height:100%` on the item or its descendants resolves
     * against that area even when a section ancestor's own height is auto.
     * Collapsing it to `height:auto` drops the shared baseline. This is not a
     * definite size for fractional grid tracks, which still need a definite
     * container.
     */
    private function percentageHeightFillsStretchedItem(DOMElement $element): bool
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            $parent = $node->parentNode;
            if ( ! $parent instanceof DOMElement ) {
                return false;
            }
            if ( $node !== $element && in_array(strtolower($node->tagName), array( 'footer', 'header', 'section' ), true) ) {
                return false;
            }
            $parentDeclarations = $this->styleResolver->structuralPresentationDeclarations($parent);
            $parentDisplay = strtolower($this->styleResolver->resolveStructuralCssVariablesInValue(
                CssValueInspector::withoutImportant((string) ($parentDeclarations['display'] ?? '')),
                $parent
            ));
            $flexDirection = strtolower($this->styleResolver->resolveStructuralCssVariablesInValue(
                CssValueInspector::withoutImportant((string) ($parentDeclarations['flex-direction'] ?? '')),
                $parent
            ));
            if ( '' === $flexDirection ) {
                $flexFlow = strtolower($this->styleResolver->resolveStructuralCssVariablesInValue(
                    CssValueInspector::withoutImportant((string) ($parentDeclarations['flex-flow'] ?? '')),
                    $parent
                ));
                $flexFlowAxis = (string) (CssValueSplitter::splitTopLevelWhitespace($flexFlow)[0] ?? '');
                if ( in_array($flexFlowAxis, array( 'row', 'row-reverse', 'column', 'column-reverse' ), true) ) {
                    $flexDirection = $flexFlowAxis;
                }
            }
            if ( $this->stretchesOnBlockAxis($node, $parent, $parentDeclarations, $parentDisplay, $flexDirection) ) {
                return true;
            }
            // An intermediate box that does not fill its parent breaks the
            // chain. A non-growing column-flex item keeps content height, so a
            // descendant percentage does not resolve against the stretched card.
            if ( $node !== $element && ! $this->fillsParentBlockSize($node, $parent, $parentDisplay, $flexDirection) ) {
                return false;
            }
        }

        return false;
    }

    private function fillsParentBlockSize(DOMElement $element, DOMElement $parent, string $parentDisplay, string $flexDirection): bool
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        $height = strtolower(CssValueInspector::withoutImportant((string) ($declarations['height'] ?? '')));
        $minHeight = strtolower(CssValueInspector::withoutImportant((string) ($declarations['min-height'] ?? '')));
        if ( $this->isPercentageBlockSize($height) || $this->isPercentageBlockSize($minHeight) ) {
            return true;
        }

        return in_array($parentDisplay, array( 'flex', 'inline-flex' ), true)
            && in_array($flexDirection, array( 'column', 'column-reverse' ), true)
            && $this->growsAlongFlexMainAxis($element);
    }

    /**
     * Whether `$element` is stretched on the block axis by a grid or row-flex parent.
     *
     * Column flex is excluded: its block axis is the main axis, sized by
     * flex-grow rather than stretch.
     *
     * @param array<string, string> $parentDeclarations
     */
    private function stretchesOnBlockAxis(DOMElement $element, DOMElement $parent, array $parentDeclarations, string $parentDisplay, string $flexDirection): bool
    {
        $stretchesCrossAxis = in_array($parentDisplay, array( 'grid', 'inline-grid' ), true)
            || ( in_array($parentDisplay, array( 'flex', 'inline-flex' ), true)
                && ! in_array($flexDirection, array( 'column', 'column-reverse' ), true) );
        if ( ! $stretchesCrossAxis ) {
            return false;
        }
        $alignSelf = strtolower($this->styleResolver->resolveStructuralCssVariablesInValue(
            CssValueInspector::withoutImportant((string) ($this->styleResolver->structuralPresentationDeclarations($element)['align-self'] ?? '')),
            $element
        ));
        if ( 'stretch' === $alignSelf ) {
            return true;
        }
        if ( '' !== $alignSelf && ! in_array($alignSelf, array( 'auto', 'normal' ), true) ) {
            return false;
        }
        $alignItems = strtolower($this->styleResolver->resolveStructuralCssVariablesInValue(
            CssValueInspector::withoutImportant((string) ($parentDeclarations['align-items'] ?? '')),
            $parent
        ));

        return '' === $alignItems || in_array($alignItems, array( 'normal', 'stretch' ), true);
    }

    /**
     * Whether the element's resolved `flex-grow` (longhand, else the first
     * number of the `flex` shorthand) is positive.
     */
    private function growsAlongFlexMainAxis(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        $grow = $this->styleResolver->resolveStructuralCssVariablesInValue(CssValueInspector::withoutImportant((string) ($declarations['flex-grow'] ?? '')), $element);
        if ( '' === trim($grow) ) {
            $flex = strtolower(trim($this->styleResolver->resolveStructuralCssVariablesInValue(CssValueInspector::withoutImportant((string) ($declarations['flex'] ?? '')), $element)));
            $first = (string) (CssValueSplitter::splitTopLevelWhitespace($flex)[0] ?? '');
            if ( '' !== $first && ! is_numeric($first) ) {
                // `flex:auto` and `flex:<basis>` both grow by 1; `none` and
                // the CSS-wide keywords do not.
                return ! in_array($flex, array( 'none', 'initial', 'inherit', 'unset', 'revert' ), true);
            }
            $grow = $first;
        }
        $grow = trim($grow);

        return is_numeric($grow) && 0 < (float) $grow;
    }

    /**
     * Whether `$element` establishes its own definite block size directly,
     * independent of any ancestor. A CSS Grid container with a genuinely
     * auto/unset `height` sizes itself, along the block axis, to the sum of
     * its own row tracks' used sizes -- the reverse relationship from
     * stretch (bottom-up, from the container's own track list, rather than
     * top-down from an ancestor). When at least one of those tracks carries
     * a definite minimum sizing function (a length, or `minmax(<length>,
     * ...)` -- e.g. a responsive `minmax(max(0.5px, calc(...)), auto)` row,
     * common in page-builder output that scales a section to the viewport),
     * the container's own auto height is thereby definite too, the same way
     * a real browser's grid track-sizing algorithm resolves it.
     *
     * An `aspect-ratio` box reaches a definite block size the same bottom-up
     * way: with `height` auto, the ratio derives the block size from the
     * inline size, so the box is exactly as definite as its own width is.
     */
    private function establishesOwnDefiniteBlockSize(DOMElement $element, string $height): bool
    {
        if ( ! $this->isAutoOrUnsetBlockSize($height) ) {
            return false;
        }
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        if ( $this->aspectRatioDerivesBlockSize($element, $declarations) ) {
            return true;
        }
        $display = strtolower($this->styleResolver->resolveStructuralCssVariablesInValue(CssValueInspector::withoutImportant((string) ($declarations['display'] ?? '')), $element));
        if ( ! in_array($display, array( 'grid', 'inline-grid' ), true) ) {
            return false;
        }
        $rows = $this->styleResolver->resolveStructuralCssVariablesInValue(CssValueInspector::withoutImportant((string) ($declarations['grid-template-rows'] ?? '')), $element);
        return $this->gridTemplateRowsContainDefiniteTrack($rows);
    }

    /**
     * Whether a declared `aspect-ratio` resolves this element's block size.
     * With `height` auto (the caller's precondition) a real ratio derives the
     * block axis from the inline one, so it is definite exactly when the
     * element's own `width` is: a length, or a percentage of the parent's
     * content width, which for the in-flow boxes this analysis walks is
     * itself resolved before the block axis is.
     *
     * An `auto` width is deliberately not accepted. It is definite only in
     * normal flow -- a float, an inline-level box, an absolutely positioned
     * box or a row flex item shrink-to-fit instead, sizing the inline axis
     * from content -- and this analysis does not model which of those the
     * element is in.
     *
     * @param array<string, string> $declarations
     */
    private function aspectRatioDerivesBlockSize(DOMElement $element, array $declarations): bool
    {
        $ratio = strtolower($this->styleResolver->resolveStructuralCssVariablesInValue(
            CssValueInspector::withoutImportant((string) ($declarations['aspect-ratio'] ?? '')),
            $element
        ));
        if ( '' === $ratio || 'auto' === $ratio || str_contains($ratio, 'auto') ) {
            return false;
        }
        if ( 1 !== preg_match('~^[\d.]+(?:\s*/\s*[\d.]+)?$~', trim($ratio)) ) {
            return false;
        }
        $width = $this->styleResolver->resolveStructuralCssVariablesInValue((string) ($declarations['width'] ?? ''), $element);

        return $this->isDefiniteBlockSize($width) || $this->isPercentageBlockSize($width);
    }

    private function gridTemplateRowsContainDefiniteTrack(string $rows): bool
    {
        foreach ( CssValueSplitter::splitTopLevelWhitespace(CssValueInspector::withoutImportant($rows)) as $track ) {
            if ( $this->gridRowTrackIsDefinite($track) ) {
                return true;
            }
        }
        return false;
    }

    private function gridRowTrackIsDefinite(string $track): bool
    {
        $track = trim($track);
        if ( 1 === preg_match('/^minmax\(\s*(.+)\)$/is', $track, $matches) ) {
            $parts = CssValueSplitter::splitTopLevel($matches[1], array( ',' ));
            return 2 === count($parts) && $this->isDefiniteBlockSize(trim($parts[0]));
        }
        if ( 1 === preg_match('/^repeat\(\s*(.+)\)$/i', $track, $matches) ) {
            $parts = CssValueSplitter::splitTopLevel($matches[1], array( ',' ));
            $list = $parts[1] ?? '';
            return '' !== $list && $this->gridTemplateRowsContainDefiniteTrack($list);
        }
        return $this->isDefiniteBlockSize($track);
    }

    private function gridTemplateRowsContainFractionalTrack(string $rows): bool
    {
        foreach ( CssValueSplitter::splitTopLevelWhitespace(CssValueInspector::withoutImportant($rows)) as $track ) {
            if ( $this->gridRowTrackIsFractional($track) ) {
                return true;
            }
        }
        return false;
    }

    private function gridRowTrackIsFractional(string $track): bool
    {
        $track = trim($track);
        if ( 1 === preg_match('/^[\d.]+fr$/i', $track) ) {
            return true;
        }
        if ( 1 !== preg_match('/^repeat\(\s*(.+)\)$/i', $track, $matches) ) {
            return false;
        }
        $parts = CssValueSplitter::splitTopLevel($matches[1], array( ',' ));
        $list = $parts[1] ?? '';
        return '' !== $list && $this->gridTemplateRowsContainFractionalTrack($list);
    }

    private function collapseFractionalGridRowTracks(string $rows): string
    {
        return implode(' ', array_map(
            fn (string $track): string => $this->collapseFractionalGridRowTrack($track),
            CssValueSplitter::splitTopLevelWhitespace($rows)
        ));
    }

    private function collapseFractionalGridRowTrack(string $track): string
    {
        $track = trim($track);
        if ( 1 === preg_match('/^[\d.]+fr$/i', $track) ) {
            return 'min-content';
        }
        if ( 1 !== preg_match('/^repeat\(\s*(.+)\)$/i', $track, $matches) ) {
            return $track;
        }
        $parts = CssValueSplitter::splitTopLevel($matches[1], array( ',' ));
        if ( 2 !== count($parts) ) {
            return $track;
        }
        return 'repeat(' . $parts[0] . ', ' . $this->collapseFractionalGridRowTracks($parts[1]) . ')';
    }

    private function isWideAbsoluteMinimumWidth(string $value): bool
    {
        $value = CssValueInspector::withoutImportant($value);
        if ( 1 !== preg_match('/^(\d+(?:\.\d+)?)\s*(px|r?em)$/i', $value, $matches) ) {
            return false;
        }
        $pixels = (float) $matches[1];
        if ( 'px' !== strtolower($matches[2]) ) {
            $pixels *= self::ROOT_FONT_SIZE_PX;
        }
        return $pixels >= 640;
    }

    /**
     * @param array<string, string> $declarations
     * @param list<string>          $conditions
     */
    private function projectAutoSizedStructuralPercentageHeight(
        string $prelude,
        string $body,
        array $declarations,
        AuthorStyleAnalysis $authorStyles,
        SourceStyleResolutionState $sourceStyles,
        TransformationEvidenceState $evidence,
        array $conditions = array()
    ): string {
        $height = (string) ($declarations['height'] ?? '');
        if ( '100%' !== strtolower(CssValueInspector::withoutImportant($height)) ) {
            return $body;
        }
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return $body;
        }
        $matchedSurface = false;
        foreach ( $selectors as $selector ) {
            $parsed = $sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                return $body;
            }
            $matches = $this->semanticPreparer->matchingSourceElements($authorStyles, $selector, $parsed);
            if ( array() === $matches ) {
                continue;
            }
            $matchedSurface = true;
            $autoSizedMatches = array_filter($matches, fn (DOMElement $element): bool => $this->isAutoSizedStructuralPercentageHeight($element, $authorStyles, $conditions));
            if ( count($autoSizedMatches) !== count($matches) ) {
                if ( array() !== $autoSizedMatches ) {
                    $evidence->recordResponsiveHeightAmbiguity($selector, $height);
                }
                return $body;
            }
        }
        if ( ! $matchedSurface ) {
            return $body;
        }
        $important = CssValueInspector::isImportant($height) ? '!important' : '';
        $retained = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            if ( 'height' !== strtolower(trim((string) strtok($declaration, ':'))) ) {
                $retained[] = $declaration;
            }
        }
        $retained[] = 'height:auto' . $important;
        return implode(';', $retained);
    }

    /** @param list<string> $conditions */
    private function isAutoSizedStructuralPercentageHeight(DOMElement $element, AuthorStyleAnalysis $authorStyles, array $conditions = array()): bool
    {
        if ( in_array(strtolower($element->tagName), array( 'canvas', 'embed', 'iframe', 'img', 'input', 'object', 'picture', 'svg', 'video' ), true) ) {
            return false;
        }
        if ( $this->isPositionedUnderConditions($element, $conditions) ) {
            return false;
        }
        if ( $this->receivesDefiniteBlockSize($element) || $this->percentageHeightFillsStretchedItem($element) ) {
            return false;
        }
        $ancestor = $element->parentNode;
        while ( $ancestor instanceof DOMElement && $ancestor !== $authorStyles->sourceBody() ) {
            $style = $this->styleResolver->structuralPresentationDeclarations($ancestor);
            if ( $this->isPositionedUnderConditions($ancestor, $conditions) ) {
                return false;
            }
            $ancestorHeight = strtolower(CssValueInspector::withoutImportant((string) ($style['height'] ?? '')));
            if ( ! in_array($ancestorHeight, array( '', 'auto', '100%' ), true) ) {
                return false;
            }
            if ( $this->hasDefiniteHeightUnderConditions($ancestor, $conditions) ) {
                return false;
            }
            if ( in_array(strtolower($ancestor->tagName), array( 'footer', 'header', 'section' ), true) ) {
                return in_array($ancestorHeight, array( '', 'auto' ), true);
            }
            $ancestor = $ancestor->parentNode;
        }
        return false;
    }

    /**
     * Whether `$element` has a definite height wherever a rule scoped by
     * `$conditions` applies.
     *
     * A responsive capture scopes its desktop stylesheet under
     * `@media (min-width:768px)`, so a wrapper's `height:42px` never reaches
     * the unconditional structural cascade. A percentage-height rule under that
     * same condition still resolves against the wrapper in the source. Only
     * declarations whose own condition stack is a subset of the rule's are
     * read: those hold everywhere the rule does. An inline height already
     * reached the structural cascade, and is left to it.
     *
     * @param list<string> $conditions
     */
    private function hasDefiniteHeightUnderConditions(DOMElement $element, array $conditions): bool
    {
        $held = self::restatableConditions($conditions);
        if ( array() === $held || isset($this->styleResolver->cssDeclarations(SourceDom::attr($element, 'style'))['height']) ) {
            return false;
        }
        $height = $this->presentationValueUnderConditions($element, 'height', $conditions);

        return $this->isDefiniteBlockSize($this->styleResolver->resolveStructuralCssVariablesInValue($height, $element));
    }

    /** @param list<string> $conditions */
    private function isPositionedUnderConditions(DOMElement $element, array $conditions): bool
    {
        $position = $this->presentationValueUnderConditions($element, 'position', $conditions);
        return in_array(CssValueInspector::comparable($position), array( 'absolute', 'fixed' ), true);
    }

    /**
     * Resolve only author declarations guaranteed by this rule's condition
     * domain, using the same ordered declaration set as definite-height proof.
     * Position is not inherited: a parent's declaration cannot position a child.
     * Inline declarations retain their ordinary cascade/importance priority.
     *
     * @param list<string> $conditions
     */
    private function presentationValueUnderConditions(DOMElement $element, string $property, array $conditions): string
    {
        $held = self::restatableConditions($conditions);
        $value = $this->styleResolver->declaredPresentation($element, $property)->resolvedValueWhere(
            static fn (array $stack): bool => array() === array_diff(self::restatableConditions($stack), $held)
        );
        $inline = $this->styleResolver->cssDeclarations(SourceDom::attr($element, 'style'));
        if ( isset($inline[$property]) && CssCascade::wins(
            array( 'important' => CssValueInspector::isImportant($inline[$property]), 'inline' => true, 'specificity' => 0, 'order' => 1 ),
            array( 'important' => CssValueInspector::isImportant($value), 'inline' => false, 'specificity' => 0, 'order' => 0 )
        ) ) {
            return $inline[$property];
        }
        return $value;
    }

    /**
     * Viewport and feature conditions, normalized for comparison. A cascade
     * `@layer` scopes a declaration without conditioning it.
     *
     * @param list<string> $conditions
     * @return list<string>
     */
    private static function restatableConditions(array $conditions): array
    {
        $normalized = array();
        foreach ( $conditions as $condition ) {
            $condition = strtolower((string) preg_replace('/\s+/', '', (string) $condition));
            if ( '' !== $condition && ! str_starts_with($condition, '@layer') ) {
                $normalized[] = $condition;
            }
        }

        return $normalized;
    }

    private function isPageShellOrSectionSurface(DOMElement $element, AuthorStyleAnalysis $authorStyles): bool
    {
        if ( $element->parentNode === $authorStyles->sourceBody() ) {
            return true;
        }
        if ( $element->getElementsByTagName('main')->length > 0 ) {
            return true;
        }
        if ( in_array(strtolower($element->tagName), array( 'header', 'main', 'footer', 'section' ), true) ) {
            return true;
        }
        $parent = $element->parentNode;
        return $parent instanceof DOMElement
            && $parent->parentNode === $authorStyles->sourceBody()
            && $this->elementChildCount($parent) > 1;
    }

    private function elementChildCount(DOMElement $element): int
    {
        $count = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                ++$count;
            }
        }
        return $count;
    }
}
