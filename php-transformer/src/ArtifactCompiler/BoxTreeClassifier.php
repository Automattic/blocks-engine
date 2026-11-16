<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use DOMElement;

/** Report-only ADR 0005 classification. Never authorizes a conversion rewrite. */
final class BoxTreeClassifier
{
    public static function report(string $html, array $tree): array
    {
        $dom = HtmlSerialization::parseDocument($html);
        $elements = $parents = array();
        foreach ($dom->getElementsByTagName('*') as $element) {
            $id = $element->getAttribute(BoxTree::NODE_ATTRIBUTE);
            // Missing evidence must not invent source identities.
            if (!$tree) continue;
            $elements[$id] = $element;
            $parents[$id] = $element->parentNode instanceof DOMElement ? $element->parentNode->getAttribute(BoxTree::NODE_ATTRIBUTE) : null;
        }
        $containing = array();
        foreach ($tree['viewports'] ?? array() as $viewport => $_) {
            $absolute = $fixed = array();
            foreach ($elements as $id => $element) {
                $parent = $parents[$id];
                $a = $absolute[$parent] ?? null;
                $f = $fixed[$parent] ?? null;
                $styles = $tree['nodes'][$id]['viewports'][$viewport]['used_styles'];
                if ('absolute' === $styles['position'] && null !== $a) $containing[$a] = true;
                if ('fixed' === $styles['position'] && null !== $f) $containing[$f] = true;
                $establishes = 'none' !== $styles['transform'] || 'none' !== $styles['filter'];
                $absolute[$id] = $establishes || 'static' !== $styles['position'] ? $id : $a;
                $fixed[$id] = $establishes ? $id : $f;
            }
        }
        $nodes = array();
        $counts = array('load-bearing' => 0, 'neutral' => 0);
        foreach ($elements as $id => $element) {
            $node = $tree['nodes'][$id];
            $criterion = self::criterion($element, $node, $tree, isset($containing[$id]));
            $disposition = 'equal-child-geometry' === $criterion ? 'neutral' : 'load-bearing';
            $nodes[$id] = array('disposition' => $disposition, 'criterion' => $criterion);
            ++$counts[$disposition];
        }
        // Source-box projection, not a forecast of pattern expansion in emitted blocks.
        $depths = $inBody = array();
        $depth = $groups = $leaves = 0;
        foreach ($elements as $id => $element) {
            $inBody[$id] = 'body' === strtolower($element->tagName) || ($inBody[$parents[$id]] ?? false);
            if (!$inBody[$id] || 'body' === strtolower($element->tagName)) continue;
            $kept = 'load-bearing' === $nodes[$id]['disposition'];
            $depths[$id] = ($depths[$parents[$id]] ?? 0) + ($kept ? 1 : 0);
            $depth = max($depth, $depths[$id]);
            if (!$tree['nodes'][$id]['children']) ++$leaves;
            elseif ($kept) ++$groups;
        }
        return array('counts' => $counts, 'nodes' => $nodes, 'projection' => $tree ? array(
            'basis' => 'body-source-boxes', 'depth' => $depth, 'groups' => $groups,
            'leaves' => $leaves, 'groups_per_leaf' => $leaves ? $groups / $leaves : 0.0,
        ) : null);
    }

    private static function criterion(DOMElement $element, array $node, array $tree, bool $containing): string
    {
        foreach ($node['viewports'] as $observation) {
            $s = $observation['used_styles'];
            if (self::paints($s)) return 'paints';
        }
        foreach ($node['viewports'] as $observation) {
            if (count($node['children']) > 1 && in_array($observation['used_styles']['display'], array('flex', 'inline-flex', 'grid', 'inline-grid'), true)) return 'multi-child-layout';
        }
        if ($containing) return 'containing-block';
        if (!in_array(strtolower($element->tagName), array('div', 'span'), true) || $element->hasAttribute('id') || $element->hasAttribute('role') || $element->hasAttribute('name')) return 'semantics/id/anchor';
        foreach ($element->attributes as $attribute) {
            if (str_starts_with($attribute->name, 'on') || str_starts_with($attribute->name, 'aria-') || in_array($attribute->name, array('tabindex', 'contenteditable', 'draggable', 'hidden', 'inert'), true)) return 'interaction/state';
        }
        if (!empty($node['interaction_states'])) return 'interaction/state';
        foreach ($node['viewports'] as $observation) {
            $s = $observation['used_styles'];
            if (isset($s['animation-name']) && 'none' !== $s['animation-name']) return 'motion';
            if (isset($s['transition-duration']) && preg_match('/[1-9]/', $s['transition-duration']) && 'none' !== ($s['transition-property'] ?? 'all')) return 'motion';
        }
        if (1 !== count($node['children'])) return 'own-sizing';
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMText && '' !== trim($child->textContent)) return 'own-sizing';
        }
        foreach ($node['viewports'] as $viewport => $observation) {
            $child = $tree['nodes'][$node['children'][0]]['viewports'][$viewport]['border_box'];
            foreach ($observation['border_box'] as $axis => $value) {
                if (abs($value - $child[$axis]) > 0.01) return 'own-sizing';
            }
        }
        return 'equal-child-geometry';
    }

    private static function paints(array $s): bool
    {
        foreach (array('box-shadow', 'filter', 'transform') as $property) {
            if ('none' !== $s[$property]) return true;
        }
        if ((float) $s['opacity'] !== 1.0 || !in_array($s['mix-blend-mode'], array('normal', 'none'), true)) return true;
        foreach (array('overflow-x', 'overflow-y') as $property) {
            if (in_array($s[$property], array('hidden', 'clip', 'scroll', 'auto'), true)) return true;
        }
        // CSSOM shorthands include color and defaults even when nothing paints.
        $background = preg_replace('/rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\)|transparent/', '', $s['background']);
        $background = preg_replace('/\b(?:none|repeat|scroll|auto|padding-box|border-box)\b|0%|\//', '', $background);
        if ('' !== trim($background)) return true;
        if (!preg_match('/^none(?:\s|$)/', $s['mask'])) return true;
        foreach (array('border-top', 'border-right', 'border-bottom', 'border-left', 'outline') as $property) {
            if (!preg_match('/\b(?:none|hidden)\b|(?:^|\s)0(?:px)?(?:\s|$)/', $s[$property]) && !str_contains($s[$property], 'transparent') && !preg_match('/rgba\([^)]*,\s*0\s*\)/', $s[$property])) return true;
        }
        return false;
    }
}
