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

    /** Whether the control's whole visible content is text, with no artwork. */
    public static function isTextOnly(DOMElement $control): bool
    {
        if ('' === trim($control->textContent ?? '')) return false;
        foreach ($control->getElementsByTagName('*') as $node) {
            if (in_array(strtolower($node->tagName), array('svg', 'img', 'picture', 'i', 'canvas', 'video', 'object', 'embed'), true)) return false;
        }
        // A hamburger drawn as a character — U+2630 (☰), or its U+2261 (≡)
        // stand-in, optionally with a variation selector — is an icon-only
        // opener, not a text label: Core keeps its own icon instead of
        // rendering a "Menu" word the source never showed.
        $glyphs = preg_replace('/[\s\x{FE0E}\x{FE0F}]/u', '', $control->textContent ?? '');
        if ('' === $glyphs) return false;
        return !preg_match('/^[\x{2630}\x{2261}]+$/u', $glyphs);
    }

    /** Select the single icon visible in the source's resting mobile state. */
    public function icon(DOMElement $control): ?DOMElement
    {
        $icon = null;
        foreach ($control->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                if ('' !== trim($child->textContent ?? '')) return null;
                continue;
            }
            $display = $this->styles->collapsedViewportAuthorDeclarations($child, array('display'));
            if ('none' === CssValueInspector::withoutImportant($display['display'] ?? '')) continue;
            if ('svg' !== strtolower($child->tagName) || null !== $icon) return null;
            $icon = $child;
        }
        return $icon;
    }

    /** @return array{artwork:string,button:array<string,array<string,string>>,icon:array<string,array<string,string>>}|null */
    public function source(DOMElement $control): ?array
    {
        // One visible passive SVG is a proven visual control. Accessible
        // titles and a hidden alternate state icon do not make it mixed
        // content; visible labels or document-dependent artwork still do.
        if (null === $this->svgMarkup) return null;
        $svg = $this->icon($control);
        if (!$svg instanceof DOMElement) return null;
        if (!SvgMaterializer::isPassiveSvgMarkup($svg) || !SourceDom::svgHasDrawableContent($svg)) return null;
        foreach ($svg->getElementsByTagName('*') as $node) {
            if (!in_array(strtolower($node->tagName), array('title', 'desc', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon'), true)) return null;
        }
        $markup = ($this->svgMarkup)($svg);
        if ('' === $markup || !SourceDom::isSafeSvgContent($markup)) return null;
        $box = array('box-sizing', 'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'margin-inline', 'margin-inline-start', 'margin-inline-end', 'border', 'border-width', 'border-style', 'border-color', 'border-radius', 'background', 'background-color', 'color', 'display', 'align-items', 'justify-content', 'transform', 'transform-origin', 'translate', 'rotate', 'scale');
        $button = $this->styles->authoredControlPresentation($control, $box);
        $paint = array('fill', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin');
        $icon = $this->styles->authoredControlPresentation($svg, array_merge($box, $paint));
        $icon[''] ??= array();
        // An unsized SVG in a definite-width control fills the control's
        // content box and takes its height from the viewBox ratio.
        $declared = fn (string $property): bool => isset($icon[''][$property]) || '' !== trim($svg->getAttribute($property))
            || isset($this->styles->collapsedViewportAuthorDeclarations($svg, array($property))[$property]);
        $definiteControl = array_filter($button, static fn (array $values): bool => (bool) preg_match('/^\d+(?:\.\d+)?(?:px|em|rem)$/D', CssValueInspector::withoutImportant($values['width'] ?? '')));
        if (!$declared('width') && !$declared('height') && '' !== trim($svg->getAttribute('viewBox') ?: $svg->getAttribute('viewbox')) && array() !== $definiteControl) {
            $icon['']['width'] = '100%';
            $icon['']['height'] = 'auto';
        }
        foreach (array('width', 'height') as $property) {
            if (!isset($icon[''][$property])) {
                $collapsed = $this->styles->collapsedViewportAuthorDeclarations($svg, array($property));
                $value = CssValueInspector::withoutImportant(trim($collapsed[$property] ?? $svg->getAttribute($property)));
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
