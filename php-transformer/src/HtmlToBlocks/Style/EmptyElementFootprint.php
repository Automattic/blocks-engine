<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** Empty-box footprint policies. Conditional and structural evidence stay distinct. */
final class EmptyElementFootprint
{
    private const VERTICAL_SPACE = array( 'height', 'min-height', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left' );

    private function isOutOfFlow(array $declarations, bool $stripImportant = true): bool
    {
        $position = (string) ($declarations['position'] ?? '');
        return in_array($stripImportant ? CssValueInspector::comparable($position) : strtolower(trim($position)), array( 'absolute', 'fixed' ), true);
    }

    private function hasPositiveLength(array $declarations, array $properties, ?DOMElement $element = null): bool
    {
        foreach ( $properties as $property ) {
            if ( isset($declarations[$property]) && $this->sourceElementClassifier->isPositiveCssLength($this->styleResolver->resolveCssVariablesInValue($declarations[$property], $element)) ) return true;
        }
        return false;
    }
    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly SourceElementClassifier $sourceElementClassifier
    ) {
    }

    /**
     * Non-style policy is lazy: runtime/identity queries retain their original
     * ordering relative to footprint checks, without callbacks into compilation.
     * @param iterable<string, bool|array> $policy
     */
    public function shouldPreserveEmptyVisualElement(DOMElement $element, iterable $policy): bool
    {
        $paintDeclarations = null;
        foreach ( $policy as $reason => $value ) {
            switch ( $reason ) {
                case 'drop':
                    return false;
                case 'hidden':
                    $inline = $this->styleResolver->cssDeclarations(SourceDom::attr($element, 'style'));
                    $opacity = CssValueInspector::comparable((string) ($inline['opacity'] ?? ''));
                    $paintDeclarations = array_merge($this->styleResolver->structuralPresentationDeclarations($element), $inline);
                    $authoredPaint = array() !== array_intersect_key($paintDeclarations, array_flip(array( 'background', 'background-color', 'background-image' )));
                    if ( $value && ! (is_numeric($opacity) && 0.0 === (float) $opacity && $authoredPaint) ) return false;
                    break;
                case 'keep':
                    if ( $value ) return true;
                    break;
                case 'layout':
                    if ( $this->isEmptyLayoutItemSpacer($element) ) return true;
                    break;
                case 'inline':
                    return $value || $this->isEmptyVisualInlineCandidate($element)
                        || ($this->hasEmptyVisualBlockDisplay($element) && $this->hasVisibleEmptyVisualPaint($paintDeclarations ?? array(), $element))
                        || $this->occupiesDefiniteGridCell($element);
                case 'block':
                    if ( $value || $this->hasFlowClear($element) || $this->hasRenderableEmptyBlockBox($element) ) return true;
                    break;
                case 'viewport':
                    if ( $this->hasRenderableEmptyBoxAtAnyViewport($element, $value) || $this->occupiesDefiniteGridCell($element) ) return true;
                    break;
            }
        }
        return $this->isEmptyVisualInlineCandidate($element);
    }

    /**
     * `opacity:0` and `visibility:hidden` stop a box from painting, not from
     * taking space: an in-flow box keeps its padding, border, and definite
     * size, and a painted ancestor shows that space. Only a box that is not
     * rendered, is out of flow, or is a zero box takes none.
     */
    public function occupiesInFlowSpace(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        if ( $this->isOutOfFlow($declarations)
            || in_array(CssValueInspector::comparable((string) ($declarations['display'] ?? '')), array( 'none', 'contents' ), true)
        ) {
            return false;
        }
        foreach ( array_merge(self::VERTICAL_SPACE, array( 'width', 'min-width', 'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-width', 'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width' )) as $property ) {
            $value = $this->styleResolver->resolveCssVariablesInValue((string) ($declarations[$property] ?? ''), $element);
            foreach ( preg_split('/\s+/', CssValueInspector::comparable($value)) ?: array() as $token ) {
                if ( $this->sourceElementClassifier->isPositiveCssLength($token) ) {
                    return true;
                }
            }
        }
        return false;
    }

    private function isEmptyLayoutItemSpacer(DOMElement $element): bool
    {
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement || 0 !== SourceDom::childElementCount($element) ) {
            return false;
        }
        $parentProperties = array( 'display', 'flex-direction' );
        $properties = array( 'position', 'display', 'width', 'min-width', 'height', 'min-height', 'flex-basis', 'flex-grow', 'flex' );
        $parentDeclared = $declared = array();
        $conditions = array('' => array());
        foreach (array(array($parent, $parentProperties), array($element, $properties)) as $index => [$node, $wanted]) {
            foreach ($wanted as $property) {
                $presentation = $this->styleResolver->declaredPresentation($node, $property);
                if (0 === $index) $parentDeclared[$property] = $presentation;
                else $declared[$property] = $presentation;
                foreach ($presentation->conditionStacks() as $stack) {
                    $conditions[implode("\n", $stack)] = $stack;
                }
            }
        }
        $parentBase = $this->styleResolver->structuralPresentationDeclarations($parent);
        $base = $this->styleResolver->structuralPresentationDeclarations($element);
        $parentInline = $this->styleResolver->cssDeclarations(SourceDom::attr($parent, 'style'));
        $inline = $this->styleResolver->cssDeclarations(SourceDom::attr($element, 'style'));
        foreach ($conditions as $stack) {
            // Read both boxes in the same responsive state. Never combine a
            // phone flex parent with a size declared only for desktop (#4276).
            $read = static function (array $base, array $declared, array $inline) use ($stack): array {
                foreach ($declared as $property => $presentation) {
                    $value = $presentation->resolvedValueWhere(static fn (array $query): bool => array() === array_diff($query, $stack));
                    if ('' !== $value) $base[$property] = $value;
                }
                return array_merge($base, $inline);
            };
            if ($this->emptyFlexItemHasFootprint($element, $read($parentBase, $parentDeclared, $parentInline), $read($base, $declared, $inline))) {
                return true;
            }
        }
        return false;
    }

    private function emptyFlexItemHasFootprint(DOMElement $element, array $parentDeclarations, array $declarations): bool
    {
        $display = CssValueInspector::comparable((string) ($parentDeclarations['display'] ?? ''));
        if ( ! in_array($display, array( 'flex', 'inline-flex' ), true) ) {
            return false;
        }
        if ( $this->isOutOfFlow($declarations)
            || in_array(CssValueInspector::comparable((string) ($declarations['display'] ?? '')), array( 'none', 'contents' ), true)
        ) {
            return false;
        }

        $column = in_array(CssValueInspector::comparable((string) ($parentDeclarations['flex-direction'] ?? '')), array( 'column', 'column-reverse' ), true);
        $properties = $column ? array( 'height', 'min-height', 'flex-basis' ) : array( 'width', 'min-width', 'flex-basis' );
        $hasSize = fn (string $value): bool => $this->sourceElementClassifier->isPositiveCssLength($value)
            || (1 === preg_match('/^\+?(\d+(?:\.\d+)?|\.\d+)%$/', trim($value), $matches) && 0 < (float) $matches[1]);
        foreach ( $properties as $property ) {
            $value = $this->styleResolver->resolveCssVariablesInValue((string) ($declarations[$property] ?? ''), $element);
            if ( $hasSize($value) ) {
                return true;
            }
        }
        // Positive growth reserves free space even with a zero basis. A
        // shrink factor alone (including the default 0 1 auto) does not.
        $grow = CssValueInspector::comparable((string) ($declarations['flex-grow'] ?? ''));
        if ( is_numeric($grow) && 0 < (float) $grow ) {
            return true;
        }
        $flex = $this->styleResolver->resolveCssVariablesInValue((string) ($declarations['flex'] ?? ''), $element);
        $parts = preg_split('/\s+/', trim($flex)) ?: array();
        $basis = (string) (count($parts) >= 3 ? $parts[2] : (count($parts) === 2 && ! is_numeric($parts[1]) ? $parts[1] : ($parts[0] ?? '')));
        return 'auto' === $flex
            || (isset($parts[0]) && is_numeric($parts[0]) && 0 < (float) $parts[0])
            || $hasSize($basis);
    }

    /**
     * An empty element that clears floats still sizes its block container.
     * `clear` is outside the source-style allow-list, so it resolves from the
     * author analysis, which keeps every declaration.
     */
    private function hasFlowClear(DOMElement $element): bool
    {
        $clear = $this->styleResolver->authorStructuralDeclarations($element)['clear'] ?? 'none';
        return 'none' !== CssValueInspector::comparable((string) $clear);
    }

    private function hasRenderableEmptyBlockBox(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        if ( $this->hasAuthoredGridTracks($element) ) {
            return true;
        }
        if ( $this->hasPositiveLength($declarations, self::VERTICAL_SPACE, $element) || $this->hasVisibleEmptyVisualPaint($declarations, $element) ) {
            return true;
        }
        // Structural block policy historically compares the literal position.
        if ( ! $this->isOutOfFlow($declarations, false) ) {
            return false;
        }

        return $this->hasPositiveLength($declarations, array( 'width', 'min-width', 'height', 'min-height' ), $element)
            || isset($declarations['inset'])
            || ( isset($declarations['left']) && isset($declarations['right']) )
            || ( isset($declarations['top']) && isset($declarations['bottom']) );
    }

    public function hasVisibleEmptyVisualPaint(array $declarations, ?DOMElement $element = null): bool
    {
        if ( $element instanceof DOMElement ) {
            foreach ( array('background', 'background-color', 'background-image', 'box-shadow', 'outline', 'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-color') as $property ) {
                if ( isset($declarations[$property]) ) {
                    $declarations[$property] = $this->styleResolver->resolveEmptyVisualPaintVariables($declarations[$property], $element);
                }
            }
        }

        foreach ( array( 'background', 'background-color', 'background-image', 'box-shadow', 'outline', 'border', 'border-top', 'border-right', 'border-bottom', 'border-left' ) as $property ) {
            if ( ! isset($declarations[$property]) ) continue;
            $value = $this->styleResolver->resolveCssVariablesInValue($declarations[$property], $element);
            if ( str_starts_with($property, 'border') ? $this->sourceElementClassifier->isVisibleEmptyVisualBorder($value) : $this->sourceElementClassifier->isVisibleEmptyVisualPaint($value) ) return true;
        }

        return isset($declarations['border-color'], $declarations['border-width'])
            && $this->sourceElementClassifier->isVisibleEmptyVisualPaint($this->styleResolver->resolveCssVariablesInValue($declarations['border-color'], $element))
            && $this->sourceElementClassifier->isPositiveCssLength($this->styleResolver->resolveCssVariablesInValue($declarations['border-width'], $element));
    }

    public function shouldPreserveEmptyVisualFigure(DOMElement $figure, string $renderedText, array $pseudoRules): bool
    {
        if ( '' !== $renderedText || 0 !== SourceDom::childElementCount($figure) ) {
            return false;
        }

        $declarations = $this->styleResolver->structuralPresentationDeclarations($figure);
        if ( ! $this->hasPositiveLength($declarations, array( 'height', 'min-height' ), $figure) ) {
            return false;
        }

        if ( $this->hasVisibleEmptyVisualPaint($declarations, $figure) ) {
            return true;
        }

        foreach ( $pseudoRules as $rule ) {
            if ( $this->styleResolver->matchesCssSelector($figure, $rule['selector']) && $this->hasVisibleEmptyVisualPaint($rule['declarations'], $figure) ) {
                return true;
            }
        }

        return false;
    }

    public function cssDeclarationsPaintOrOccupySpace(string $declarations): bool
    {
        $declared = array();
        foreach ( $this->styleResolver->cssDeclarations($declarations) as $property => $value ) {
            $declared[strtolower(trim((string) $property))] = trim((string) $value);
        }

        $hasHeight = ( isset($declared['height']) && $this->cssValueIsNonZero($declared['height']) )
            || ( isset($declared['min-height']) && $this->cssValueIsNonZero($declared['min-height']) );

        foreach ( $declared as $property => $value ) {
            if ( ! $this->cssPropertyPaintsOrOccupiesSpace($property, $hasHeight) ) {
                continue;
            }
            if ( $this->cssValueIsNonZero((string) $value) ) {
                return true;
            }
            // A background paints with any other value — even all-zero
            // colors like `#000`; only `none` stays quiet.
            $value = strtolower(trim((string) $value));
            if ( str_starts_with($property, 'background') && ! in_array($value, array( '', 'none' ), true) ) {
                return true;
            }
        }

        return false;
    }

    public function subtreeHasPaintedPseudo(DOMElement $element, array $pseudoRules): bool
    {
        $nodes = array_merge(array( $element ), SourceDom::descendantElements($element));
        $classes = array();
        foreach ( $nodes as $node ) {
            foreach ( preg_split('/\s+/', trim(SourceDom::attr($node, 'class'))) ?: array() as $class ) {
                if ( '' !== $class ) {
                    $classes[$class] = true;
                }
            }
        }
        foreach ( $pseudoRules as $rule ) {
            $normalized = strtolower(trim((string) ($rule['declarations']['content'] ?? ''), " \t\"'"));
            if ( in_array($normalized, array( '', 'none', 'normal', 'open-quote', 'close-quote', 'no-open-quote', 'no-close-quote' ), true) || 1 === preg_match('/^attr\(\s*data-text\s*\)$/', $normalized) ) {
                continue;
            }
            $selector = (string) ($rule['selector'] ?? '');
            $relevant = false;
            foreach ( $classes as $class => $_ ) {
                if ( str_contains($selector, $class) ) {
                    $relevant = true;
                    break;
                }
            }
            if ( ! $relevant ) {
                continue;
            }
            foreach ( $nodes as $node ) {
                if ( $this->styleResolver->matchesCssSelector($node, $selector) ) {
                    return true;
                }
            }
        }

        return false;
    }

    public function declaresEmptyInlineLayoutBox(DOMElement $element): bool
    {
        foreach ( array_merge(array( $element ), SourceDom::descendantElements($element)) as $node ) {
            $declared = $this->styleResolver->authorDeclaredValuesAtAnyViewport($node, array( 'display', 'gap', 'column-gap', 'row-gap', 'height', 'min-height', 'overflow', 'overflow-x', 'overflow-y' ));
            foreach ( $declared['display'] ?? array() as $value ) {
                if ( in_array(strtolower(trim($value)), array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true) ) {
                    return true;
                }
            }
            foreach ( array( 'gap', 'column-gap', 'row-gap', 'height', 'min-height' ) as $property ) {
                foreach ( $declared[$property] ?? array() as $value ) {
                    if ( CssValueInspector::isNonZero($value) || str_contains($value, 'calc(') ) {
                        return true;
                    }
                }
            }
            foreach ( array( 'overflow', 'overflow-x', 'overflow-y' ) as $property ) {
                foreach ( $declared[$property] ?? array() as $value ) {
                    if ( in_array(strtolower(trim($value)), array( 'clip', 'hidden', 'auto', 'scroll' ), true) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function cssPropertyPaintsOrOccupiesSpace(string $property, bool $hasHeight): bool
    {
        foreach ( array( 'background', 'border', 'outline', 'padding', 'margin', 'mask' ) as $prefix ) {
            if ( str_starts_with($property, $prefix) ) {
                return true;
            }
        }

        return in_array($property, array( 'aspect-ratio', 'box-shadow', 'clip-path', 'content', 'filter', 'flex-basis', 'height', 'min-height' ), true)
            || ( in_array($property, array( 'width', 'min-width' ), true) && $hasHeight );
    }

    private function cssValueIsNonZero(string $value): bool
    {
        $value = strtolower(trim($value));
        if ( '' === $value || 'none' === $value ) {
            return false;
        }
        if ( 0 === preg_match_all('/\d+(?:\.\d+)?/', $value, $numbers) ) {
            return true;
        }
        foreach ( $numbers[0] as $number ) {
            if ( 0.0 !== (float) $number ) {
                return true;
            }
        }

        return false;
    }

    private function occupiesDefiniteGridCell(DOMElement $element): bool
    {
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement || 0 !== SourceDom::childElementCount($element) || '' !== trim($element->textContent ?? '') ) {
            return false;
        }
        $parentDeclarations = $this->styleResolver->structuralPresentationDeclarations($parent);
        if ( ! in_array(CssValueInspector::comparable((string) ($parentDeclarations['display'] ?? '')), array( 'grid', 'inline-grid' ), true) ) {
            return false;
        }
        foreach ( array( 'grid-template-rows', 'grid-template-columns' ) as $property ) {
            if ( ! $this->isDefiniteGridTrackList($this->styleResolver->resolveCssVariablesInValue((string) ($parentDeclarations[$property] ?? ''), $parent)) ) {
                return false;
            }
        }
        $declarations = array_merge(
            $this->styleResolver->structuralPresentationDeclarations($element),
            $this->styleResolver->cssDeclarations(SourceDom::attr($element, 'style'))
        );
        return ! in_array(CssValueInspector::comparable((string) ($declarations['display'] ?? '')), array( 'none', 'contents' ), true)
            && ! $this->isOutOfFlow($declarations);
    }

    private function isDefiniteGridTrackList(string $tracks): bool
    {
        $tracks = strtolower(trim($tracks));
        while ( 1 === preg_match('/repeat\(\s*\d+\s*,\s*([^()]*)\)/', $tracks, $match) ) {
            $tracks = str_replace($match[0], $match[1], $tracks);
        }
        $sizes = preg_split('/\s+/', $tracks) ?: array();
        if ( '' === $tracks || array() === $sizes ) {
            return false;
        }
        foreach ( $sizes as $size ) {
            if ( ! $this->sourceElementClassifier->isPositiveCssLength($size) ) {
                return false;
            }
        }
        return true;
    }

    public function hasAuthoredGridTracks(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        if ( ! in_array(CssValueInspector::comparable((string) ($declarations['display'] ?? '')), array( 'grid', 'inline-grid' ), true) ) {
            return false;
        }
        foreach ( array( 'grid-template-rows', 'grid-auto-rows' ) as $property ) {
            $tracks = strtolower(trim((string) ($declarations[$property] ?? '')));
            if ( '' !== $tracks && ! in_array($tracks, array( 'none', 'initial', 'unset' ), true) ) {
                return true;
            }
        }
        return false;
    }

    public function hasEmptyVisualBlockDisplay(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        foreach ( array( 'width', 'height' ) as $property ) {
            $size = $this->styleResolver->resolveCssVariablesInValue((string) ($declarations[$property] ?? 'auto'), $element);
            if ( 1 === preg_match('/^[+-]?0(?:\.0+)?(?:[a-z]+|%)?$/i', trim($size)) ) {
                return false;
            }
        }
        return in_array(CssValueInspector::comparable((string) ($declarations['display'] ?? '')), array( 'block', 'flow-root', 'flex', 'grid', 'inline-block', 'inline-flex', 'inline-grid' ), true);
    }

    public function isEmptyVisualInlineCandidate(DOMElement $element): bool
    {
        if ( '' !== trim($element->textContent ?? '') || 0 !== SourceDom::childElementCount($element) || ! $this->sourceElementClassifier->isInlineContentElement(strtolower($element->tagName)) ) {
            return false;
        }

        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        return $this->hasExplicitEmptyVisualDimensions($declarations) && $this->hasVisibleEmptyVisualPaint($declarations);
    }

    private function hasExplicitEmptyVisualDimensions(array $declarations): bool
    {
        return $this->hasPositiveLength($declarations, array( 'width' ))
            && $this->hasPositiveLength($declarations, array( 'height' ));
    }

    private function hasRenderableEmptyBoxAtAnyViewport(DOMElement $element, array $evidence): bool
    {
        foreach ( $evidence as $evidence ) {
            $declarations = $evidence['declarations'];
            if ( $this->hasVisibleEmptyVisualPaint($declarations, $element) || $this->hasPositiveLength($declarations, self::VERTICAL_SPACE, $element) ) return true;
        }
        return false;
    }
}
