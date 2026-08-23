<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Pure normalized-node traversal and value inspection.
 */
final class StaticHtmlNodeInspector
{
    /**
     * @param array<string, mixed> $container
     * @return array<int, mixed>
     */
    public function nodeList(array $container): array
    {
        if ( is_array($container['nodes'] ?? null) ) {
            return array_values($container['nodes']);
        }

        if ( is_array($container['children'] ?? null) ) {
            return array_values($container['children']);
        }

        return array();
    }

    /** @param array<string, mixed> $node */
    public function textDescendantCount(array $node): int
    {
        $count = 0;
        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            if ( 'TEXT' === strtoupper((string) ($child['type'] ?? '')) ) {
                ++$count;
            }
            $count += $this->textDescendantCount($child);
        }

        return $count;
    }

    /** @param array<string, mixed> $node */
    public function subtreePlainText(array $node): string
    {
        $parts = array();
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            $own = $this->nodePlainText($node);
            if ( '' !== $own ) {
                $parts[] = $own;
            }
        }
        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            $childText = $this->subtreePlainText($child);
            if ( '' !== $childText ) {
                $parts[] = $childText;
            }
        }

        return implode(' ', $parts);
    }

    /** @param array<string, mixed> $node */
    public function nodePlainText(array $node): string
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        if ( isset($text['characters']) && is_scalar($text['characters']) ) {
            return (string) $text['characters'];
        }

        $segments = is_array($text['segments'] ?? null) ? $text['segments'] : array();
        if ( ! empty($segments) ) {
            $out = '';
            foreach ( $segments as $segment ) {
                if ( is_array($segment) && isset($segment['characters']) && is_scalar($segment['characters']) ) {
                    $out .= (string) $segment['characters'];
                }
            }
            if ( '' !== $out ) {
                return $out;
            }
        }

        foreach ( array('characters', 'text') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                return (string) $node[$key];
            }
        }

        return '';
    }

    /** @param array<string, mixed> $node */
    public function boxValue(array $node, string $key): ?float
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( isset($box[$key]) && is_numeric($box[$key]) ) {
            return (float) $box[$key];
        }
        if ( isset($node[$key]) && is_numeric($node[$key]) ) {
            return (float) $node[$key];
        }

        return null;
    }

    /** @param array<string, mixed> $node */
    public function cornerRadius(array $node): float
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        return isset($box['corner_radius']) && is_numeric($box['corner_radius']) ? (float) $box['corner_radius'] : 0.0;
    }

    /** @param array<string, mixed> $node */
    public function subtreeHasRenderableVector(array $node): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true) ) {
            return true;
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->subtreeHasRenderableVector($child) ) {
                return true;
            }
        }

        return false;
    }
}
