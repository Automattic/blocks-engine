<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

/** Restates a source-proved monochrome glyph on core's immutable icon slot. */
final class DisclosureIconPresentation
{
    public function __construct(private readonly StyleResolver $styles, private readonly Closure $sanitizeSvg) {}

    /** @return array{closed:string,open:string}|array{} */
    public function rules(DOMElement $control): array
    {
        $icons = $control->getElementsByTagName('svg');
        if (1 !== $icons->length) return array();
        $icon = $icons->item(0);
        if (!$icon instanceof DOMElement || !SourceDom::svgHasDrawableContent($icon)) return array();
        $classPair = $icon->hasAttribute('data-dla-disclosure-open-class') && $icon->hasAttribute('data-dla-disclosure-closed-class');
        $stylePair = $icon->hasAttribute('data-dla-disclosure-open-style') && $icon->hasAttribute('data-dla-disclosure-closed-style');
        if (!$classPair && !$stylePair) return array();
        if ($classPair && $icon->getAttribute('class') !== $icon->getAttribute('data-dla-disclosure-closed-class')) return array();
        if ($stylePair && $icon->getAttribute('style') !== $icon->getAttribute('data-dla-disclosure-closed-style')) return array();
        // A mask retains a currentColor vector exactly, but cannot retain a
        // multi-paint image. Those sources remain on the existing native path.
        foreach (array_merge(array($icon), iterator_to_array($icon->getElementsByTagName('*'))) as $node) {
            if ('text' === strtolower($node->tagName)) return array();
            foreach (array('fill', 'stroke') as $property) {
                $value = strtolower(trim($node->getAttribute($property)));
                if ('' !== $value && !in_array($value, array('none', 'currentcolor', 'inherit'), true)) return array();
            }
        }
        $drawing = $icon->cloneNode(true);
        if (!$drawing instanceof DOMElement) return array();
        $drawing->removeAttribute('class');
        // The state transform/opacity/box are carried on the native slot, not
        // inside its image as well. Retain only intrinsic vector paint here.
        $paint = array_intersect_key($this->styles->cssDeclarations($icon->getAttribute('style')), array_flip(array('fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'fill-rule', 'clip-rule')));
        $drawing->removeAttribute('style');
        if ($paint) $drawing->setAttribute('style', $this->styles->cssDeclarationString($paint));
        $markup = ($this->sanitizeSvg)($drawing);
        if (!SourceDom::isSafeSvgContent($markup)) return array();
        $closed = $this->state($icon, false);
        $open = $this->state($icon, true);
        if (!$closed || !$open || !$this->length($closed['width'] ?? '') || !$this->length($closed['height'] ?? '')) return array();
        $mask = 'url("data:image/svg+xml;base64,' . base64_encode($markup) . '") center/contain no-repeat';
        $base = array('display' => 'block', 'flex-shrink' => '0', 'font-size' => '0', 'line-height' => '0', 'mask' => $mask, '-webkit-mask' => $mask);
        foreach (array('closed' => $closed, 'open' => $open) as $state => $declarations) {
            $declarations['background-color'] = $declarations['color'] ?? 'currentColor';
            $rules[$state] = $this->styles->cssDeclarationString(array_merge($base, $declarations));
        }
        return $rules;
    }

    /** @return array<string,string> */
    private function state(DOMElement $icon, bool $open): array
    {
        $clone = $icon->cloneNode(true);
        if (!$clone instanceof DOMElement || !$icon->parentNode) return array();
        if ($open) {
            if ($icon->hasAttribute('data-dla-disclosure-open-class')) $clone->setAttribute('class', $icon->getAttribute('data-dla-disclosure-open-class'));
            if ($icon->hasAttribute('data-dla-disclosure-open-style')) $clone->setAttribute('style', $icon->getAttribute('data-dla-disclosure-open-style'));
        }
        // A fresh node keeps selector caches honest. It occupies the source's
        // exact slot while resolving, so sibling selectors still mean the same.
        $parent = $icon->parentNode;
        $parent->replaceChild($clone, $icon);
        try {
            $declarations = $this->styles->resolvedPresentationDeclarations($clone);
            foreach (array('fill', 'stroke') as $paint) {
                if (isset($declarations[$paint]) && !in_array(strtolower(trim($declarations[$paint])), array('none', 'currentcolor', 'inherit'), true)) return array();
            }
            $result = array_intersect_key($declarations, array_flip(array('width', 'height', 'color', 'opacity', 'transform', 'transform-origin', 'rotate', 'scale', 'translate', 'transition-property', 'transition-duration', 'transition-timing-function', 'transition-delay')));
            foreach (array('width', 'height') as $dimension) {
                if (!isset($result[$dimension])) {
                    $value = $icon->getAttribute($dimension);
                    $result[$dimension] = is_numeric($value) ? $value . 'px' : $value;
                }
            }
            if (!isset($result['color']) || in_array(strtolower(trim($result['color'])), array('inherit', 'unset'), true)) {
                $result['color'] = $this->styles->authoredInheritedPropertyWinner($clone, 'color') ?: 'currentColor';
            }
            $result['transform'] ??= 'none';
            return $result;
        } finally {
            $parent->replaceChild($icon, $clone);
        }
    }

    private function length(string $value): bool
    {
        return 1 === preg_match('/^(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:px|em|rem|%)$/', trim($value));
    }
}
