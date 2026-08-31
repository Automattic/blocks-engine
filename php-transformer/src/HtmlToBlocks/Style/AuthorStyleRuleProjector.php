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
        $body = $this->projectResponsiveCanvasMinimumWidth($prelude, $body, $authorStyles, $sourceStyles, $evidence);
        $body = $this->projectAutoSizedStructuralPercentageHeight($prelude, $body, $authorStyles, $sourceStyles, $evidence);
        $body = $this->projectSourceContentBoxSizing($prelude, $body, $authorStyles, $sourceStyles);
        return $this->projectIntrinsicGridRowTracks($prelude, $body, $authorStyles, $sourceStyles);
    }

    private function projectSourceContentBoxSizing(string $prelude, string $body, AuthorStyleAnalysis $authorStyles, SourceStyleResolutionState $sourceStyles): string
    {
        $declarations = $this->styleResolver->cssDeclarations($body);
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

    private function projectResponsiveCanvasMinimumWidth(
        string $prelude,
        string $body,
        AuthorStyleAnalysis $authorStyles,
        SourceStyleResolutionState $sourceStyles,
        TransformationEvidenceState $evidence
    ): string {
        $declarations = $this->styleResolver->cssDeclarations($body);
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

    private function projectIntrinsicGridRowTracks(string $prelude, string $body, AuthorStyleAnalysis $authorStyles, SourceStyleResolutionState $sourceStyles): string
    {
        $declarations = $this->styleResolver->cssDeclarations($body);
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
        return ! $this->isDefiniteBlockSize((string) ($declarations['height'] ?? ''))
            && ! $this->isDefiniteBlockSize((string) ($declarations['min-height'] ?? ''));
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

    private function projectAutoSizedStructuralPercentageHeight(
        string $prelude,
        string $body,
        AuthorStyleAnalysis $authorStyles,
        SourceStyleResolutionState $sourceStyles,
        TransformationEvidenceState $evidence
    ): string {
        $declarations = $this->styleResolver->cssDeclarations($body);
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
