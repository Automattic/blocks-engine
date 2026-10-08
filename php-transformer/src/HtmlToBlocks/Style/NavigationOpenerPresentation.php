<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer;
use Closure;
use DOMElement;

/** Source control -> Core's dynamic open button; no saved-markup divergence. */
final class NavigationOpenerPresentation
{
    public const METADATA_KEY = 'blocksEngineNavigationOpener';

    public function __construct(private readonly StyleResolver $styles, private readonly ?Closure $svgMarkup = null) {}

    /** @return array{artwork:string,button:array<string,array<string,string>>,icon:array<string,array<string,string>>}|null */
    public function source(DOMElement $control): ?array
    {
        // A single passive SVG is a proven intrinsic-size visual control.
        // Mixed labels, multiple icons or document-dependent artwork cannot
        // justify replacing the complete native button content.
        if (null === $this->svgMarkup || '' !== trim($control->textContent ?? '')) return null;
        $children = array_values(array_filter(iterator_to_array($control->childNodes), static fn($child): bool => $child instanceof DOMElement));
        if (1 !== count($children) || 'svg' !== strtolower($children[0]->tagName)) return null;
        $svg = $children[0];
        if (!SvgMaterializer::isPassiveSvgMarkup($svg) || !SourceDom::svgHasDrawableContent($svg)) return null;
        foreach ($svg->getElementsByTagName('*') as $node) {
            if (!in_array(strtolower($node->tagName), array('g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon'), true)) return null;
        }
        $markup = ($this->svgMarkup)($svg);
        if ('' === $markup || !SourceDom::isSafeSvgContent($markup)) return null;
        $box = array('box-sizing', 'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'margin-inline', 'margin-inline-start', 'margin-inline-end', 'border', 'border-width', 'border-style', 'border-color', 'border-radius', 'background-color', 'color', 'display', 'align-items', 'justify-content', 'transform', 'transform-origin', 'translate', 'rotate', 'scale');
        $button = $this->styles->authoredControlPresentation($control, $box);
        $paint = array('fill', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin');
        $icon = $this->styles->authoredControlPresentation($svg, array_merge($box, $paint));
        $icon[''] ??= array();
        foreach (array('width', 'height') as $property) {
            if (!isset($icon[''][$property])) {
                $value = trim($svg->getAttribute($property));
                if (is_numeric($value)) $value .= 'px';
                if (!preg_match('/^\d+(?:\.\d+)?(?:px|em|rem)$/D', $value)) return null;
                $icon[''][$property] = $value;
            }
        }
        // Core's icon stylesheet sets fill:currentColor. That is not the SVG
        // initial fill and must not override authored presentation attributes.
        foreach ($paint as $property) {
            if (!isset($icon[''][$property]) && $svg->hasAttribute($property)) $icon[''][$property] = $svg->getAttribute($property);
        }
        $icon['']['fill'] ??= 'black';
        $icon['']['stroke'] ??= 'none';
        return array('artwork' => $markup, 'button' => $button, 'icon' => $icon);
    }

    /** @param array<string,array<string,string>> $rules */
    public function css(string $selector, array $rules, DOMElement $source): string
    {
        $css = '';
        foreach ($rules as $condition => $values) {
            $parts = array();
            foreach ($values as $property => $value) {
                $value = $this->styles->resolveCssVariablesInValue(CssValueInspector::withoutImportant($value), $source);
                if ('' === $value || preg_match('/[{}<>;]|var\(|url\(/i', $value)) continue;
                $safeValues = $this->styles->safeVisualDeclarations(array($property => $value));
                // Resting classification deliberately omits transforms and
                // logical box sides; a replaced control owns those families.
                if (in_array($property, array('box-sizing', 'transform', 'transform-origin', 'translate', 'rotate', 'scale', 'margin-inline', 'margin-inline-start', 'margin-inline-end', 'fill', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin'), true)) $safeValues[$property] = $value;
                foreach ($safeValues as $name => $safe) $parts[] = $name . ':' . $safe . '!important';
            }
            if (array() === $parts) continue;
            $rule = $selector . '{' . implode(';', $parts) . '}';
            $css .= '' === $condition ? $rule : $condition . '{' . $rule . str_repeat('}', substr_count($condition, '{') + 1);
        }
        return $css;
    }
}
