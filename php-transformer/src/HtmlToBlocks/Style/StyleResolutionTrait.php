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

    private function resetPresentationResolutionCache(): void
    {
        $this->presentationAttributesCache = array();
        $this->presentationDeclarationsCache = array();
        $this->mergedPresentationStyleCache = array();
    }

    private function styleAttributeMapper(): StyleAttributeMapper
    {
        return $this->styleAttributeMapper ??= new StyleAttributeMapper();
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
    private function presentationAttributes(DOMElement $element): array
    {
        $cacheKey = $this->presentationCacheKey($element);
        if ( isset($this->presentationAttributesCache[$cacheKey]) ) {
            return $this->presentationAttributesCache[$cacheKey];
        }

        $declarations = $this->presentationDeclarations($element);
        $mapped       = $this->styleAttributeMapper()->map($declarations);

        $attrs = array_filter(array_merge($mapped['attrs'] ?? array(), array(
            'anchor'    => $this->safeAnchor($this->attr($element, 'id')),
            'className' => $this->promotedClassName($this->attr($element, 'class')),
            'style'     => $mapped['style'],
            'layout'    => $this->layoutAttribute($element, $this->cssDeclarationString($declarations)),
        )), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));

        $this->presentationAttributesCache[$cacheKey] = $attrs;

        return $attrs;
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
        $tagName = strtolower($element->tagName);
        if ( in_array($tagName, array( 'button', 'header', 'footer', 'main', 'nav', 'article', 'aside', 'section', 'svg' ), true) ) {
            return true;
        }

        if ( 'li' === $tagName && $this->hasMultipleStyledInlineChildren($element) ) {
            return true;
        }

        $tokens = strtolower(trim(implode(' ', array(
            $this->attr($element, 'class'),
            $this->attr($element, 'id'),
            $this->attr($element, 'role'),
        ))));

        if ( preg_match('/(?:^|[^a-z0-9])(?:btn|button|cta|action|nav|menu|logo|brand|branding|cards?|tile|panel|pricing|price|product|grid|columns|layout|stack|cluster|row|wrap|hero|masthead|banner|badge|chip|pill|status|indicator|marker|dot|orb|media|image|photo|gallery|cover|thumb|thumbnail|art|artwork|illustration)(?:[^a-z0-9]|$)/', $tokens) ) {
            return true;
        }

        if ( 'a' === $tagName ) {
            for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
                $ancestorTokens = strtolower($this->attr($node, 'class') . ' ' . $this->attr($node, 'id'));
                if ( preg_match('/(?:^|[^a-z0-9])(?:actions?|btns?|buttons?|cta|nav|menu|card|tile|panel|pricing|product)(?:[^a-z0-9]|$)/', $ancestorTokens) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasMultipleStyledInlineChildren(DOMElement $element): bool
    {
        $styledInlineChildren = 0;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'br' !== $tagName && ! $this->isInlineContentElement($tagName) ) {
                continue;
            }

            if ( '' !== trim($this->attr($child, 'class')) || '' !== trim($this->attr($child, 'style')) ) {
                ++$styledInlineChildren;
            }
        }

        return $styledInlineChildren >= 2;
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

        return array_slice($rules, 0, 200);
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

        return array_slice($rules, 0, 200);
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
            'background-color',
            'background-image',
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
            'font-size',
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
            'row-gap',
            'text-align',
            'text-decoration',
            'text-transform',
            'width',
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
        $selector = $this->normalizeCssSelector($selector);
        if ( '' === $selector || preg_match('/[>+~\[\]=]/', $selector) ) {
            return false;
        }

        foreach ( preg_split('/\s+/', $selector) ?: array() as $part ) {
            if ( ! preg_match('/^(?:[a-z][a-z0-9_-]*)?(?:\.[A-Za-z0-9_-]+)+$|^\.[A-Za-z0-9_-]+$|^[a-z][a-z0-9_-]*$/i', $part) ) {
                return false;
            }
        }

        return true;
    }

    private function matchesCssSelector(DOMElement $element, string $selector): bool
    {
        $parts = preg_split('/\s+/', $this->normalizeCssSelector($selector)) ?: array();
        if ( array() === $parts ) {
            return false;
        }

        if ( ! $this->matchesCssSelectorPart($element, $parts[count($parts) - 1]) ) {
            return false;
        }

        $current = $element->parentNode instanceof DOMElement ? $element->parentNode : null;
        for ( $index = count($parts) - 2; $index >= 0; --$index ) {
            $matched = false;
            for ( $node = $current; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null ) {
                if ( $this->matchesCssSelectorPart($node, $parts[$index]) ) {
                    $matched = true;
                    $current = $node->parentNode instanceof DOMElement ? $node->parentNode : null;
                    break;
                }
            }
            if ( ! $matched ) {
                return false;
            }
        }

        return true;
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

    private function normalizeCssSelector(string $selector): string
    {
        return trim(preg_replace('/:(?:hover|focus|active|visited|before|after|focus-visible)\b.*/', '', $selector) ?? $selector);
    }

    private function matchesCssSelectorPart(DOMElement $element, string $selector): bool
    {
        if ( ! preg_match('/^(?:(?<tag>[a-z][a-z0-9_-]*))?(?<classes>(?:\.[A-Za-z0-9_-]+)*)$/i', $selector, $match) ) {
            return false;
        }

        if ( empty($match['tag']) && empty($match['classes']) ) {
            return false;
        }

        if ( ! empty($match['tag']) && strtolower($match['tag']) !== strtolower($element->tagName) ) {
            return false;
        }

        $classes = array_values(array_filter(preg_split('/\./', ltrim((string) ($match['classes'] ?? ''), '.')) ?: array(), static fn (string $class): bool => '' !== $class));
        $elementClasses = preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array();
        foreach ( $classes as $class ) {
            if ( ! in_array($class, $elementClasses, true) ) {
                return false;
            }
        }

        return true;
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
