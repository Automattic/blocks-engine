<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationEvidenceState;
use DOMElement;
use WeakMap;

/** Projects authored rule bodies whose source geometry changes under block wrappers. */
final class AuthorStyleRuleProjector
{
    private const ROOT_FONT_SIZE_PX = 16;

    /** Bounded ancestor walk for stretch-derived percentage-height resolution. */
    private const MAX_STRETCH_ANCESTOR_DEPTH = 12;

    /** @var WeakMap<AuthorStyleAnalysis, bool> */
    private WeakMap $universalBorderBoxResets;

    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly AuthorSelectorSemanticPreparer $semanticPreparer
    ) {
        $this->universalBorderBoxResets = new WeakMap();
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

    /** @return array{body: string, declarations: array<string, string>} */
    public function projectWithDeclarations(
        string $prelude,
        string $body,
        AuthorStyleAnalysis $authorStyles,
        SourceStyleResolutionState $sourceStyles,
        TransformationEvidenceState $evidence
    ): array {
        $declarations = $this->styleResolver->cssDeclarations($body);
        $this->acceptProjectedBody($body, $declarations, $this->projectResponsiveCanvasMinimumWidth($prelude, $body, $declarations, $authorStyles, $sourceStyles, $evidence));
        $this->acceptProjectedBody($body, $declarations, $this->projectAutoSizedStructuralPercentageHeight($prelude, $body, $declarations, $authorStyles, $sourceStyles, $evidence));
        $this->acceptProjectedBody($body, $declarations, $this->projectSourceContentBoxSizing($prelude, $body, $declarations, $authorStyles, $sourceStyles));
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
        $declarations = $this->styleResolver->cssDeclarations($body);
    }

    /** @param array<string, string> $declarations */
    private function projectSourceContentBoxSizing(string $prelude, string $body, array $declarations, AuthorStyleAnalysis $authorStyles, SourceStyleResolutionState $sourceStyles): string
    {
        if ( isset($declarations['box-sizing'])
            || ! isset($declarations['width'])
            || ! CssValueInspector::hasDefiniteWidth('width:' . $declarations['width'])
        ) {
            return $body;
        }

        $hasBoxChrome = false;
        foreach ( $declarations as $property => $value ) {
            $isBorderWidth = 1 === preg_match('/^border(?:-(?:top|right|bottom|left|block(?:-(?:start|end))?|inline(?:-(?:start|end))?))?(?:-width)?$/', $property);
            if ( ( 'padding' === $property || str_starts_with($property, 'padding-') || $isBorderWidth )
                && CssValueInspector::isNonZero($value)
            ) {
                $hasBoxChrome = true;
                break;
            }
        }
        if ( ! $hasBoxChrome ) {
            return $body;
        }

        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors || $this->authorStylesUseUniversalBorderBoxReset($authorStyles) ) {
            return $body;
        }
        $matched = false;
        foreach ( $selectors as $selector ) {
            $parsed = $sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                return $body;
            }
            foreach ( $this->semanticPreparer->matchingSourceElements($authorStyles, $selector, $parsed) as $element ) {
                $matched = true;
                if ( 'a' === strtolower($element->tagName)
                    || FormControlClassifier::isControlElement($element)
                    || 'button' === strtolower(trim($element->getAttribute('role')))
                ) {
                    return $body;
                }
                $resolved = CssValueInspector::comparable((string) ($this->styleResolver->structuralPresentationDeclarations($element)['box-sizing'] ?? ''));
                if ( ! in_array($resolved, array( '', 'content-box', 'initial', 'unset', 'revert', 'revert-layer' ), true) ) {
                    return $body;
                }
            }
        }
        if ( ! $matched ) {
            return $body;
        }
        return $body . ( str_ends_with(rtrim($body), ';') ? '' : ';' ) . 'box-sizing:content-box';
    }

    private function authorStylesUseUniversalBorderBoxReset(AuthorStyleAnalysis $authorStyles): bool
    {
        if ( isset($this->universalBorderBoxResets[$authorStyles]) ) {
            return $this->universalBorderBoxResets[$authorStyles];
        }

        $usesBorderBox = false;
        ( new CssStylesheetTransformer() )->visitStyleRules(
            $authorStyles->combinedCss(),
            function (string $prelude, string $body, array $ancestors) use (&$usesBorderBox): void {
                if ( $usesBorderBox || array() !== $ancestors ) {
                    return;
                }
                $boxSizing = CssValueInspector::comparable((string) ($this->styleResolver->cssDeclarations($body)['box-sizing'] ?? ''));
                if ( 'border-box' !== $boxSizing ) {
                    return;
                }
                foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                    if ( '*' === trim((string) preg_replace('/\/\*.*?\*\//s', '', $selector)) ) {
                        $usesBorderBox = true;
                        return;
                    }
                }
            }
        );
        return $this->universalBorderBoxResets[$authorStyles] = $usesBorderBox;
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
            $matches = $this->semanticPreparer->matchingSourceElements($authorStyles, $selector, $parsed);
            if ( array() === $matches ) {
                continue;
            }
            $matchedSurface = true;
            foreach ( $matches as $element ) {
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
            // its children along the block axis via flex-basis/grow instead,
            // so it is not a stretch-derived size source for block height.
            return false;
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
     */
    private function establishesOwnDefiniteBlockSize(DOMElement $element, string $height): bool
    {
        if ( ! $this->isAutoOrUnsetBlockSize($height) ) {
            return false;
        }
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        $display = strtolower($this->styleResolver->resolveStructuralCssVariablesInValue(CssValueInspector::withoutImportant((string) ($declarations['display'] ?? '')), $element));
        if ( ! in_array($display, array( 'grid', 'inline-grid' ), true) ) {
            return false;
        }
        $rows = $this->styleResolver->resolveStructuralCssVariablesInValue(CssValueInspector::withoutImportant((string) ($declarations['grid-template-rows'] ?? '')), $element);
        return $this->gridTemplateRowsContainDefiniteTrack($rows);
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

    /** @param array<string, string> $declarations */
    private function projectAutoSizedStructuralPercentageHeight(
        string $prelude,
        string $body,
        array $declarations,
        AuthorStyleAnalysis $authorStyles,
        SourceStyleResolutionState $sourceStyles,
        TransformationEvidenceState $evidence
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
            $autoSizedMatches = array_filter($matches, fn (DOMElement $element): bool => $this->isAutoSizedStructuralPercentageHeight($element, $authorStyles));
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

    private function isAutoSizedStructuralPercentageHeight(DOMElement $element, AuthorStyleAnalysis $authorStyles): bool
    {
        if ( in_array(strtolower($element->tagName), array( 'canvas', 'embed', 'iframe', 'img', 'input', 'object', 'picture', 'svg', 'video' ), true) ) {
            return false;
        }
        $elementStyle = $this->styleResolver->structuralPresentationDeclarations($element);
        if ( in_array(strtolower(CssValueInspector::withoutImportant((string) ($elementStyle['position'] ?? ''))), array( 'absolute', 'fixed' ), true) ) {
            return false;
        }
        $ancestor = $element->parentNode;
        while ( $ancestor instanceof DOMElement && $ancestor !== $authorStyles->sourceBody() ) {
            $style = $this->styleResolver->structuralPresentationDeclarations($ancestor);
            if ( in_array(strtolower(CssValueInspector::withoutImportant((string) ($style['position'] ?? ''))), array( 'absolute', 'fixed' ), true) ) {
                return false;
            }
            $ancestorHeight = strtolower(CssValueInspector::withoutImportant((string) ($style['height'] ?? '')));
            if ( ! in_array($ancestorHeight, array( '', 'auto', '100%' ), true) ) {
                return false;
            }
            if ( in_array(strtolower($ancestor->tagName), array( 'footer', 'header', 'section' ), true) ) {
                return in_array($ancestorHeight, array( '', 'auto' ), true);
            }
            $ancestor = $ancestor->parentNode;
        }
        return false;
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
