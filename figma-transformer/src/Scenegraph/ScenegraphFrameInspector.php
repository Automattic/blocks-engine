<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Builds a compact frame/page candidate report for decoded scenegraphs.
 */
final class ScenegraphFrameInspector
{
    public function __construct(
        private readonly ScenegraphIndex $index = new ScenegraphIndex()
    ) {
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function inspect(array $source, array $options = array()): array
    {
        $limit = isset($options['frame_inspection_limit']) && is_numeric($options['frame_inspection_limit'])
            ? max(1, (int) $options['frame_inspection_limit'])
            : 50;
        $index = $this->index->build($source);
        $nodes = is_array($index['nodes'] ?? null) ? $index['nodes'] : array();
        $childrenIndex = is_array($index['children_index'] ?? null) ? $index['children_index'] : array();
        $parentIndex = is_array($index['parent_index'] ?? null) ? $index['parent_index'] : array();
        $statsMemo = array();
        $candidates = array();

        foreach ( $nodes as $id => $node ) {
            if ( ! is_array($node) || ! is_string($id) ) {
                continue;
            }

            $type = strtoupper((string) ($node['type'] ?? ''));
            if ( ! in_array($type, array('FRAME', 'SECTION', 'COMPONENT', 'INSTANCE'), true) ) {
                continue;
            }

            $stats = $this->subtreeStats($id, $nodes, $childrenIndex, $statsMemo);
            $dimensions = $this->dimensions($node);
            $candidates[] = array_filter(
                array(
                    'id'                    => $id,
                    'name'                  => (string) ($node['name'] ?? ''),
                    'type'                  => $type,
                    'width'                 => $dimensions['width'],
                    'height'                => $dimensions['height'],
                    'page'                  => $this->nearestAncestor($id, array('CANVAS'), $nodes, $parentIndex),
                    'section'               => 'SECTION' === $type ? null : $this->nearestAncestor($id, array('SECTION'), $nodes, $parentIndex),
                    'parent'                => $this->parentSummary($id, $nodes, $parentIndex),
                    'child_count'           => count(is_array($childrenIndex[$id] ?? null) ? $childrenIndex[$id] : array()),
                    'subtree_node_count'    => $stats['nodes'],
                    'text_count'            => $stats['texts'],
                    'asset_reference_count' => $stats['assets'],
                    'score'                 => $this->score($type, $dimensions, $stats),
                ),
                static fn (mixed $value): bool => null !== $value
            );
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => ((int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0))
                ?: strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''))
        );

        return array(
            'schema'          => 'blocks-engine/figma-transformer/frame-inspection/v1',
            'input_shape'     => $this->detectInputShape($source),
            'node_count'      => count($nodes),
            'candidate_count' => count($candidates),
            'returned_count'  => min($limit, count($candidates)),
            'candidates'      => array_slice($candidates, 0, $limit),
            'diagnostics'     => is_array($index['diagnostics'] ?? null) ? $index['diagnostics'] : array(),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, array<int, string>>   $childrenIndex
     * @param array<string, array<string, int>>   $memo
     * @return array{nodes:int,texts:int,assets:int}
     */
    private function subtreeStats(string $id, array $nodes, array $childrenIndex, array &$memo): array
    {
        if ( isset($memo[$id]) ) {
            return $memo[$id];
        }

        $node = is_array($nodes[$id] ?? null) ? $nodes[$id] : array();
        $stats = array(
            'nodes'  => 1,
            'texts'  => 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ? 1 : 0,
            'assets' => $this->nodeHasAssetReference($node) ? 1 : 0,
        );

        foreach ( $childrenIndex[$id] ?? array() as $childId ) {
            if ( ! is_string($childId) ) {
                continue;
            }
            $childStats = $this->subtreeStats($childId, $nodes, $childrenIndex, $memo);
            $stats['nodes'] += $childStats['nodes'];
            $stats['texts'] += $childStats['texts'];
            $stats['assets'] += $childStats['assets'];
        }

        $memo[$id] = $stats;
        return $stats;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{width: float|null, height: float|null}
     */
    private function dimensions(array $node): array
    {
        $width = null;
        $height = null;
        if ( is_numeric($node['width'] ?? null) ) {
            $width = (float) $node['width'];
        }
        if ( is_numeric($node['height'] ?? null) ) {
            $height = (float) $node['height'];
        }
        if ( is_array($node['size'] ?? null) ) {
            $width = is_numeric($node['size']['x'] ?? null) ? (float) $node['size']['x'] : $width;
            $height = is_numeric($node['size']['y'] ?? null) ? (float) $node['size']['y'] : $height;
        }

        foreach ( array('absoluteBoundingBox', 'absoluteRenderBounds') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                $width = is_numeric($node[$key]['width'] ?? null) ? (float) $node[$key]['width'] : $width;
                $height = is_numeric($node[$key]['height'] ?? null) ? (float) $node[$key]['height'] : $height;
            }
        }

        return array('width' => $width, 'height' => $height);
    }

    /**
     * @param array<int, string>                 $types
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string>              $parentIndex
     * @return array<string, string>|null
     */
    private function nearestAncestor(string $id, array $types, array $nodes, array $parentIndex): ?array
    {
        $parent = $parentIndex[$id] ?? null;
        while ( is_string($parent) && isset($nodes[$parent]) && is_array($nodes[$parent]) ) {
            $type = strtoupper((string) ($nodes[$parent]['type'] ?? ''));
            if ( in_array($type, $types, true) ) {
                return $this->nodeSummary($parent, $nodes[$parent]);
            }
            $parent = $parentIndex[$parent] ?? null;
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string>              $parentIndex
     * @return array<string, string>|null
     */
    private function parentSummary(string $id, array $nodes, array $parentIndex): ?array
    {
        $parent = $parentIndex[$id] ?? null;
        return is_string($parent) && isset($nodes[$parent]) && is_array($nodes[$parent]) ? $this->nodeSummary($parent, $nodes[$parent]) : null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, string>
     */
    private function nodeSummary(string $id, array $node): array
    {
        return array(
            'id'   => $id,
            'name' => (string) ($node['name'] ?? ''),
            'type' => strtoupper((string) ($node['type'] ?? '')),
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeHasAssetReference(array $node): bool
    {
        foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef', 'imageHash') as $key ) {
            if ( isset($node[$key]) ) {
                return true;
            }
        }

        foreach ( array('fills', 'fillPaints', 'backgroundPaints') as $key ) {
            foreach ( is_array($node[$key] ?? null) ? $node[$key] : array() as $paint ) {
                if ( is_array($paint) && 'IMAGE' === strtoupper((string) ($paint['type'] ?? '')) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array{width: float|null, height: float|null} $dimensions
     * @param array{nodes:int,texts:int,assets:int}       $stats
     */
    private function score(string $type, array $dimensions, array $stats): int
    {
        $area = (float) ($dimensions['width'] ?? 0) * (float) ($dimensions['height'] ?? 0);
        $typeScore = match ( $type ) {
            'FRAME' => 100,
            'SECTION' => 80,
            'COMPONENT', 'INSTANCE' => 30,
            default => 0,
        };

        return $typeScore
            + min(250, $stats['texts'] * 4)
            + min(120, $stats['assets'] * 12)
            + min(160, intdiv($stats['nodes'], 8))
            + ((($dimensions['width'] ?? 0) >= 1000 && ($dimensions['width'] ?? 0) <= 2200) ? 80 : 0)
            + (($dimensions['height'] ?? 0) >= 3000 ? 220 : (($dimensions['height'] ?? 0) >= 2000 ? 140 : 0))
            + ($area > 300000 ? 80 : 0)
            - (($dimensions['height'] ?? 0) > 0 && ($dimensions['height'] ?? 0) < 1500 && $stats['nodes'] > 200 ? 160 : 0)
            - ($area > 0 && $area < 10000 ? 60 : 0);
    }

    /**
     * @param array<string, mixed> $source
     */
    private function detectInputShape(array $source): string
    {
        foreach ( array('NODE_CHANGES', 'node_changes', 'nodeChanges', 'document', 'nodes') as $key ) {
            if ( array_key_exists($key, $source) ) {
                return $key;
            }
        }

        return 'array';
    }
}
