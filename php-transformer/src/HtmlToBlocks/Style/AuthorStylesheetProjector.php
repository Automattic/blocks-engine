<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ShellLandmarkPolicy;
use DOMElement;

/** Projects authored selectors and declarations onto canonical block markup. */
final class AuthorStylesheetProjector
{
    public const INLINE_LAYOUT_CARRIER_CLASS = 'blocks-engine-inline-layout-carrier';

    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly AuthorSelectorSemanticPreparer $semanticPreparer,
        private readonly AuthorStyleRuleProjector $ruleBodyProjector
    ) {}

    public function project(string $stylesheet, AuthorStylesheetProjectionContext $context): string
    {
        return ( new CssStylesheetTransformer() )->transformStyleRules(
            $stylesheet,
            function (string $prelude, string $body) use ($context): string {
                $projection = $this->ruleBodyProjector->projectWithDeclarations(
                    $prelude,
                    $body,
                    $context->authorStyles,
                    $context->sourceStyles,
                    $context->evidence
                );
                $body = $projection['body'];
                $declarations = $projection['declarations'];
                $margins = array_filter($declarations, static fn (string $name): bool => 'margin' === $name || str_starts_with($name, 'margin-'), ARRAY_FILTER_USE_KEY);
                $imagePrelude = $this->projectAuthorImageSelectorPrelude($prelude, $context);
                $svgImagePrelude = $this->projectAuthorImageSelectorPrelude($prelude, $context, 'svg', $declarations);
                $imageRule = '' === $imagePrelude
                    ? ''
                    : $imagePrelude . '{' . $this->imageProjectionBridgeDeclarations($declarations) . '}';
                $svgImageRule = '' === $svgImagePrelude
                    ? ''
                    : $svgImagePrelude . '{' . $this->imageProjectionBridgeDeclarations($declarations, true) . '}';
                $editorDocumentRootRule = $this->editorDocumentRootRule($prelude, $body);
                if ( array() === $margins ) {
                    $css = $this->rewriteStyleRule($prelude, $body, $context) . $imageRule . $svgImageRule . $editorDocumentRootRule;
                    return $css . $this->editorPositionRules($css);
                }

                $inner = array_diff_key($declarations, $margins);
                $rules = '' === $this->styleResolver->cssDeclarationString($inner)
                    ? ''
                    : $this->rewriteStyleRule($prelude, $this->styleResolver->cssDeclarationString($inner), $context);
                $css = $rules
                    . $this->marginSelectorPrelude($prelude, $context) . '{' . $this->styleResolver->cssDeclarationString($margins) . '}'
                    . $imageRule
                    . $svgImageRule
                    . $editorDocumentRootRule;
                return $css . $this->editorPositionRules($css);
            }
        );
    }

    private function editorPositionRules(string $css): string
    {
        return ( new CssStylesheetTransformer() )->transformStyleRules(
            $css,
            function (string $prelude, string $body): string {
                $position = trim((string) ($this->styleResolver->cssDeclarations($body)['position'] ?? ''));
                $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                if ( '' === $position || null === $selectors ) {
                    return '';
                }
                $editorSelectors = array();
                foreach ( $selectors as $selector ) {
                    $selector = trim($selector);
                    if ( '' === $selector || str_starts_with($selector, ':host') || str_contains($selector, '.editor-styles-wrapper') ) {
                        continue;
                    }
                    if ( 1 === preg_match('/^body(?=$|[.#:\[])/', $selector) ) {
                        $editorSelectors[] = preg_replace('/^body/', ':root body.editor-styles-wrapper', $selector, 1) ?? $selector;
                        continue;
                    }
                    if ( 1 === preg_match('/^:root(?=$|[.#:\[])/', $selector) ) {
                        $editorSelectors[] = preg_replace('/^:root/', ':root .editor-styles-wrapper', $selector, 1) ?? $selector;
                        continue;
                    }
                    $editorSelectors[] = ':root .editor-styles-wrapper ' . $selector;
                }
                return array() === $editorSelectors
                    ? ''
                    : implode(',', $editorSelectors) . '{position:' . $position . '}';
            }
        );
    }

    private function marginSelectorPrelude(string $prelude, AuthorStylesheetProjectionContext $context): string
    {
        $projected = $this->rewriteSelectorPrelude($prelude, $context, true);
        $selectors = CssStylesheetTransformer::splitSelectorList($projected);
        if ( null === $selectors ) {
            return $projected;
        }

        // Linked author CSS precedes Gutenberg's inline flow resets, so authored margins cannot rely on source order.
        $shim = ':not(.' . $context->authorStyles->classSpecificityShim() . ')';
        return implode(',', array_map(static function (string $selector) use ($shim): string {
            $selector = trim($selector);
            if ( 1 !== preg_match('/::[A-Za-z_-][A-Za-z0-9_-]*(?:\([^)]*\))?$/', $selector) ) {
                return $selector . $shim;
            }
            return preg_replace('/(::[A-Za-z_-][A-Za-z0-9_-]*(?:\([^)]*\))?)$/', $shim . '$1', $selector, 1) ?? $selector;
        }, $selectors));
    }

    private function rewriteStyleRule(string $prelude, string $body, AuthorStylesheetProjectionContext $context): string
    {
        $projectedPrelude = $this->rewriteSelectorPrelude($prelude, $context);
        $body = $this->buttonLinkCompatDeclarations($prelude, $projectedPrelude, $body, $context);
        $wrapperPrelude = $this->buttonPresentationWrapperPrelude($prelude, $context);
        if ( '' === $wrapperPrelude ) {
            $directWrapperPrelude = $this->directButtonGeometryWrapperPrelude($prelude, $context);
            if ( '' === $directWrapperPrelude ) {
                $mixedButtonProjection = $this->withoutCollapsedButtonProjectedWidths($projectedPrelude, $body);
                return null !== $mixedButtonProjection ? $mixedButtonProjection : $projectedPrelude . '{' . $body . '}';
            }
            [ $geometry, $inner ] = $this->splitDirectButtonGeometryDeclarations($body);
            if ( '' === $geometry ) {
                return '' === $inner ? '' : $projectedPrelude . '{' . $inner . '}';
            }
            return $this->withButtonWrapperInnerFill($directWrapperPrelude, $geometry, '' === $inner ? '' : $projectedPrelude . '{' . $inner . '}');
        }

        [ $layout, $control ] = $this->splitButtonPresentationDeclarations($body);
        if ( '' === $layout ) {
            return '' === $control ? '' : $projectedPrelude . '{' . $control . '}';
        }
        if ( '' === $control ) {
            return $this->withButtonWrapperInnerFill($wrapperPrelude, $layout);
        }
        return $this->withButtonWrapperInnerFill($wrapperPrelude, $layout, $projectedPrelude . '{' . $control . '}');
    }

    /**
     * core/button defaults and support styles are emitted after carried author CSS.
     * Keep source button declarations authoritative after their selector is lowered
     * to a generated marker, including media-query overrides.
     */
    private function buttonLinkCompatDeclarations(string $prelude, string $projectedPrelude, string $body, AuthorStylesheetProjectionContext $context): string
    {
        if ( ! str_contains($projectedPrelude, '.wp-block-button__link') || ! $this->projectsAnchorButtonControl($prelude, $context) ) {
            return $body;
        }

        $declarations = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            if ( false === $colon ) {
                $declarations[] = $declaration;
                continue;
            }
            $name = trim(substr($declaration, 0, $colon));
            $value = trim(substr($declaration, $colon + 1));
            if ( '' === $name || '' === $value || ! $this->isButtonLinkLayoutProperty($name) || preg_match('/\s*!important\s*$/i', $value) ) {
                $declarations[] = $declaration;
                continue;
            }
            $declarations[] = $name . ':' . $value . '!important';
        }
        return implode(';', $declarations);
    }

    private function projectsAnchorButtonControl(string $prelude, AuthorStylesheetProjectionContext $context): bool
    {
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                continue;
            }
            foreach ( $this->matchingSourceElements($selector, $parsed, $context) as $element ) {
                if ( 'a' === strtolower($element->tagName)
                    && '' !== $context->selectorProjections->controlMarker($element->getNodePath() ?? '') ) {
                    return true;
                }
            }
        }
        return false;
    }

    private function isButtonLinkLayoutProperty(string $property): bool
    {
        return 'display' === $property
            || 'gap' === $property
            || str_starts_with($property, 'flex-')
            || str_starts_with($property, 'align-')
            || str_starts_with($property, 'justify-')
            || 'width' === $property
            || 'height' === $property
            || str_starts_with($property, 'min-')
            || str_starts_with($property, 'max-')
            || 'padding' === $property
            || str_starts_with($property, 'padding-');
    }

    private function withoutCollapsedButtonProjectedWidths(string $prelude, string $body): ?string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return null;
        }
        $buttonSelectors = array();
        $otherSelectors = array();
        foreach ( $selectors as $selector ) {
            if ( str_contains($selector, '> :where(.wp-block-button__link)') ) {
                $buttonSelectors[] = $selector;
            } else {
                $otherSelectors[] = $selector;
            }
        }
        if ( array() === $buttonSelectors ) {
            return null;
        }
        $safe = array();
        $collapsed = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            $name = strtolower(trim(false === $colon ? $declaration : substr($declaration, 0, $colon)));
            $value = false === $colon ? '' : trim(substr($declaration, $colon + 1));
            if ( false !== $colon && $this->isCollapsedButtonKeywordWidth($name, $value) ) {
                $collapsed[] = $declaration;
            } else {
                $safe[] = $declaration;
            }
        }
        if ( array() === $collapsed ) {
            return null;
        }
        $css = array() === $safe ? '' : $prelude . '{' . implode(';', $safe) . '}';
        if ( array() !== $otherSelectors ) {
            $css .= implode(',', $otherSelectors) . '{' . implode(';', $collapsed) . '}';
        }
        return $css;
    }

    private function buttonPresentationWrapperPrelude(string $prelude, AuthorStylesheetProjectionContext $context): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return '';
        }
        $rewritten = array();
        foreach ( $selectors as $selector ) {
            $selector = $this->projectSourceBodyStateSelector($selector, $context);
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                array_push($rewritten, ...$this->projectUnsupportedFunctionalControlSelector($selector, $context, true));
                continue;
            }
            if ( null !== $parsed['pseudo_state_suffix_span'] ) {
                continue;
            }
            $matches = $this->matchingSourceElements($selector, $parsed, $context);
            if ( array() === $matches ) {
                continue;
            }
            $markers = array();
            foreach ( $matches as $element ) {
                $path = $element->getNodePath() ?? '';
                $marker = $context->selectorProjections->isButtonPresentationPath($path)
                    ? $context->selectorProjections->controlMarker($path)
                    : '';
                if ( '' === $marker ) {
                    continue 2;
                }
                $markers[] = $marker;
            }
            foreach ( array_unique($markers) as $marker ) {
                $rewritten[] = ':where(.' . $marker . ')' . $this->selectorSpecificityShims($parsed, $context);
            }
        }
        return implode(',', $rewritten);
    }

    private function directButtonGeometryWrapperPrelude(string $prelude, AuthorStylesheetProjectionContext $context): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return '';
        }
        $rewritten = array();
        foreach ( $selectors as $selector ) {
            $selector = $this->projectSourceBodyStateSelector($selector, $context);
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] || null !== $parsed['pseudo_state_suffix_span'] ) {
                continue;
            }
            $matches = $this->matchingSourceElements($selector, $parsed, $context);
            if ( array() === $matches ) {
                continue;
            }
            foreach ( $matches as $element ) {
                $path = $element->getNodePath() ?? '';
                $marker = $context->selectorProjections->controlMarker($path);
                if ( '' === $marker || $context->selectorProjections->isButtonPresentationPath($path) ) {
                    continue 2;
                }
                $rewritten[] = $this->projectControlSelector($selector, $parsed, $marker, $context, true);
            }
        }
        return implode(',', array_values(array_unique($rewritten)));
    }

    /** @return array{string, string} */
    private function splitDirectButtonGeometryDeclarations(string $body): array
    {
        $geometry = array();
        $inner = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            $name = strtolower(trim(false === $colon ? $declaration : substr($declaration, 0, $colon)));
            $value = false === $colon ? '' : trim(substr($declaration, $colon + 1));
            $wrapperOwned = array(
                'position', 'top', 'right', 'bottom', 'left', 'z-index',
                'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
                'grid-area', 'grid-column', 'grid-row',
                'grid-column-start', 'grid-column-end', 'grid-row-start', 'grid-row-end',
                'align-self', 'justify-self', 'order',
            );
            if ( $this->isCollapsedButtonKeywordWidth($name, $value) ) {
                continue;
            }
            if ( '' !== $name && false !== $colon && in_array($name, $wrapperOwned, true)
                && ! $this->isButtonControlBoxSize($name, $value)
            ) {
                $geometry[] = $declaration;
            } else {
                $inner[] = $declaration;
            }
        }
        return array( implode(';', $geometry), implode(';', $inner) );
    }

    private function projectSourceBodyStateSelector(string $selector, AuthorStylesheetProjectionContext $context): string
    {
        $classes = $context->authorStyles->sourceBodyProjectionClasses();
        if ( array() === $classes ) {
            return $selector;
        }
        $classes = implode('|', array_map(static fn (string $class): string => preg_quote($class, '/'), $classes));
        return preg_replace('/^\s*body(?=\.(?:' . $classes . ')(?:\b|[.#:\[]))/', '', $selector, 1) ?? $selector;
    }

    /** @return array{string, string} */
    private function splitButtonPresentationDeclarations(string $body): array
    {
        $layout = array();
        $control = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            $name = strtolower(trim(false === $colon ? $declaration : substr($declaration, 0, $colon)));
            $value = false === $colon ? '' : trim(substr($declaration, $colon + 1));
            if ( '' === $name || false === $colon ) {
                $control[] = $declaration;
                continue;
            }
            if ( $this->isCollapsedButtonKeywordWidth($name, $value) ) {
                continue;
            }
            if ( $this->isButtonWrapperLayoutProperty($name) && ! $this->isButtonControlBoxSize($name, $value) ) {
                $layout[] = $declaration;
            } else {
                $control[] = $declaration;
            }
        }
        return array( implode(';', $layout), implode(';', $control) );
    }

    private function withButtonWrapperInnerFill(string $wrapperPrelude, string $layoutCss, string $rest = ''): string
    {
        $css = $wrapperPrelude . '{' . $layoutCss . '}';
        if ( CssValueInspector::hasDefiniteWidth($layoutCss) ) {
            $selectors = CssStylesheetTransformer::splitSelectorList($wrapperPrelude) ?? array( $wrapperPrelude );
            $button = implode(',', array_map(static fn (string $selector): string => rtrim($selector) . '> :where(.wp-block-button)', $selectors));
            $link = implode(',', array_map(static fn (string $selector): string => rtrim($selector) . '> :where(.wp-block-button)> :where(.wp-block-button__link)', $selectors));
            $css .= $button . '{width:100%!important}'
                . $link . '{width:100%!important;max-width:100%!important}';
        }
        return $css . $rest;
    }

    private function isButtonControlBoxSize(string $property, string $value): bool
    {
        if ( ! in_array($property, array( 'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height' ), true) ) {
            return false;
        }
        $value = strtolower(CssValueInspector::withoutImportant($value));
        return in_array($value, array( 'min-content', 'max-content', 'fit-content', 'content' ), true);
    }

    private function isCollapsedButtonKeywordWidth(string $property, string $value): bool
    {
        $value = strtolower(CssValueInspector::withoutImportant($value));
        if ( 'min-content' !== $value ) {
            return false;
        }
        return in_array($property, array( 'width', 'min-width', 'max-width' ), true)
            || (str_starts_with($property, '--') && str_contains($property, 'width'));
    }

    private function isButtonWrapperLayoutProperty(string $property): bool
    {
        return in_array($property, array(
            'align-content', 'align-items', 'align-self', 'clear', 'display', 'float',
            'flex', 'flex-basis', 'flex-direction', 'flex-flow', 'flex-grow', 'flex-shrink',
            'flex-wrap', 'gap', 'grid', 'grid-area', 'grid-auto-columns', 'grid-auto-flow',
            'grid-auto-rows', 'grid-column', 'grid-row', 'grid-template', 'grid-template-areas',
            'grid-template-columns', 'grid-template-rows', 'isolation', 'justify-content',
            'justify-items', 'justify-self', 'order', 'overflow', 'overflow-x', 'overflow-y',
            'place-content', 'place-items', 'place-self', 'position', 'top', 'right', 'bottom',
            'left', 'z-index', 'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
        ), true);
    }

    private function rewriteSelectorPrelude(string $prelude, AuthorStylesheetProjectionContext $context, bool $controlWrapper = false): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return $prelude;
        }
        $rewritten = array();
        foreach ( $selectors as $selector ) {
            $runtimeProjection = $this->projectRuntimeAttributeSelector($selector, $context);
            if ( null !== $runtimeProjection ) {
                array_push($rewritten, ...$runtimeProjection);
                continue;
            }
            $selector = $this->projectSourceAttributeNegationStateSelector($selector, $context);
            $selector = $this->projectSourceBodyStateSelector($selector, $context);
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                $projectedControls = $this->projectUnsupportedFunctionalControlSelector($selector, $context, $controlWrapper);
                if ( array() === $projectedControls ) {
                    $rewritten[] = $selector;
                } else {
                    array_push($rewritten, ...$projectedControls);
                }
                continue;
            }
            $matches = $this->matchingSourceElements($selector, $parsed, $context);
            if ( array() === $matches ) {
                $dormantControls = $this->projectDormantAncestorControlSelector($selector, $parsed, $context, $controlWrapper);
                if ( array() !== $dormantControls ) {
                    array_push($rewritten, ...$dormantControls);
                    continue;
                }
                $rewritten[] = $this->rewriteSourceTagTypes($selector, $parsed, $context);
                continue;
            }
            $attributeAncestryProjection = $this->projectSourceAttributeAncestrySelector($selector, $parsed, $matches, $context);
            if ( null !== $attributeAncestryProjection ) {
                array_push($rewritten, ...$attributeAncestryProjection);
                continue;
            }
            $attributeProjection = $this->projectSourceAttributeSelector($parsed, $matches, $context);
            if ( null !== $attributeProjection ) {
                array_push($rewritten, ...$attributeProjection);
                continue;
            }
            if ( AuthorSelectorSemanticPreparer::isRootChildSelector($parsed) ) {
                $shellTags = array_values(array_unique(array_filter(array_map(
                    static function (DOMElement $element) use ($context): string {
                        if ( $element->parentNode !== $context->authorStyles->sourceBody() ) {
                            return '';
                        }
                        $tag = strtolower($element->tagName);
                        $area = ShellLandmarkPolicy::landmarkKind($tag, $element->getAttribute('role'));
                        return in_array($area, array( 'header', 'footer' ), true) ? $tag : '';
                    },
                    $matches
                ))));
                $markers = array_values(array_unique(array_filter(array_map(
                    static function (DOMElement $element) use ($shellTags, $context): string {
                        return in_array(strtolower($element->tagName), $shellTags, true)
                            ? ''
                            : $context->selectorProjections->rootChildMarker($element->getNodePath() ?? '');
                    },
                    $matches
                ))));
                if ( array() === $markers && array() === $shellTags ) {
                    $rewritten[] = $selector;
                    continue;
                }
                foreach ( $markers as $marker ) {
                    $rewritten[] = $this->projectSemanticLeafSelector($selector, $parsed, $marker, $context);
                }
                foreach ( $shellTags as $tag ) {
                    $rewritten[] = ':where(' . $tag . '.wp-block-template-part)' . $this->selectorSpecificityShims($parsed, $context);
                }
                continue;
            }

            $tableDescendants = array();
            $nonTableMatches = array();
            foreach ( $matches as $element ) {
                $projected = $this->projectTableDescendantSelector($selector, $parsed, $element, $context);
                if ( null === $projected ) {
                    $nonTableMatches[] = $element;
                } else {
                    $tableDescendants[] = $projected;
                }
            }
            foreach ( array_values(array_unique($tableDescendants)) as $projected ) {
                $rewritten[] = $projected;
            }
            if ( array() === $nonTableMatches ) {
                continue;
            }
            $matches = $nonTableMatches;

            $controls = array();
            $semanticLeaves = array();
            $richTextLeaves = array();
            $inlineLayoutCarriers = false;
            $hasNonProjected = false;
            foreach ( $matches as $element ) {
                $path = $element->getNodePath() ?? '';
                if ( $context->selectorProjections->isInlineLayoutCarrierPath($path) ) {
                    $inlineLayoutCarriers = true;
                } elseif ( '' !== ($marker = $context->selectorProjections->controlMarker($path)) ) {
                    $controls[] = $marker;
                } elseif ( '' !== ($marker = $context->selectorProjections->semanticMarker($path)) ) {
                    $semanticLeaves[] = $marker;
                } elseif ( '' !== ($marker = $context->selectorProjections->richTextMarker($path)) ) {
                    $richTextLeaves[] = $marker;
                } else {
                    $hasNonProjected = true;
                }
            }
            $controls = array_values(array_unique($controls));
            $semanticLeaves = array_values(array_unique($semanticLeaves));
            $richTextLeaves = array_values(array_unique($richTextLeaves));
            if ( array() === $controls && array() === $semanticLeaves && array() === $richTextLeaves && ! $inlineLayoutCarriers ) {
                $rewritten[] = $this->rewriteSourceTagTypes($selector, $parsed, $context);
                continue;
            }
            $projectedMarkers = array_merge($controls, $semanticLeaves, $richTextLeaves);
            if ( $hasNonProjected ) {
                $rewritten[] = $this->rewriteSourceTagTypes($selector, $parsed, $context, ':not(:where(.' . implode(',.', $projectedMarkers) . '))');
            }
            foreach ( $controls as $marker ) {
                $rewritten[] = $this->projectControlSelector($selector, $parsed, $marker, $context, $controlWrapper);
            }
            foreach ( $semanticLeaves as $marker ) {
                $rewritten[] = $this->projectSemanticLeafSelector($selector, $parsed, $marker, $context);
            }
            foreach ( $richTextLeaves as $marker ) {
                $rewritten[] = $this->projectRichTextSemanticSelector($selector, $parsed, $marker, $context);
            }
            if ( $inlineLayoutCarriers ) {
                $rewritten[] = $this->projectInlineLayoutCarrierSelector($selector, $parsed);
            }
        }
        return implode(',', $rewritten);
    }

    /** @param array<string, mixed> $parsed @return list<string> */
    private function projectDormantAncestorControlSelector(
        string $selector,
        array $parsed,
        AuthorStylesheetProjectionContext $context,
        bool $wrapper
    ): array {
        $rightmost = $parsed['rightmost_compound_span'] ?? null;
        if ( ! is_array($rightmost) || 0 === (int) $rightmost['start'] ) {
            return array();
        }

        $leafSelector = substr($selector, (int) $rightmost['start']);
        $leafParsed = $context->sourceStyles->parsedSelector($leafSelector);
        if ( ! $leafParsed['supported'] ) {
            return array();
        }

        $projected = array();
        foreach ( $this->matchingSourceElements($leafSelector, $leafParsed, $context) as $element ) {
            $marker = $context->selectorProjections->controlMarker($element->getNodePath() ?? '');
            if ( '' !== $marker ) {
                $projected[] = substr($selector, 0, (int) $rightmost['start'])
                    . $this->projectControlSelector($leafSelector, $leafParsed, $marker, $context, $wrapper);
            }
        }
        return array_values(array_unique($projected));
    }

    /** @return list<string> */
    private function projectUnsupportedFunctionalControlSelector(
        string $selector,
        AuthorStylesheetProjectionContext $context,
        bool $wrapper
    ): array {
        if ( 1 !== preg_match('/:(?:is|where)\s*\(/i', $selector) ) {
            return array();
        }

        $rewritten = array();
        foreach ( $context->authorStyles->sourceBody()->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || ! in_array(strtolower($element->tagName), array( 'a', 'button' ), true) ) {
                continue;
            }
            $marker = $context->selectorProjections->controlMarker($element->getNodePath() ?? '');
            if ( '' === $marker ) {
                continue;
            }

            $pattern = '';
            $specificityShim = '';
            $id = trim($element->getAttribute('id'));
            if ( '' !== $id && 1 === preg_match('/#' . preg_quote($id, '/') . '(?![\w-])/', $selector) ) {
                $pattern = '/#' . preg_quote($id, '/') . '(?![\w-])/';
                $specificityShim = ':not(#' . $context->authorStyles->idSpecificityShim() . ')';
            } else {
                foreach ( preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array() as $className ) {
                    if ( '' !== $className && 1 === preg_match('/\.' . preg_quote($className, '/') . '(?![\w-])/', $selector) ) {
                        $pattern = '/\.' . preg_quote($className, '/') . '(?![\w-])/';
                        $specificityShim = ':not(.' . $context->authorStyles->classSpecificityShim() . ')';
                        break;
                    }
                }
            }
            if ( '' === $pattern ) {
                continue;
            }

            $target = ':where(.' . $marker . ($wrapper ? '.wp-block-buttons)' : ')> :where(.wp-block-button__link)') . $specificityShim;
            $projected = preg_replace($pattern, $target, $selector, 1);
            if ( is_string($projected) && '' !== $projected ) {
                $rewritten[] = $projected;
            }
        }
        return array_values(array_unique($rewritten));
    }

    private function editorDocumentRootRule(string $prelude, string $body): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors || ! in_array('body', array_map(static fn (string $selector): string => strtolower(trim($selector)), $selectors), true) ) {
            return '';
        }

        // Gutenberg establishes an explicit canvas presentation context, so
        // document-level body inheritance cannot otherwise win in the editor.
        $inheritedProperties = array(
            'color', 'direction', 'hyphens', 'letter-spacing', 'line-height',
            'tab-size', 'text-align', 'text-indent', 'text-shadow', 'text-transform',
            'visibility', 'white-space', 'word-spacing', 'writing-mode',
        );
        $declarations = array_filter(
            $this->styleResolver->cssDeclarations($body),
            static fn (string $name): bool => str_starts_with($name, '--')
                || 'font' === $name
                || str_starts_with($name, 'font-')
                || in_array($name, $inheritedProperties, true),
            ARRAY_FILTER_USE_KEY
        );
        $css = $this->styleResolver->cssDeclarationString($declarations);
        return '' === $css ? '' : ':root .editor-styles-wrapper{' . $css . '}';
    }

    /** @return list<string>|null */
    private function projectRuntimeAttributeSelector(string $selector, AuthorStylesheetProjectionContext $context): ?array
    {
        $selector = trim($selector);
        $selectorMarkers = $context->selectorProjections->runtimeAttributeSelectorMarkers();
        uksort($selectorMarkers, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ( $selectorMarkers as $runtimeSelector => $markers ) {
            if ( ! str_starts_with($selector, $runtimeSelector) ) {
                continue;
            }
            $suffix = substr($selector, strlen($runtimeSelector));
            if ( '' !== $suffix && ! in_array($suffix[0], array('.', ':'), true) ) {
                continue;
            }
            $parsed = $context->sourceStyles->parsedSelector($runtimeSelector);
            if ( ! $parsed['supported'] ) {
                continue;
            }
            $shims = $this->selectorSpecificityShims($parsed, $context);
            return array_map(static fn (string $marker): string => ':where(.' . $marker . ')' . $shims . $suffix, $markers);
        }
        return null;
    }

    /** @param array<string, mixed> $parsed @param list<DOMElement> $matches @return list<string>|null */
    private function projectSourceAttributeAncestrySelector(string $selector, array $parsed, array $matches, AuthorStylesheetProjectionContext $context): ?array
    {
        $rightmost = $parsed['rightmost_compound_span'] ?? null;
        $ancestry = is_array($rightmost) ? substr($selector, 0, (int) $rightmost['start']) : '';
        if ( null !== $parsed['pseudo_state_suffix_span'] || ! preg_match('/\[\s*data-[a-z0-9_-]+(?:\s*[~|^$*]?=|\s*\])/i', $ancestry) ) {
            return null;
        }
        $projected = array();
        $scope = '';
        if ( preg_match('/^\s*((?::where\([^(),]+\)\s+)+)/i', $selector, $scopeMatch) ) {
            $scope = trim((string) $scopeMatch[1]) . ' ';
        }
        foreach ( $matches as $element ) {
            $id = trim($element->getAttribute('id'));
            if ( preg_match('/^[a-z_][a-z0-9_-]*$/i', $id) ) {
                $target = '#' . $id;
            } else {
                $marker = $context->selectorProjections->attributeMarker($element->getNodePath() ?? '');
                if ( '' === $marker ) {
                    return null;
                }
                $target = '.' . $marker;
            }
            $projected[] = $scope . ':where(' . $target . ')' . $this->selectorSpecificityShims($parsed, $context);
        }
        return array_values(array_unique($projected));
    }

    /** @param array<string, mixed> $parsed @param list<DOMElement> $matches @return list<string>|null */
    private function projectSourceAttributeSelector(array $parsed, array $matches, AuthorStylesheetProjectionContext $context): ?array
    {
        if ( null !== $parsed['pseudo_state_suffix_span'] ) {
            return null;
        }
        $compounds = $parsed['compounds'] ?? array();
        $rightmost = $compounds[array_key_last($compounds)] ?? array();
        $hasDataAttribute = array_filter($rightmost['attributes'] ?? array(), static fn (array $attribute): bool => str_starts_with($attribute['name'] ?? '', 'data-'));
        if ( array() === $hasDataAttribute ) {
            return null;
        }
        $projected = array();
        foreach ( $matches as $element ) {
            $marker = $context->selectorProjections->attributeMarker($element->getNodePath() ?? '');
            if ( '' === $marker ) {
                return null;
            }
            $projected[] = ':where(.' . $marker . ')' . $this->selectorSpecificityShims($parsed, $context);
        }
        return array_values(array_unique($projected));
    }

    private function projectSourceAttributeNegationStateSelector(string $selector, AuthorStylesheetProjectionContext $context): string
    {
        $marker = $context->selectorProjections->attributeNegationMarker(trim($selector));
        if ( '' === $marker ) {
            return $selector;
        }
        return preg_replace(
            '/:not\(\s*\[\s*data-[a-z0-9_-]+(?:\s*[~|^$*]?=\s*(?:"[^"]*"|\'[^\']*\'|[^\]\s]+))?\s*\]\s*\)/i',
            ':not(.' . $marker . ')',
            $selector
        ) ?? $selector;
    }

    /** @param array<string, string> $declarations */
    private function projectAuthorImageSelectorPrelude(string $prelude, AuthorStylesheetProjectionContext $context, string $tagName = 'img', array $declarations = array()): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return '';
        }
        $projected = array();
        foreach ( $selectors as $selector ) {
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                continue;
            }
            $matches = $this->matchingSourceElements($selector, $parsed, $context);
            $imageMatches = array_values(array_filter($matches, fn (DOMElement $element): bool => $tagName === strtolower($element->tagName) && ('svg' !== $tagName || $this->isProjectableFillSvg($element, $declarations))));
            if ( array() === $imageMatches ) {
                continue;
            }
            if ( AuthorSelectorSemanticPreparer::isRootChildSelector($parsed) ) {
                foreach ( $imageMatches as $element ) {
                    $marker = $context->selectorProjections->rootChildMarker($element->getNodePath() ?? '');
                    if ( '' !== $marker ) {
                        $projected[] = $this->projectSemanticLeafSelector($selector, $parsed, $marker, $context) . '.wp-block-image > img';
                    }
                }
                continue;
            }
            if ( 'svg' === $tagName ) {
                $projected[] = $this->projectImageSelector($selector, $parsed, $context, true);
            }
            $projected[] = $this->projectImageSelector($selector, $parsed, $context);
        }
        return implode(',', array_values(array_unique($projected)));
    }

    /** @param array<string, string> $declarations */
    private function isExplicitParentFillSvg(DOMElement $element, array $declarations): bool
    {
        if ( '' === $this->explicitObjectFit($declarations)
            || '100%' !== trim((string) ($declarations['width'] ?? ''))
            || '100%' !== trim((string) ($declarations['height'] ?? ''))
        ) {
            return false;
        }
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }
        $parentStyle = $this->styleResolver->structuralPresentationDeclarations($parent);
        if ( ! in_array(strtolower(trim((string) ($parentStyle['position'] ?? ''))), array( 'absolute', 'fixed' ), true) ) {
            return false;
        }
        return isset($parentStyle['inset']) && '' !== trim((string) $parentStyle['inset']);
    }

    /** @param array<string, string> $declarations */
    private function isProjectableFillSvg(DOMElement $element, array $declarations): bool
    {
        if ( $this->isExplicitParentFillSvg($element, $declarations) ) {
            return true;
        }
        return '100%' === trim((string) ($declarations['width'] ?? ''))
            && '100%' === trim((string) ($declarations['height'] ?? ''))
            && (bool) preg_match('/(?:^|\s)(?:defer\s+)?x(?:min|mid|max)y(?:min|mid|max)\s+slice(?:\s|$)/i', trim($element->getAttribute('preserveaspectratio')));
    }

    /** @param array<string, string> $declarations */
    private function explicitObjectFit(array $declarations): string
    {
        $objectFit = strtolower(trim((string) ($declarations['object-fit'] ?? '')));
        return in_array($objectFit, array( 'contain', 'cover', 'fill', 'none', 'scale-down' ), true) ? $objectFit : '';
    }

    /** @param array<string, string> $declarations */
    private function imageProjectionBridgeDeclarations(array $declarations, bool $preserveObjectFit = false): string
    {
        $bridge = array( 'display:block' );
        $position = strtolower(trim((string) ($declarations['position'] ?? '')));
        $width = strtolower(trim((string) ($declarations['width'] ?? '')));
        $height = strtolower(trim((string) ($declarations['height'] ?? '')));
        $ownsBox = ! in_array($width, array( '', 'auto' ), true) && ! in_array($height, array( '', 'auto' ), true);
        if ( $ownsBox || in_array($position, array( 'absolute', 'fixed' ), true) ) {
            $bridge[] = 'width:100%';
            $bridge[] = 'height:100%';
        }
        $bridge[] = 'max-width:100%';
        $objectFit = $this->explicitObjectFit($declarations);
        $bridge[] = 'object-fit:' . ($preserveObjectFit && '' !== $objectFit ? $objectFit : 'inherit');
        $bridge[] = 'object-position:inherit';
        $bridge[] = 'border-radius:inherit';
        return implode(';', $bridge);
    }

    /** @param array<string, mixed> $parsed */
    private function rewriteSourceTagTypes(string $selector, array $parsed, AuthorStylesheetProjectionContext $context, string $rightmostInsertion = ''): string
    {
        $replacements = array();
        foreach ( $parsed['type_spans'] as $typeSpan ) {
            $marker = $context->selectorProjections->tagMarker((string) $typeSpan['name']);
            if ( '' !== $marker ) {
                $replacements[$typeSpan['start']] = array( 'end' => $typeSpan['end'], 'value' => ':where(.' . $marker . ')' . $this->typeSpecificityShim($context) );
            }
        }
        if ( '' !== $rightmostInsertion ) {
            $replacements[(int) $parsed['rightmost_rewrite_end']] = array( 'end' => (int) $parsed['rightmost_rewrite_end'], 'value' => $rightmostInsertion );
        }
        return $this->replaceSelectorSpans($selector, $replacements);
    }

    /** @param array<string, mixed> $parsed */
    private function projectControlSelector(string $selector, array $parsed, string $marker, AuthorStylesheetProjectionContext $context, bool $wrapper = false): string
    {
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        return ':where(.' . $marker . ')' . ($wrapper ? ':where(.wp-block-buttons)' : $this->selectorSpecificityShims($parsed, $context) . '> :where(.wp-block-button__link)') . $suffix;
    }

    /** @param array<string, mixed> $parsed */
    private function projectSemanticLeafSelector(string $selector, array $parsed, string $marker, AuthorStylesheetProjectionContext $context): string
    {
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        return ':where(.' . $marker . ')' . $this->selectorSpecificityShims($parsed, $context) . $suffix;
    }

    /** @param array<string, mixed> $parsed */
    private function projectRichTextSemanticSelector(string $selector, array $parsed, string $marker, AuthorStylesheetProjectionContext $context): string
    {
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        return ':where(mark[style*="--blocks-engine-richtext-marker:' . $marker . '"],span[data-blocks-engine-richtext-marker="' . $marker . '"])' . $this->selectorSpecificityShims($parsed, $context) . $suffix;
    }

    /** @param array<string, mixed> $parsed */
    private function projectInlineLayoutCarrierSelector(string $selector, array $parsed): string
    {
        $rightmost = $parsed['rightmost_compound_span'] ?? null;
        if ( ! is_array($rightmost) ) {
            return $selector;
        }
        return substr($selector, 0, (int) $rightmost['start'])
            . 'p.' . self::INLINE_LAYOUT_CARRIER_CLASS . ' > '
            . substr($selector, (int) $rightmost['start']);
    }

    /** @param array<string, mixed> $parsed */
    private function projectTableDescendantSelector(string $selector, array $parsed, DOMElement $element, AuthorStylesheetProjectionContext $context): ?string
    {
        if ( ! in_array(strtolower($element->tagName), array( 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th' ), true)
            || ! TableSelectorProjectionPolicy::needsStructuralProjection($parsed, $element)
        ) {
            return null;
        }
        $table = $this->ancestorElement($element, 'table');
        $marker = $table instanceof DOMElement ? $context->selectorProjections->tableMarker($table->getNodePath() ?? '') : '';
        $path = $table instanceof DOMElement ? $this->serializedTableDescendantPath($table, $element, $context) : '';
        if ( '' === $marker || '' === $path ) {
            return null;
        }
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        return '.' . $marker . '>table>' . $path . $this->selectorSpecificityShims($parsed, $context) . $suffix;
    }

    private function serializedTableDescendantPath(DOMElement $table, DOMElement $element, AuthorStylesheetProjectionContext $context): string
    {
        $tableId = spl_object_id($table);
        return $context->selectorProjections->tableDescendantPath($tableId, spl_object_id($element), function () use ($table): array {
            $paths = array();
            foreach ( array( 'thead', 'tbody', 'tfoot' ) as $section ) {
                $rowIndex = 0;
                foreach ( $table->getElementsByTagName($section) as $sectionElement ) {
                    if ( $sectionElement instanceof DOMElement && $this->belongsToTable($sectionElement, $table) ) {
                        $paths[spl_object_id($sectionElement)] = $section;
                    }
                }
                foreach ( $table->getElementsByTagName('tr') as $row ) {
                    if ( ! $row instanceof DOMElement || ! $this->belongsToTable($row, $table) || $section !== $this->serializedTableSection($row) ) {
                        continue;
                    }
                    ++$rowIndex;
                    $rowPath = $section . '>tr:nth-child(' . $rowIndex . ')';
                    $paths[spl_object_id($row)] = $rowPath;
                    $cellIndex = 0;
                    foreach ( $row->childNodes as $cell ) {
                        if ( ! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), array( 'td', 'th' ), true) ) {
                            continue;
                        }
                        ++$cellIndex;
                        $paths[spl_object_id($cell)] = $rowPath . '>' . strtolower($cell->tagName) . ':nth-child(' . $cellIndex . ')';
                    }
                }
            }
            return $paths;
        });
    }

    private function serializedTableSection(DOMElement $element): string
    {
        return $this->ancestorElement($element, 'thead') instanceof DOMElement
            ? 'thead'
            : ($this->ancestorElement($element, 'tfoot') instanceof DOMElement ? 'tfoot' : 'tbody');
    }

    private function belongsToTable(DOMElement $element, DOMElement $table): bool
    {
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'table' === strtolower($parent->tagName) ) {
                return $parent === $table;
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

    /** @param array<string, mixed> $parsed */
    private function projectImageSelector(string $selector, array $parsed, AuthorStylesheetProjectionContext $context, bool $wrapperOnly = false): string
    {
        $replacements = array(
            (int) $parsed['rightmost_rewrite_end'] => array(
                'end' => (int) $parsed['rightmost_rewrite_end'],
                'value' => $wrapperOnly ? '.wp-block-image' : '.wp-block-image > img',
            ),
        );
        $rightmostType = $parsed['compounds'][count($parsed['compounds']) - 1]['type'] ?? null;
        if ( is_string($rightmostType) && in_array(strtolower($rightmostType), array( 'img', 'svg' ), true) ) {
            $typeSpan = end($parsed['type_spans']);
            if ( is_array($typeSpan) ) {
                $replacements[(int) $typeSpan['start']] = array(
                    'end' => (int) $typeSpan['end'],
                    'value' => ':where(figure)' . $this->typeSpecificityShim($context),
                );
            }
        }
        return $this->replaceSelectorSpans($selector, $replacements);
    }

    private function typeSpecificityShim(AuthorStylesheetProjectionContext $context): string
    {
        return '' === $context->authorStyles->specificityShim() ? '' : ':not(' . $context->authorStyles->specificityShim() . ')';
    }

    /** @param array<string, mixed> $parsed */
    private function selectorSpecificityShims(array $parsed, AuthorStylesheetProjectionContext $context): string
    {
        $shims = '';
        foreach ( $parsed['compounds'] as $compound ) {
            $zeroSpecificity = $compound['zero_specificity'] ?? array();
            if ( null !== $compound['type'] && 0 === (int) ($zeroSpecificity['types'] ?? 0) ) {
                $shims .= $this->typeSpecificityShim($context);
            }
            $classCount = count($compound['classes']) - (int) ($zeroSpecificity['classes'] ?? 0);
            for ( $index = 0; $index < $classCount; ++$index ) {
                $shims .= ':not(.' . $context->authorStyles->classSpecificityShim() . ')';
            }
            $attributeCount = count($compound['attributes']) - (int) ($zeroSpecificity['attributes'] ?? 0);
            for ( $index = 0; $index < $attributeCount; ++$index ) {
                $shims .= ':not(.' . $context->authorStyles->classSpecificityShim() . ')';
            }
            $idCount = count($compound['ids']) - (int) ($zeroSpecificity['ids'] ?? 0);
            for ( $index = 0; $index < $idCount; ++$index ) {
                $shims .= ':not(#' . $context->authorStyles->idSpecificityShim() . ')';
            }
            if ( null !== $compound['nth_child'] || $compound['first_child'] || $compound['last_child'] ) {
                $shims .= ':not(.' . $context->authorStyles->classSpecificityShim() . ')';
            }
        }
        return $shims;
    }

    /** @param array<int, array{end: int, value: string}> $replacements */
    private function replaceSelectorSpans(string $selector, array $replacements): string
    {
        ksort($replacements, SORT_NUMERIC);
        $output = '';
        $offset = 0;
        foreach ( $replacements as $start => $replacement ) {
            $output .= substr($selector, $offset, $start - $offset) . $replacement['value'];
            $offset = $replacement['end'];
        }
        return $output . substr($selector, $offset);
    }

    /** @param array<string, mixed> $parsed @return list<DOMElement> */
    private function matchingSourceElements(string $selector, array $parsed, AuthorStylesheetProjectionContext $context): array
    {
        return $this->semanticPreparer->matchingSourceElements($context->authorStyles, $selector, $parsed);
    }
}
