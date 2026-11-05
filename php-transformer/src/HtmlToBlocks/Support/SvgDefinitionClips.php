<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMElement;

/** Non-painting definition carriers belong to CSS resources, never the block tree. */
final class SvgDefinitionClips
{
    public static function isCarrier(DOMElement $svg): bool
    {
        return SourceDom::isDefinitionsOnlySvg($svg)
            && preg_match('/^0(?:\.0+)?(?:px)?$/', trim($svg->getAttribute('width'))) === 1
            && preg_match('/^0(?:\.0+)?(?:px)?$/', trim($svg->getAttribute('height'))) === 1;
    }

    /** @return array<string, string> */
    public static function replacements(DOMElement $body): array
    {
        $result = array();
        foreach ($body->getElementsByTagName('svg') as $svg) {
            if (!self::isCarrier($svg) || !SvgMaterializer::isPassiveSvgMarkup($svg)) {
                continue;
            }
            // Retain the complete resource: shapes can reference sibling definitions.
            $markup = $svg->ownerDocument->saveHTML($svg);
            if (!$svg->hasAttribute('xmlns')) {
                $markup = str_replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ', $markup);
            }
            $markup = SvgMaterializer::restoreMarkupCasing($markup);
            foreach ($svg->getElementsByTagName('*') as $clip) {
                if ('' === $clip->getAttribute('id')) {
                    continue;
                }
                $id = $clip->getAttribute('id');
                $resource = 'url("data:image/svg+xml,' . rawurlencode($markup) . '#' . rawurlencode($id) . '")';
                $plainAncestors = true;
                for ($ancestor = $clip->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
                    foreach (array('class', 'style', 'transform', 'clip-rule') as $attribute) {
                        if ($ancestor->hasAttribute($attribute) && !($ancestor === $svg && 'style' === $attribute && preg_match('/^\s*position\s*:\s*absolute\s*;?\s*$/i', $ancestor->getAttribute('style')))) {
                            $plainAncestors = false;
                        }
                    }
                    if ($ancestor === $svg) break;
                }
                $result[$id] = ($plainAncestors && 'clippath' === strtolower($clip->tagName) ? self::native($clip) : null) ?? $resource;
                $result['@' . $id] = $resource;
            }
        }
        return $result;
    }

    private static function native(DOMElement $clip): ?string
    {
        // Transforms, clip rules, nested geometry and styling require SVG semantics.
        foreach ($clip->attributes as $attr) {
            if (!in_array(strtolower($attr->name), array('id', 'clippathunits'), true)) {
                return null;
            }
        }
        $children = array();
        foreach ($clip->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }
        if (1 !== count($children)) {
            return null;
        }
        $shape = $children[0];
        $tag = strtolower($shape->tagName);
        foreach ($shape->attributes as $attr) {
            if (!in_array(strtolower($attr->name), array('d', 'points', 'cx', 'cy', 'r', 'rx', 'ry'), true)) {
                return null;
            }
        }
        $bbox = 'objectBoundingBox' === $clip->getAttribute('clippathunits');
        if ($bbox && 'path' === $tag) {
            $ellipse = self::arcEllipse($shape->getAttribute('d'));
            if (null !== $ellipse) {
                return $ellipse;
            }
        }
        if ($bbox && in_array($tag, array('circle', 'ellipse'), true)) {
            $values = array();
            foreach (array('circle' === $tag ? 'r' : 'rx', 'circle' === $tag ? 'r' : 'ry', 'cx', 'cy') as $attr) {
                $value = $shape->getAttribute($attr) ?: '0';
                if (!is_numeric($value)) {
                    return null;
                }
                $values[] = (float) $value * 100 . '%';
            }
            // A circle in a non-square object box scales independently on both axes.
            return "ellipse({$values[0]} {$values[1]} at {$values[2]} {$values[3]})";
        }
        if ('polygon' === $tag && preg_match('/^[-+\d.eE,\s]+$/', $shape->getAttribute('points'))) {
            $numbers = preg_split('/[\s,]+/', trim($shape->getAttribute('points')));
            if (count($numbers) < 6 || count($numbers) % 2) {
                return null;
            }
            $points = array();
            for ($i = 0; $i < count($numbers); $i += 2) {
                if (!is_numeric($numbers[$i]) || !is_numeric($numbers[$i + 1])) {
                    return null;
                }
                $points[] = $bbox ? ((float) $numbers[$i] * 100) . '% ' . ((float) $numbers[$i + 1] * 100) . '%' : $numbers[$i] . 'px ' . $numbers[$i + 1] . 'px';
            }
            return 'polygon(' . implode(', ', $points) . ')';
        }
        if (!$bbox && 'path' === $tag && preg_match('/^[MmZzLlHhVvCcSsQqTtAa0-9eE+.,\s-]+$/', $shape->getAttribute('d'))) {
            return 'path("' . $shape->getAttribute('d') . '")';
        }
        return null;
    }

    /** Two axis-aligned half arcs, optionally separated by a redundant moveto. */
    private static function arcEllipse(string $d): ?string
    {
        $n = '([-+]?(?:\\d*\\.\\d+|\\d+\\.?\\d*)(?:[eE][-+]?\\d+)?)';
        $s = '[\\s,]*';
        $pair = $n . $s . $n;
        $arc = 'A' . $s . $pair . $s . $n . $s . '([01])' . $s . '([01])' . $s . $pair;
        if (!preg_match('/^\\s*M' . $s . $pair . $s . $arc . $s . '(?:M' . $s . $pair . $s . ')?' . $arc . $s . '[Zz]?\\s*$/', $d, $m)) {
            return null;
        }
        $v = array_map('floatval', array_slice($m, 1));
        // M x y; A rx ry rotation large sweep x y; optional M x y; A ...
        list($x, $y, $rx, $ry, $rotation, $large, $sweep, $endX, $endY) = $v;
        if ($rx <= 0 || $ry <= 0 || 0.0 !== $rotation
            || ($m[10] !== '' && ($v[9] !== $endX || $v[10] !== $endY))
            || array_slice($v, 11, 3) !== array($rx, $ry, 0.0)
            || $v[15] !== $sweep || $v[16] !== $x || $v[17] !== $y) {
            return null;
        }
        if (!(($y === $endY && abs($x - $endX) === 2 * $rx)
            || ($x === $endX && abs($y - $endY) === 2 * $ry))) {
            return null;
        }
        return 'ellipse(' . ($rx * 100) . '% ' . ($ry * 100) . '% at ' . (($x + $endX) * 50) . '% ' . (($y + $endY) * 50) . '%)';
    }

    /** Rewrite only local clip-path references, not strings or unrelated URL properties. */
    public static function rewrite(string $css, array $replacements): string
    {
        if (array() === $replacements || !str_contains($css, '#')) {
            return $css;
        }
        $parts = \Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter::splitTopLevel($css, array(';'));
        foreach ($parts as &$part) {
            $part = preg_replace_callback('/^(\s*(clip-path|mask|mask-image|filter|fill|stroke)\s*:\s*)url\(\s*([\'"]?)#([^\s\'"()]+)\3\s*\)(\s*(?:!\s*important)?\s*)$/i', static function (array $m) use ($replacements): string {
                $key = ('clip-path' === strtolower($m[2]) ? '' : '@') . $m[4];
                return isset($replacements[$key]) ? $m[1] . $replacements[$key] . $m[5] : $m[0];
            }, $part) ?? $part;
        }
        return implode(';', $parts);
    }
}
