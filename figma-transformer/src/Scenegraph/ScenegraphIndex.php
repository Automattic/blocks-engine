<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Builds deterministic Figma node maps and parent/child indexes from decoded node changes.
 */
final class ScenegraphIndex
{
    /**
     * @param array<string, mixed> $source Decoded NODE_CHANGES-shaped source array.
     * @return array<string, mixed>
     */
    public function build(array $source): array
    {
        $diagnostics = array();
        $rawNodes    = array();
        $roots       = $this->extractRootNodes($source);

        foreach ( $roots as $key => $root ) {
            if ( is_array($root) ) {
                $this->collectNode($root, is_string($key) ? $key : null, null, $rawNodes, $diagnostics);
            }
        }

        ksort($rawNodes, SORT_NATURAL);

        $nodeMap       = array();
        $parentIndex   = array();
        $childrenIndex = array();

        foreach ( $rawNodes as $id => $entry ) {
            $node   = $entry['node'];
            $parent = $entry['parent'];

            $node['id']       = $id;
            $node['children'] = array();

            $nodeMap[$id]     = $node;
            $parentIndex[$id] = $parent;

            if ( null !== $parent ) {
                $childrenIndex[$parent] ??= array();
                $childrenIndex[$parent][] = $id;
            }
        }

        foreach ( $nodeMap as $id => $_node ) {
            $childrenIndex[$id] ??= array();
        }

        foreach ( $childrenIndex as $parent => $children ) {
            $childrenIndex[$parent] = $this->sortNodeIds($children, $nodeMap);
        }

        $topLevelNodeIds = array();
        foreach ( $parentIndex as $id => $parent ) {
            if ( null === $parent || ! isset($nodeMap[$parent]) ) {
                $topLevelNodeIds[] = $id;
            }
        }
        $topLevelNodeIds = $this->sortNodeIds($topLevelNodeIds, $nodeMap);

        foreach ( array_keys($nodeMap) as $id ) {
            $nodeMap[$id] = $this->hydrateNode($id, $nodeMap, $childrenIndex);
        }

        return array(
            'nodes'              => $nodeMap,
            'parent_index'       => $parentIndex,
            'children_index'     => $childrenIndex,
            'top_level_node_ids' => $topLevelNodeIds,
            'diagnostics'        => $diagnostics,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<string, array<int, string>> $childrenIndex
     * @param array<int, string> $trail
     * @return array<string, mixed>
     */
    private function hydrateNode(string $id, array $nodeMap, array $childrenIndex, array $trail = array()): array
    {
        $node = $nodeMap[$id];
        $node['children'] = array();

        if ( in_array($id, $trail, true) ) {
            return $node;
        }

        $trail[] = $id;
        foreach ( $childrenIndex[$id] ?? array() as $childId ) {
            if ( isset($nodeMap[$childId]) ) {
                $node['children'][] = $this->hydrateNode($childId, $nodeMap, $childrenIndex, $trail);
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<mixed>
     */
    private function extractRootNodes(array $source): array
    {
        foreach ( array('NODE_CHANGES', 'node_changes', 'nodeChanges') as $key ) {
            if ( is_array($source[$key] ?? null) ) {
                return $source[$key];
            }
        }

        if ( is_array($source['document'] ?? null) ) {
            return array($source['document']);
        }

        if ( is_array($source['nodes'] ?? null) ) {
            return $source['nodes'];
        }

        return $source;
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, array{node: array<string, mixed>, parent: ?string}> $rawNodes
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function collectNode(array $value, ?string $fallbackId, ?string $parentId, array &$rawNodes, array &$diagnostics): void
    {
        $node = $this->unwrapNodeChange($value);
        if ( null === $node ) {
            $diagnostics[] = array(
                'code'    => 'scenegraph_node_missing',
                'message' => 'Skipped a node change without a node payload.',
            );
            return;
        }

        $id = $this->readString($node, array('id', 'node_id', 'nodeId')) ?? $fallbackId;
        if ( null === $id || '' === $id ) {
            $diagnostics[] = array(
                'code'    => 'scenegraph_node_id_missing',
                'message' => 'Skipped a node without a stable id.',
            );
            return;
        }

        $explicitParent = $this->readString($node, array('parent', 'parentId', 'parent_id'));
        $effectiveParent = $explicitParent ?? $parentId;

        $children = array();
        if ( is_array($node['children'] ?? null) ) {
            $children = $node['children'];
        }

        unset($node['children']);
        if ( isset($rawNodes[$id]) ) {
            $diagnostics[] = array(
                'code'    => 'scenegraph_node_id_duplicate',
                'message' => 'Encountered a duplicate node id; kept the richer source node.',
                'node_id' => $id,
            );

            if ( $this->nodeRichness($node, $children) <= $this->nodeRichness($rawNodes[$id]['node'], $rawNodes[$id]['children'] ?? array()) ) {
                return;
            }
        }

        $rawNodes[$id] = array(
            'node'     => $node,
            'parent'   => $effectiveParent,
            'children' => $children,
        );

        foreach ( $children as $key => $child ) {
            if ( is_array($child) ) {
                $this->collectNode($child, is_string($key) ? $key : null, $id, $rawNodes, $diagnostics);
            }
        }
    }

    /**
     * @param array<string, mixed> $value
     * @return ?array<string, mixed>
     */
    private function unwrapNodeChange(array $value): ?array
    {
        foreach ( array('node', 'document', 'newValue', 'value') as $key ) {
            if ( is_array($value[$key] ?? null) ) {
                return $value[$key];
            }
        }

        if ( isset($value['type']) || isset($value['id']) || isset($value['children']) ) {
            return $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string> $keys
     */
    private function readString(array $node, array $keys): ?string
    {
        foreach ( $keys as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                return (string) $node[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, mixed> $children
     */
    private function nodeRichness(array $node, array $children): int
    {
        return count($node, COUNT_RECURSIVE) + count($children, COUNT_RECURSIVE);
    }

    /**
     * @param array<int, string> $ids
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, string>
     */
    private function sortNodeIds(array $ids, array $nodeMap): array
    {
        usort(
            $ids,
            static function (string $left, string $right) use ($nodeMap): int {
                $leftNode  = $nodeMap[$left] ?? array();
                $rightNode = $nodeMap[$right] ?? array();

                $leftBox  = self::readBounds($leftNode);
                $rightBox = self::readBounds($rightNode);

                return array($leftBox['y'], $leftBox['x'], (string) ($leftNode['name'] ?? ''), $left)
                    <=> array($rightBox['y'], $rightBox['x'], (string) ($rightNode['name'] ?? ''), $right);
            }
        );

        return $ids;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{x: float, y: float}
     */
    private static function readBounds(array $node): array
    {
        foreach ( array('absoluteBoundingBox', 'absoluteRenderBounds', 'relativeTransformBounds') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                return array(
                    'x' => is_numeric($node[$key]['x'] ?? null) ? (float) $node[$key]['x'] : 0.0,
                    'y' => is_numeric($node[$key]['y'] ?? null) ? (float) $node[$key]['y'] : 0.0,
                );
            }
        }

        return array('x' => 0.0, 'y' => 0.0);
    }
}
