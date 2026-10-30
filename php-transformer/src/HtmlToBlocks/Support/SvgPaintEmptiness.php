<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMElement;

/** A proof of empty paint, not a general SVG renderer. Unknowns always survive. */
final class SvgPaintEmptiness
{
    public function __construct(private readonly StyleResolver $styles) {}

    public function paintsNothing(DOMElement $svg): bool
    {
        if (SourceDom::isDefinitionsOnlySvg($svg) || !SourceDom::svgHasDrawableContent($svg)) return false;
        // An in-flow box still reserves layout space even when it paints nothing.
        if (!$this->outOfFlow($svg)) return false;
        $nodes = array($svg, ...iterator_to_array($svg->getElementsByTagName('*')));
        $ids = array();
        foreach ($nodes as $node) {
            $id = $node->getAttribute('id');
            if ('' !== $id) {
                if (isset($ids[$id])) return false;
                $ids[$id] = $node;
            }
            // Do not let an empty ancestor mask hide uncertain rendering effects.
            if (!in_array(strtolower($node->tagName), array('svg', 'g', 'a', 'defs', 'mask', 'clippath', 'title', 'desc', 'metadata', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'image'), true)) return false;
            $declarations = $this->declarations($node);
            foreach ($declarations as $property => $value) {
                if (preg_match('/var\s*\(/i', $value)) return false;
                if (('filter' === $property || str_starts_with($property, 'animation') || str_starts_with($property, 'transition')) && !in_array($value, array('', 'none', '0s'), true)) return false;
            }
            foreach ($node->attributes as $attribute) {
                if (str_starts_with(strtolower($attribute->name), 'on')) return false;
            }
        }
        $inherited = array();
        $ancestors = array();
        for ($parent = $svg->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) array_unshift($ancestors, $parent);
        foreach ($ancestors as $ancestor) {
            $inherited = array_merge($inherited, array_intersect_key($this->declarations($ancestor), array_flip(array('fill', 'fill-opacity', 'stroke', 'stroke-opacity', 'stroke-width'))));
        }
        return $this->emptyNode($svg, $ids, $inherited, array());
    }

    private function outOfFlow(DOMElement $svg): bool
    {
        $declarations = array_merge(
            $this->styles->cssDeclarations($svg->getAttribute('style')),
            $this->styles->matchedCascadedDeclarations($svg),
            $this->styles->referenceViewportAuthorDeclarations($svg, array('position'))
        );
        $position = strtolower(trim(preg_replace('/\s*!important\s*$/i', '', $declarations['position'] ?? '') ?? ''));
        return in_array($position, array('absolute', 'fixed'), true);
    }

    /** Presentation attributes are lower priority than the author cascade. */
    private function declarations(DOMElement $node): array
    {
        $result = array();
        foreach (array('fill', 'fill-opacity', 'stroke', 'stroke-opacity', 'stroke-width', 'opacity', 'mask', 'filter') as $property) {
            if ($node->hasAttribute($property)) $result[$property] = $node->getAttribute($property);
        }
        $result = array_merge($result, $this->styles->cssDeclarations($node->getAttribute('style')), $this->styles->matchedCascadedDeclarations($node));
        // URL-valued effects are deliberately absent from the paint cascade.
        // Query the existing full author resolver rather than treating absence
        // there as proof that a filter or CSS mask does not exist.
        $result = array_merge($result, $this->styles->referenceViewportAuthorDeclarations($node, array('filter', 'mask', 'transition', 'animation')));
        foreach ($result as $property => $value) {
            $value = trim(preg_replace('/\s*!important\s*$/i', '', $value) ?? $value);
            $result[$property] = 'mask' === $property ? $value : strtolower($value);
        }
        return $result;
    }

    private function zero(string $value): bool
    {
        return 1 === preg_match('/^[+-]?0+(?:\.0+)?(?:px|em|rem|%|pt|cm|mm|in)?$/', $value);
    }

    private function transparent(string $value): bool
    {
        return in_array($value, array('none', 'transparent', '#0000', '#00000000'), true)
            || 1 === preg_match('/^rgba\([^()]+,\s*0(?:\.0+)?\s*\)$/', $value);
    }

    private function emptyNode(DOMElement $node, array $ids, array $inherited, array $visiting): bool
    {
        $tag = strtolower($node->tagName);
        $own = $this->declarations($node);
        $paint = array_merge($inherited, array_intersect_key($own, array_flip(array('fill', 'fill-opacity', 'stroke', 'stroke-opacity', 'stroke-width'))));
        if ($this->zero($own['opacity'] ?? '1')) return true;
        $mask = $own['mask'] ?? 'none';
        if ('none' !== $mask) {
            if (1 !== preg_match('/^url\([\'\"]?#([^\s\'\"()]+)[\'\"]?\)$/', $mask, $match)) return false;
            $id = $match[1];
            if (!isset($ids[$id]) || isset($visiting[$id]) || 'mask' !== strtolower($ids[$id]->tagName)) return false;
            $visiting[$id] = true;
            // Mask paint inherits from its definition ancestry, not its consumer.
            $maskPaint = array();
            $ancestors = array();
            for ($parent = $ids[$id]->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) array_unshift($ancestors, $parent);
            foreach ($ancestors as $ancestor) $maskPaint = array_merge($maskPaint, array_intersect_key($this->declarations($ancestor), array_flip(array('fill', 'fill-opacity', 'stroke', 'stroke-opacity', 'stroke-width'))));
            if ($this->emptyNode($ids[$id], $ids, $maskPaint, $visiting)) return true;
        }
        if (in_array($tag, array('svg', 'g', 'a', 'mask'), true)) {
            foreach ($node->childNodes as $child) {
                if (!$child instanceof DOMElement || in_array(strtolower($child->tagName), array('defs', 'mask', 'clippath', 'title', 'desc', 'metadata'), true)) continue;
                if (!$this->emptyNode($child, $ids, $paint, $visiting)) return false;
            }
            return true;
        }
        if ('image' === $tag) return false;
        $fillEmpty = $this->transparent($paint['fill'] ?? 'black') || $this->zero($paint['fill-opacity'] ?? '1');
        $strokeEmpty = $this->transparent($paint['stroke'] ?? 'none') || $this->zero($paint['stroke-opacity'] ?? '1') || $this->zero($paint['stroke-width'] ?? '1');
        return $fillEmpty && $strokeEmpty;
    }
}
