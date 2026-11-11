<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMElement;
use DOMNode;

/** Lower only definition carriers whose clips all have exact native equivalents. */
final class SvgDefinitionClips
{
    public static function isCarrier(DOMElement $svg): bool
    {
        return array() !== self::carrierReplacements($svg);
    }

    /** @return array<string, string> */
    public static function replacements(DOMElement $body): array
    {
        $result = array();
        foreach ($body->getElementsByTagName('svg') as $svg) {
            $result = array_replace($result, self::carrierReplacements($svg));
        }
        return $result;
    }

    /** Rewrite inline `style` references to definition-only SVG clips under $body. */
    public static function rewriteInlineStyles(DOMElement $body): void
    {
        $replacements = self::replacements($body);
        if ( array() === $replacements ) {
            return;
        }
        foreach ( $body->getElementsByTagName('*') as $element ) {
            if ( $element->hasAttribute('style') ) {
                $element->setAttribute('style', self::rewrite($element->getAttribute('style'), $replacements));
            }
        }
    }

    /** All-or-nothing: unsupported definitions must keep their in-document carrier. */
    private static function carrierReplacements(DOMElement $svg): array
    {
        if (!SourceDom::isDefinitionsOnlySvg($svg)
            || preg_match('/^0(?:\.0+)?(?:px)?$/', trim($svg->getAttribute('width'))) !== 1
            || preg_match('/^0(?:\.0+)?(?:px)?$/', trim($svg->getAttribute('height'))) !== 1) {
            return array();
        }
        foreach ($svg->attributes as $attr) {
            $name = strtolower($attr->name);
            // Carrier metadata does not change the geometry of its definitions.
            if (str_starts_with($name, 'data-') || str_starts_with($name, 'xmlns')
                || in_array($name, array('aria-hidden', 'focusable', 'id', 'class'), true)
                || ('role' === $name && in_array(strtolower(trim($attr->value)), array('presentation', 'none'), true))) {
                continue;
            }
            if (!in_array($name, array('width', 'height', 'style'), true)
                || ('style' === $name && !preg_match('/^\s*position\s*:\s*absolute\s*;?\s*$/i', $attr->value))) {
                return array();
            }
        }
        $result = array();
        foreach ($svg->childNodes as $defs) {
            if (self::isNeutralNode($defs)) {
                continue;
            }
            if (!$defs instanceof DOMElement) {
                return array();
            }
            if (in_array(strtolower($defs->tagName), array('title', 'desc', 'metadata'), true)) {
                continue;
            }
            if ($defs->attributes->length > 0) {
                return array();
            }
            foreach ($defs->childNodes as $clip) {
                if (self::isNeutralNode($clip)) {
                    continue;
                }
                if (!$clip instanceof DOMElement) {
                    return array();
                }
                if ('clippath' !== strtolower($clip->tagName) || '' === $clip->getAttribute('id')
                    || null === ($native = self::native($clip))) {
                    return array();
                }
                $result[$clip->getAttribute('id')] = $native;
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
            if (self::isNeutralNode($child)) {
                continue;
            }
            if (!$child instanceof DOMElement) {
                return null;
            }
            $children[] = $child;
        }
        if (1 !== count($children)) {
            return null;
        }
        $shape = $children[0];
        foreach ($shape->childNodes as $child) {
            if (!self::isNeutralNode($child)) {
                return null;
            }
        }
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
                if (in_array($attr, array('r', 'rx', 'ry'), true) && (float) $value <= 0) {
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
        return null;
    }

    /** Source formatting and comments do not change clip geometry. */
    private static function isNeutralNode(DOMNode $node): bool
    {
        return XML_COMMENT_NODE === $node->nodeType
            || (XML_TEXT_NODE === $node->nodeType && '' === trim($node->textContent ?? ''));
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
            $part = preg_replace_callback('/^(\s*(clip-path)\s*:\s*)url\(\s*([\'"]?)#([^\s\'"()]+)\3\s*\)(\s*(?:!\s*important)?\s*)$/i', static function (array $m) use ($replacements): string {
                $key = $m[4];
                return isset($replacements[$key]) ? $m[1] . $replacements[$key] . $m[5] : $m[0];
            }, $part) ?? $part;
        }
        return implode(';', $parts);
    }
}
