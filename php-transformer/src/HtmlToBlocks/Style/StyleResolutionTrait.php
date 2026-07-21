<?php

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\WordPress\GeneratedGutenbergClassPolicy;
use DOMElement;

/**
 * CSS / style-resolution concern extracted from HtmlTransformer.
 *
 * Resolves an element's declared styling from the source `<style>`/linked CSS
 * and computes presentation attributes: static CSS-rule collection, supported
 * selector matching (`matchesCssSelector`), merged inline + matched-rule style
 * (`mergedPresentationStyle`), CSS declaration parsing/serialization, layout
 * attribute inference, and presentation class-name normalization. This is the
 * CSS-rule resolution the font/typography path and `ButtonStyleResolver` rely
 * on, given a single home so style work no longer collides in the god-object.
 *
 * Pure move: methods extracted verbatim from HtmlTransformer with no logic or
 * signature changes. Methods reference `$this->attr()` / `$this->safeAnchor()`
 * (DomHelpersTrait), `$this->promotedClassName()` / `$this->cardLikeChildCount()`,
 * and the `$staticStyleRules` property, all composed onto HtmlTransformer.
 */
trait StyleResolutionTrait
{
    private ?StyleAttributeMapper $styleAttributeMapper = null;

    private ?HighValueStyleBoundaryPolicy $highValueStyleBoundaryPolicy = null;

    /**
     * Resolved presentation attributes for the active transform, keyed by the
     * DOMElement wrapper object id plus node path. PHP may reuse wrapper object
     * ids within one traversal as transient DOMElement wrappers are released.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $presentationAttributesCache = array();

    /**
     * @var array<string, array<string, string>>
     */
    private array $presentationDeclarationsCache = array();

    /**
     * @var array<string, string>
     */
    private array $mergedPresentationStyleCache = array();

    /**
     * Inline sizing declarations which core block supports cannot serialize are
     * carried by deterministic classes in a generated stylesheet.
     *
     * @var array<string, string>
     */
    private array $generatedGeometryRules = array();

    private ?GeometryCarrierClassAllocator $geometryCarrierClassAllocator = null;

    /**
     * @return list<string>
     */
    private function inlineGeometryProperties(): array
    {
        return array(
            'width',
            'height',
            'min-width',
            'min-height',
            'max-width',
            'max-height',
            'aspect-ratio',
            'box-sizing',
            'flex-basis',
        );
    }

    private function resetPresentationResolutionCache(): void
    {
        $this->presentationAttributesCache = array();
        $this->presentationDeclarationsCache = array();
        $this->mergedPresentationStyleCache = array();
        $this->generatedGeometryRules = array();
        $this->geometryCarrierClassAllocator = null;
    }

    private function styleAttributeMapper(): StyleAttributeMapper
    {
        return $this->styleAttributeMapper ??= new StyleAttributeMapper();
    }

    private function highValueStyleBoundaryPolicy(): HighValueStyleBoundaryPolicy
    {
        return $this->highValueStyleBoundaryPolicy ??= new HighValueStyleBoundaryPolicy();
    }

    /**
     * Resolve an element's presentation into canonical block attributes.
     *
     * The merged CSS is translated into the canonical block `style` OBJECT
     * (typography/color/spacing/border) plus the `layout` attribute. Class-owned
     * vertical flex CSS stays owned by the preserved `className` to avoid
     * WordPress `is-vertical` layout classes overriding source CSS. A raw inline
     * `style` STRING is never emitted on a block: declarations that do not map to
     * a block support are dropped and ride on `className` instead (#261). Frozen
     * responsive/JS hidden base states are normalized away (#259).
     *
     * @return array<string, mixed>
     */
    private function presentationAttributes(DOMElement $element, array $excludedGeometryProperties = array()): array
    {
        $cacheKey = $this->presentationCacheKey($element) . ':' . implode(',', $excludedGeometryProperties);
        if ( isset($this->presentationAttributesCache[$cacheKey]) ) {
            return $this->presentationAttributesCache[$cacheKey];
        }

        $declarations = $this->classOwnedResponsiveDeclarations(
            $element,
            $this->presentationDeclarations($element)
        );
        $mapped       = $this->styleAttributeMapper()->map($declarations);

        $attrs = array_filter(array_merge($mapped['attrs'] ?? array(), array(
            'anchor'    => $this->safeAnchor($this->attr($element, 'id')),
            'className' => $this->mergePresentationClassNames(
                $this->promotedClassName($this->attr($element, 'class')),
                $this->inlineGeometryClassName($element, $excludedGeometryProperties)
            ),
            'inlineGeometryStyle' => $this->inlineGeometryStyle($element, $excludedGeometryProperties),
            'style'     => $mapped['style'],
            'layout'    => $this->layoutAttribute($element, $this->cssDeclarationString($declarations)),
        )), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));

        $this->presentationAttributesCache[$cacheKey] = $attrs;

        return $attrs;
    }

    /**
     * Keep declarations with conditional variants under author stylesheet
     * ownership. Promoting their base values to block supports would serialize
     * them inline and prevent media/container queries from winning the cascade.
     * Explicit source inline declarations retain their normal priority.
     *
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function classOwnedResponsiveDeclarations(DOMElement $element, array $declarations): array
    {
        if (array() === $declarations || array() === $this->conditionalStyleRules) {
            return $declarations;
        }

        $conditionalFamilies = array();
        foreach ($this->conditionalStyleRules as $rule) {
            if (! $this->matchesCssSelector($element, $rule['selector'])) {
                continue;
            }
            foreach (array_keys($rule['declarations']) as $property) {
                $conditionalFamilies[$this->responsivePropertyFamily($property)] = true;
            }
        }

        if (array() === $conditionalFamilies) {
            return $declarations;
        }

        $inline = $this->cssDeclarations($this->attr($element, 'style'));
        foreach (array_keys($declarations) as $property) {
            $family = $this->responsivePropertyFamily($property);
            if (! isset($conditionalFamilies[$family]) || $this->inlineOwnsResponsiveProperty($property, $family, $inline)) {
                continue;
            }
            unset($declarations[$property]);
        }

        return $declarations;
    }

    private function responsivePropertyFamily(string $property): string
    {
        $property = strtolower(trim($property));
        if (
            in_array($property, array('display', 'gap', 'row-gap', 'column-gap', 'justify-content', 'align-content', 'align-items', 'align-self'), true)
            || str_starts_with($property, 'flex-')
            || str_starts_with($property, 'grid-')
        ) {
            return 'layout';
        }
        foreach (array('padding', 'margin', 'border', 'background') as $family) {
            if ($property === $family || str_starts_with($property, $family . '-')) {
                return $family;
            }
        }

        return $property;
    }

    /**
     * @param array<string, string> $inline
     */
    private function inlineOwnsResponsiveProperty(string $property, string $family, array $inline): bool
    {
        if (isset($inline[$property])) {
            return true;
        }

        return $property !== $family && isset($inline[$family]);
    }

    private function hasConditionalStyleFamily(DOMElement $element, string $family): bool
    {
        foreach ($this->conditionalStyleRules as $rule) {
            if (! $this->matchesCssSelector($element, $rule['selector'])) {
                continue;
            }
            foreach (array_keys($rule['declarations']) as $property) {
                if ($family === $this->responsivePropertyFamily($property)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Core supports cannot serialize arbitrary box dimensions. Keep only source
     * inline geometry in a generated stylesheet; class-owned declarations are
     * already retained by author stylesheet materialization.
     */
    private function inlineGeometryClassName(DOMElement $element, array $excludedProperties = array()): string
    {
        $declarations = $this->cssDeclarations($this->attr($element, 'style'));
        $geometry = array();
        foreach ($this->inlineGeometryProperties() as $property) {
            if (in_array($property, $excludedProperties, true)) {
                continue;
            }
            $rawValue = trim((string) ($declarations[$property] ?? ''));
            if (1 === preg_match('/\s*!important\s*$/i', $rawValue)) {
                continue;
            }
            $value = $rawValue;
            if ('' !== $value && ! preg_match('/[{}<>;]/', $value)) {
                $geometry[$property] = $value;
            }
        }

        if (array() === $geometry) {
            return '';
        }

        ksort($geometry);
        $declarations = array();
        foreach ($geometry as $property => $value) {
            // A converted inline declaration must continue to outrank authored
            // normal selectors, including ID selectors. Authored !important
            // rules retain their normal cascade priority through specificity.
            $declarations[] = $property . ':' . $value . ' !important';
        }
        $rule = implode(';', $declarations);
        $className = ($this->geometryCarrierClassAllocator ??= new GeometryCarrierClassAllocator())->allocate($this->geometryStructuralPath($element) . "\n" . $rule);
        $this->generatedGeometryRules[$className] = '.' . $className . '{' . $rule . '}';

        return $className;
    }

    private function geometryStructuralPath(DOMElement $element): string
    {
        $segments = array();
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            $index = 1;
            for ($sibling = $node->previousSibling; null !== $sibling; $sibling = $sibling->previousSibling) {
                if ($sibling instanceof DOMElement && strtolower($sibling->tagName) === strtolower($node->tagName)) {
                    ++$index;
                }
            }
            $segments[] = strtolower($node->tagName) . ':' . $index;
        }

        return implode('/', array_reverse($segments));
    }

    private function inlineGeometryStyle(DOMElement $element, array $excludedProperties = array()): string
    {
        $declarations = $this->cssDeclarations($this->attr($element, 'style'));
        $style = array();
        $geometryValues = array();
        foreach ($this->inlineGeometryProperties() as $property) {
            if (in_array($property, $excludedProperties, true)) {
                continue;
            }
            $value = trim((string) ($declarations[$property] ?? ''));
            $geometryValues[] = $value;
            if (1 === preg_match('/\s*!important\s*$/i', $value)) {
                $style[] = $property . ':' . $value;
            }
        }

        if (array_filter($geometryValues, static fn (string $value): bool => str_contains($value, 'var('))) {
            foreach ($declarations as $property => $value) {
                if (str_starts_with($property, '--')) {
                    $style[] = $property . ':' . $value;
                }
            }
        }

        return implode(';', $style);
    }

    private function mergePresentationClassNames(string ...$classNames): string
    {
        $classes = array();
        foreach ($classNames as $className) {
            foreach (preg_split('/\s+/', trim($className)) ?: array() as $class) {
                if ('' !== $class && ! in_array($class, $classes, true)) {
                    $classes[] = $class;
                }
            }
        }

        return implode(' ', $classes);
    }

    private function generatedGeometryCss(string $serializedBlocks): string
    {
        $rules = array();
        foreach ($this->generatedGeometryRules as $className => $rule) {
            if (preg_match('/(?:^|[^a-zA-Z0-9_-])' . preg_quote($className, '/') . '(?:$|[^a-zA-Z0-9_-])/', $serializedBlocks)) {
                $rules[] = $rule;
            }
        }

        return implode("\n", $rules);
    }

    /**
     * @return array<string, string>
     */
    private function presentationDeclarations(DOMElement $element): array
    {
        $cacheKey = $this->presentationCacheKey($element);
        if ( isset($this->presentationDeclarationsCache[$cacheKey]) ) {
            return $this->presentationDeclarationsCache[$cacheKey];
        }

        $style = $this->mergedPresentationStyle($element);
        $this->presentationDeclarationsCache[$cacheKey] = $this->stripFrozenHiddenState($element, $this->cssDeclarations($style));

        return $this->presentationDeclarationsCache[$cacheKey];
    }

    /**
     * Resolve structural context even when the element is not itself a style
     * boundary. Child classification still needs parent flex/grid semantics.
     *
     * @return array<string, string>
     */
    private function structuralPresentationDeclarations(DOMElement $element): array
    {
        $declarations = array();
        foreach ( $this->staticStyleRules as $rule ) {
            if ( $this->matchesCssSelector($element, $rule['selector']) ) {
                $declarations = array_merge($declarations, $rule['declarations']);
            }
        }

        return array_merge($declarations, $this->cssDeclarations($this->attr($element, 'style')));
    }

    /**
     * Remove responsive/JS-revealed hidden base states (display:none /
     * visibility:hidden / opacity:0) from content-bearing or interactive
     * elements so they are not frozen permanently invisible (#259). Genuinely
     * decorative / aria-hidden nodes keep their hidden declarations.
     *
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function stripFrozenHiddenState(DOMElement $element, array $declarations): array
    {
        if ( array() === $declarations || $this->isDecorativeHiddenElement($element) ) {
            return $declarations;
        }

        $stripped = array();
        if ( isset($declarations['display']) && 'none' === strtolower(trim($declarations['display'])) ) {
            unset($declarations['display']);
            $stripped[] = 'display:none';
        }
        if ( isset($declarations['visibility']) && 'hidden' === strtolower(trim($declarations['visibility'])) ) {
            unset($declarations['visibility']);
            $stripped[] = 'visibility:hidden';
        }
        if ( isset($declarations['opacity']) && is_numeric(trim($declarations['opacity'])) && 0.0 === (float) trim($declarations['opacity']) ) {
            unset($declarations['opacity']);
            $stripped[] = 'opacity:0';
        }

        if ( array() !== $stripped ) {
            $this->frozenHiddenStateFindings[] = array(
                'tag'          => strtolower($element->tagName),
                'selector'     => $this->elementSelector($element),
                'declarations' => $stripped,
            );
        }

        return $declarations;
    }

    /**
     * An element is treated as genuinely (decoratively) hidden when it carries
     * no real content or interactivity, or it is explicitly aria-hidden /
     * presentational. Such nodes may stay hidden; everything else is assumed to
     * be a responsive/JS-revealed element captured in its base-hidden state.
     */
    private function isDecorativeHiddenElement(DOMElement $element): bool
    {
        if ( 'true' === strtolower(trim($this->attr($element, 'aria-hidden'))) ) {
            return true;
        }
        if ( in_array(strtolower(trim($this->attr($element, 'role'))), array( 'presentation', 'none' ), true) ) {
            return true;
        }
        if ( in_array(strtolower($element->tagName), array( 'svg', 'canvas' ), true) ) {
            return true;
        }

        if ( '' !== trim($element->textContent ?? '') ) {
            return false;
        }

        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && in_array(strtolower($descendant->tagName), array( 'a', 'button', 'input', 'select', 'textarea', 'img', 'picture', 'video', 'audio', 'iframe', 'nav', 'form' ), true) ) {
                return false;
            }
        }

        return true;
    }

    private function mergedPresentationStyle(DOMElement $element): string
    {
        $cacheKey = $this->presentationCacheKey($element);
        if ( isset($this->mergedPresentationStyleCache[$cacheKey]) ) {
            return $this->mergedPresentationStyleCache[$cacheKey];
        }

        $inlineStyle = $this->attr($element, 'style');
        if ( array() === $this->staticStyleRules || ! $this->isHighValueStyledElement($element) ) {
            $this->mergedPresentationStyleCache[$cacheKey] = $inlineStyle;
            return $inlineStyle;
        }

        $declarations = array();
        foreach ( $this->staticStyleRules as $rule ) {
            if ( $this->matchesCssSelector($element, $rule['selector']) ) {
                $declarations = array_merge($declarations, $rule['declarations']);
            }
        }

        if ( array() === $declarations ) {
            $this->mergedPresentationStyleCache[$cacheKey] = $inlineStyle;
            return $inlineStyle;
        }

        $declarations = array_merge($declarations, $this->cssDeclarations($inlineStyle));
        $this->mergedPresentationStyleCache[$cacheKey] = $this->cssDeclarationString($declarations);

        return $this->mergedPresentationStyleCache[$cacheKey];
    }

    private function presentationCacheKey(DOMElement $element): string
    {
        return spl_object_id($element) . ':' . $element->getNodePath();
    }

    private function isHighValueStyledElement(DOMElement $element): bool
    {
        return $this->highValueStyleBoundaryPolicy()->matches($element);
    }

    /**
     * @return array<int, array{selector: string, declarations: array<string, string>}>
     */
    private function staticStyleRules(string $html, string $linkedCss): array
    {
        $css = trim($linkedCss);
        if ( preg_match_all('@<style\b[^>]*>(.*?)</style>@is', $html, $matches) ) {
            $css .= ( '' === $css ? '' : "\n" ) . implode("\n", array_map('trim', $matches[1]));
        }

        if ( '' === trim($css) ) {
            return array();
        }

        $css = preg_replace('@/\*.*?\*/@s', '', $css) ?? $css;
        $css = $this->topLevelCssRules($css);
        $rules = array();
        if ( ! preg_match_all('/([^{}]+)\{([^{}]+)\}/', $css, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        foreach ( $matches as $match ) {
            $declarations = $this->safeVisualDeclarations($this->cssDeclarations((string) $match[2]));
            if ( array() === $declarations ) {
                continue;
            }
            foreach ( explode(',', (string) $match[1]) as $selector ) {
                $selector = trim($selector);
                if ( '' !== $selector && ! $this->selectorCarriesPseudoState($selector) && $this->isSupportedCssSelector($selector) ) {
                    $rules[] = array(
                        'selector' => $selector,
                        'declarations' => $declarations,
                    );
                }
            }
        }

        return $rules;
    }

    /**
     * Collect author rules nested in conditional at-rules. Their declarations
     * must remain class-owned even though only the base cascade is available to
     * the server-side transformer.
     *
     * @return array<int, array{selector: string, declarations: array<string, string>}>
     */
    private function conditionalStyleRules(string $html, string $linkedCss): array
    {
        $css = trim($linkedCss);
        if (preg_match_all('@<style\b[^>]*>(.*?)</style>@is', $html, $matches)) {
            $css .= ('' === $css ? '' : "\n") . implode("\n", array_map('trim', $matches[1]));
        }
        if ('' === trim($css)) {
            return array();
        }

        $css = preg_replace('@/\*.*?\*/@s', '', $css) ?? $css;
        $conditionalCss = '';
        $length = strlen($css);
        for ($offset = 0; $offset < $length; ++$offset) {
            if ('@' !== $css[$offset]) {
                continue;
            }
            $blockStart = $this->findCssToken($css, '{', $offset);
            $statementEnd = $this->findCssToken($css, ';', $offset);
            if (null === $blockStart || (null !== $statementEnd && $statementEnd < $blockStart)) {
                if (null !== $statementEnd) {
                    $offset = $statementEnd;
                }
                continue;
            }

            $prelude = strtolower(trim(substr($css, $offset, $blockStart - $offset)));
            $depth = 1;
            for ($end = $blockStart + 1; $end < $length; ++$end) {
                if ('"' === $css[$end] || "'" === $css[$end]) {
                    $quote = $css[$end];
                    for (++$end; $end < $length; ++$end) {
                        if ('\\' === $css[$end]) {
                            ++$end;
                            continue;
                        }
                        if ($quote === $css[$end]) {
                            break;
                        }
                    }
                    continue;
                }
                if ('{' === $css[$end]) {
                    ++$depth;
                } elseif ('}' === $css[$end] && 0 === --$depth) {
                    if (preg_match('/^@(media|container|supports)\b/', $prelude)) {
                        $conditionalCss .= "\n" . substr($css, $blockStart + 1, $end - $blockStart - 1);
                    }
                    $offset = $end;
                    continue 2;
                }
            }
            break;
        }

        $rules = array();
        if (! preg_match_all('/([^{}]+)\{([^{}]+)\}/', $conditionalCss, $matches, PREG_SET_ORDER)) {
            return $rules;
        }
        foreach ($matches as $match) {
            $declarations = $this->safeVisualDeclarations($this->cssDeclarations((string) $match[2]));
            if (array() === $declarations) {
                continue;
            }
            foreach (explode(',', (string) $match[1]) as $selector) {
                $selector = trim($selector);
                if ('' !== $selector && ! str_starts_with($selector, '@') && ! $this->selectorCarriesPseudoState($selector) && $this->isSupportedCssSelector($selector)) {
                    $rules[] = array(
                        'selector' => $selector,
                        'declarations' => $declarations,
                    );
                }
            }
        }

        return $rules;
    }

    /**
     * @return array<int, array{selector: string, pseudo: string, declarations: array<string, string>}>
     */
    private function staticPseudoElementStyleRules(string $html, string $linkedCss): array
    {
        $css = trim($linkedCss);
        if ( preg_match_all('@<style\b[^>]*>(.*?)</style>@is', $html, $matches) ) {
            $css .= ( '' === $css ? '' : "\n" ) . implode("\n", array_map('trim', $matches[1]));
        }

        if ( '' === trim($css) ) {
            return array();
        }

        $css = preg_replace('@/\*.*?\*/@s', '', $css) ?? $css;
        $css = $this->topLevelCssRules($css);
        $rules = array();
        if ( ! preg_match_all('/([^{}]+)\{([^{}]+)\}/', $css, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        foreach ( $matches as $match ) {
            $declarations = $this->safeVisualDeclarations($this->cssDeclarations((string) $match[2]));
            if ( array() === $declarations ) {
                continue;
            }

            foreach ( explode(',', (string) $match[1]) as $selector ) {
                $selector = trim($selector);
                if ( ! preg_match('/::?(before|after)\b/i', $selector, $pseudoMatch) ) {
                    continue;
                }

                $baseSelector = trim((string) preg_replace('/::?(?:before|after)\b/i', '', $selector));
                if ( '' !== $baseSelector && ! $this->selectorCarriesPseudoState($baseSelector) && $this->isSupportedCssSelector($baseSelector) ) {
                    $rules[] = array(
                        'selector'     => $baseSelector,
                        'pseudo'       => strtolower($pseudoMatch[1]),
                        'declarations' => $declarations,
                    );
                }
            }
        }

        return $rules;
    }

    private function topLevelCssRules(string $css): string
    {
        $output = '';
        $length = strlen($css);
        $depth = 0;

        for ( $offset = 0; $offset < $length; ++$offset ) {
            $char = $css[$offset];

            if ( '"' === $char || "'" === $char ) {
                $output .= $char;
                for ( ++$offset; $offset < $length; ++$offset ) {
                    $output .= $css[$offset];
                    if ( '\\' === $css[$offset] ) {
                        if ( $offset + 1 < $length ) {
                            ++$offset;
                            $output .= $css[$offset];
                        }
                        continue;
                    }
                    if ( $char === $css[$offset] ) {
                        break;
                    }
                }
                continue;
            }

            if ( 0 !== $depth || '@' !== $char ) {
                if ( '{' === $char ) {
                    ++$depth;
                } elseif ( '}' === $char && $depth > 0 ) {
                    --$depth;
                }
                $output .= $char;
                continue;
            }

            $blockStart = $this->findCssToken($css, '{', $offset);
            $statementEnd = $this->findCssToken($css, ';', $offset);
            if ( null === $blockStart || ( null !== $statementEnd && $statementEnd < $blockStart ) ) {
                if ( null === $statementEnd ) {
                    break;
                }
                $offset = $statementEnd;
                continue;
            }

            $atRuleDepth = 1;
            for ( $innerOffset = $blockStart + 1; $innerOffset < $length; ++$innerOffset ) {
                if ( '"' === $css[$innerOffset] || "'" === $css[$innerOffset] ) {
                    $quote = $css[$innerOffset];
                    for ( ++$innerOffset; $innerOffset < $length; ++$innerOffset ) {
                        if ( '\\' === $css[$innerOffset] ) {
                            ++$innerOffset;
                            continue;
                        }
                        if ( $quote === $css[$innerOffset] ) {
                            break;
                        }
                    }
                    continue;
                }
                if ( '{' === $css[$innerOffset] ) {
                    ++$atRuleDepth;
                    continue;
                }
                if ( '}' === $css[$innerOffset] ) {
                    --$atRuleDepth;
                    if ( 0 === $atRuleDepth ) {
                        $offset = $innerOffset;
                        continue 2;
                    }
                }
            }

            break;
        }

        return $output;
    }

    private function findCssToken(string $css, string $token, int $offset): ?int
    {
        $length = strlen($css);
        for ( ; $offset < $length; ++$offset ) {
            if ( '"' === $css[$offset] || "'" === $css[$offset] ) {
                $quote = $css[$offset];
                for ( ++$offset; $offset < $length; ++$offset ) {
                    if ( '\\' === $css[$offset] ) {
                        ++$offset;
                        continue;
                    }
                    if ( $quote === $css[$offset] ) {
                        break;
                    }
                }
                continue;
            }
            if ( $token === $css[$offset] ) {
                return $offset;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function safeVisualDeclarations(array $declarations): array
    {
        $safe = array_flip(array(
            'background',
            'background-attachment',
            'background-clip',
            'background-color',
            'background-image',
            'background-origin',
            'background-position',
            'background-repeat',
            'background-size',
            'aspect-ratio',
            'border',
            'border-color',
            'border-radius',
            'border-style',
            'border-width',
            'box-shadow',
            'color',
            'align-items',
            'column-gap',
            'display',
            'flex-direction',
            'flex-wrap',
            'font-family',
            'font-size',
            'font-style',
            'font-weight',
            'letter-spacing',
            'gap',
            'grid-template-columns',
            'grid-template-rows',
            'height',
            'justify-content',
            'line-height',
            'margin',
            'margin-bottom',
            'margin-left',
            'margin-right',
            'margin-top',
            'max-height',
            'max-width',
            'min-height',
            'min-width',
            'padding',
            'padding-bottom',
            'padding-left',
            'padding-right',
            'padding-top',
            'position',
            'row-gap',
            'text-align',
            'text-decoration',
            'text-transform',
            'width',
            'z-index',
        ));

        return array_intersect_key($declarations, $safe);
    }

    /**
     * @return array<string, string>
     */
    private function cssDeclarations(string $style): array
    {
        $declarations = array();
        foreach ( CssValueSplitter::splitTopLevel($style, array( ';' )) as $declaration ) {
            if ( ! str_contains($declaration, ':') ) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $declaration, 2));
            $name = strtolower($name);
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;
            $allowsImageUrl = in_array($name, array( 'background', 'background-image' ), true) && ! preg_match('/(?:expression\s*\(|javascript\s*:)/i', $value);
            if ( '' !== $name && '' !== $value && ( $allowsImageUrl || ! preg_match('/(?:expression\s*\(|javascript\s*:|url\s*\()/i', $value) ) ) {
                $declarations[$name] = $value;
            }
        }

        return $declarations;
    }

    /**
     * @param array<string, string> $declarations
     */
    private function cssDeclarationString(array $declarations): string
    {
        $parts = array();
        foreach ( $declarations as $name => $value ) {
            $parts[] = $name . ':' . $value;
        }

        return implode(';', $parts);
    }

    private function isSupportedCssSelector(string $selector): bool
    {
        return (bool) (CssSelectorMatcher::parse($selector)['supported'] ?? false);
    }

    private function matchesCssSelector(DOMElement $element, string $selector): bool
    {
        $match = CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($selector));
        return $match['supported'] && $match['matches'];
    }

    /**
     * Whether a selector targets a pseudo-state or pseudo-element rather than the
     * element's resting state. Such rules (`:hover`, `:focus`, `:active`,
     * `:visited`, `:focus-visible`, `:focus-within`, `::before`/`::after`, and the
     * single-colon legacy `:before`/`:after`) describe transient or generated
     * presentation. They must never be folded into an element's RESTING inline
     * style — they belong in the verbatim materialized stylesheet, where they fire
     * on real interaction. Selectors carrying one of these are excluded from the
     * inline-style resolution rule set entirely (not stripped-and-kept), so a
     * `.btn-primary:hover{background:#f0ac22}` rule no longer overrides the correct
     * resting `.btn-primary` declarations on the element.
     */
    private function selectorCarriesPseudoState(string $selector): bool
    {
        return 1 === preg_match('/:{1,2}(?:hover|focus-visible|focus-within|focus|active|visited|before|after)\b/i', $selector);
    }

    private function presentationClassName(string $className): string
    {
        $classes = preg_split('/\s+/', trim($className)) ?: array();
        $classes = array_filter($classes, static fn (string $class): bool => '' !== $class && ! self::isBehaviorHookClassName($class) && ! self::isGeneratedCoreClassName($class));

        return implode(' ', array_values(array_unique($classes)));
    }

    private static function isBehaviorHookClassName(string $className): bool
    {
        return 1 === preg_match('/^js(?:$|[-_:]|[A-Z])/', $className);
    }

    private static function isGeneratedCoreClassName(string $className): bool
    {
        return GeneratedGutenbergClassPolicy::isGeneratedClassName($className);
    }

    /**
     * @return array<string, string>
     */
    private function layoutAttribute(DOMElement $element, string $mergedStyle = ''): array
    {
        $declared = trim($this->attr($element, 'data-layout'));
        if ( '' === $declared ) {
            $declared = trim($this->attr($element, 'data-wp-layout'));
        }

        if ( '' !== $declared ) {
            $decoded = json_decode($declared, true);
            $type = is_array($decoded) ? (string) ($decoded['type'] ?? '') : $declared;
            if ( in_array($type, array( 'constrained', 'flex', 'flow', 'grid' ), true) ) {
                return array( 'type' => $type );
            }
        }

        $inlineStyle = strtolower($this->attr($element, 'style'));
        $mergedDeclarations = $this->cssDeclarations($mergedStyle);
        $inlineDeclarations = $this->cssDeclarations($inlineStyle);
        if ( preg_match('/(?:^|;)\s*display\s*:\s*(inline-)?flex\b/', $inlineStyle) ) {
            $layout = array( 'type' => 'flex' );
            // flex-direction: column / column-reverse is a vertical main axis. A
            // core/group flex layout defaults to a horizontal Row, so the
            // orientation must be made explicit or the children render
            // side-by-side instead of stacked. Row / row-reverse / default flex
            // keeps the implicit horizontal orientation.
            if ( preg_match('/(?:^|;)\s*flex-direction\s*:\s*column(?:-reverse)?\b/', $inlineStyle) ) {
                $layout['orientation'] = 'vertical';
            }
            $justifyContent = $this->layoutJustifyContent((string) ($inlineDeclarations['justify-content'] ?? $mergedDeclarations['justify-content'] ?? ''));
            if ( '' !== $justifyContent ) {
                $layout['justifyContent'] = $justifyContent;
            }
            $flexWrap = $this->layoutFlexWrap((string) ($inlineDeclarations['flex-wrap'] ?? $mergedDeclarations['flex-wrap'] ?? ''));
            if ( '' !== $flexWrap ) {
                $layout['flexWrap'] = $flexWrap;
            }

            return $layout;
        }
        $style = strtolower('' !== trim($mergedStyle) ? $mergedStyle : $this->attr($element, 'style'));
        if ( preg_match('/(?:^|;)\s*display\s*:\s*(inline-)?flex\b/', $style)
            && ! preg_match('/(?:^|;)\s*flex-direction\s*:\s*column(?:-reverse)?\b/', $style)
        ) {
            if ( ! preg_match('/(?:^|;)\s*display\s*:\s*(inline-)?flex\b/', $inlineStyle) && $this->hasOwnStyleHook($element) ) {
                return array();
            }

            return array( 'type' => 'flex' );
        }
        if ( preg_match('/(?:^|;)\s*display\s*:\s*(inline-)?grid\b/', $style) ) {
            if ( ! preg_match('/(?:^|;)\s*display\s*:\s*(inline-)?grid\b/', $inlineStyle) && preg_match('/(?:^|;)\s*grid-template-columns\s*:/', $style) ) {
                return array();
            }

            return array( 'type' => 'grid' );
        }

        $inlineOwnsLayout = false;
        foreach (array_keys($inlineDeclarations) as $property) {
            if ('layout' === $this->responsivePropertyFamily($property)) {
                $inlineOwnsLayout = true;
                break;
            }
        }
        if (! $inlineOwnsLayout && $this->hasConditionalStyleFamily($element, 'layout')) {
            return array();
        }

        // An explicit grid class token (`grid`, `grid-3`, `footer-grid`,
        // `card-grid`, …) is a deterministic CSS-grid signal on its own. When the
        // container holds more than one element child, emit grid layout so the
        // multi-column arrangement survives even when the children are plain
        // wrappers rather than recognized card markup. Without this the grid
        // collapses to a vertical stack and loses visual parity.
        if ( $this->hasExplicitGridClass($element) && 1 < $this->directElementChildCount($element) ) {
            return array( 'type' => 'grid' );
        }

        if ( $this->hasGridLikeClass($element) && 1 < $this->cardLikeChildCount($element) ) {
            return array( 'type' => 'grid' );
        }

        return array();
    }

    private function hasOwnStyleHook(DOMElement $element): bool
    {
        return '' !== trim($this->attr($element, 'class')) || '' !== trim($this->attr($element, 'id'));
    }

    private function layoutJustifyContent(string $value): string
    {
        $value = strtolower(trim($value));
        $map = array(
            'flex-start'    => 'left',
            'start'         => 'left',
            'left'          => 'left',
            'center'        => 'center',
            'flex-end'      => 'right',
            'end'           => 'right',
            'right'         => 'right',
            'space-between' => 'space-between',
        );

        return $map[ $value ] ?? '';
    }

    private function layoutFlexWrap(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, array( 'wrap', 'nowrap' ), true) ? $value : '';
    }

    /**
     * Unambiguous grid class tokens: a bare `grid`, a numbered `grid-N`, or any
     * `*-grid` / `*_grid` suffix (footer-grid, card-grid, mission-grid, …) plus
     * the common `grid-cols` / `grid-columns` utility names. These map directly to
     * `display:grid` containers, so they are safe to treat as grids regardless of
     * child semantics. Ambiguous semantic names (cards, features, …) stay gated on
     * card-like children via hasGridLikeClass().
     */
    private function hasExplicitGridClass(DOMElement $element): bool
    {
        $className = strtolower($this->attr($element, 'class'));
        return (bool) preg_match('/(?:^|[\s_-])(?:grid|grid-[0-9]+|grid-cols(?:-[0-9]+)?|grid-columns|[a-z0-9]+[-_]grid)(?:$|[\s_-])/', $className);
    }

    private function hasGridLikeClass(DOMElement $element): bool
    {
        $className = strtolower($this->attr($element, 'class'));
        return (bool) preg_match('/(?:^|[\s_-])(?:cards|features|services|providers|testimonials|resources|posts|projects|stats|badges|grid|grid-[0-9]+|tiles|collection|gallery)(?:$|[\s_-])/', $className);
    }
}
