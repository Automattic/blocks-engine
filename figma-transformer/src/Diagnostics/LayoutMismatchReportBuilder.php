<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Diagnostics;

/**
 * Compares source Figma visual boxes with runner-supplied generated DOM boxes.
 */
final class LayoutMismatchReportBuilder
{
    public const SCHEMA = 'blocks-engine/figma-transformer/layout-mismatch-report/v1';
    public const DOM_BOXES_SCHEMA = 'homeboy/static-artifact-dom-boxes/v1';

    /**
     * @param array<string, mixed> $htmlSourceReport
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function build(array $htmlSourceReport, array $evidence = array(), array $options = array()): array
    {
        $threshold = $this->numericOption($options, 'threshold', 24.0);
        $sizeThreshold = $this->numericOption($options, 'size_threshold', $threshold);
        $limit = max(1, (int) $this->numericOption($options, 'limit', 100.0));
        $sourceNodes = $this->sourceNodesById($htmlSourceReport);
        $generatedNodes = $this->generatedNodesById($evidence);
        $diagnostics = array();
        $matched = 0;
        $unmatchedSource = 0;

        foreach ( $sourceNodes as $nodeId => $sourceNode ) {
            $generatedNode = $generatedNodes[$nodeId] ?? null;
            if ( null === $generatedNode ) {
                $unmatchedSource++;
                continue;
            }

            $sourceBox = $this->boxFromNode($sourceNode);
            $generatedBox = $this->boxFromNode($generatedNode);
            if ( null === $sourceBox || null === $generatedBox ) {
                continue;
            }

            $matched++;
            $delta = $this->delta($sourceBox, $generatedBox);
            $positionMismatch = abs($delta['x']) > $threshold || abs($delta['y']) > $threshold;
            $sizeMismatch = abs($delta['width']) > $sizeThreshold || abs($delta['height']) > $sizeThreshold;
            $outsideParent = $this->outsideGeneratedParent($sourceNode, $generatedNode, $generatedNodes, $threshold);
            if ( ! $positionMismatch && ! $sizeMismatch && null === $outsideParent ) {
                continue;
            }

            if ( $positionMismatch || $sizeMismatch ) {
                $diagnostics[] = $this->diagnostic(
                    $positionMismatch ? 'misplaced_element' : 'element_size_mismatch',
                    $sourceNode,
                    $generatedNode,
                    $sourceBox,
                    $generatedBox,
                    $delta,
                    $threshold,
                    $sizeThreshold
                );
            }

            if ( $positionMismatch && $sizeMismatch ) {
                $diagnostics[] = $this->diagnostic('element_size_mismatch', $sourceNode, $generatedNode, $sourceBox, $generatedBox, $delta, $threshold, $sizeThreshold);
            }

            if ( null !== $outsideParent ) {
                $diagnostics[] = array_merge(
                    $this->diagnostic('element_outside_parent_bounds', $sourceNode, $generatedNode, $sourceBox, $generatedBox, $delta, $threshold, $sizeThreshold),
                    array('parent' => $outsideParent)
                );
            }
        }

        usort(
            $diagnostics,
            static fn (array $left, array $right): int => (($right['max_delta'] ?? 0) <=> ($left['max_delta'] ?? 0)) ?: strcmp((string) ($left['node']['id'] ?? ''), (string) ($right['node']['id'] ?? ''))
        );

        $diagnostics = array_slice($diagnostics, 0, $limit);
        $codeCounts = array();
        foreach ( $diagnostics as $diagnostic ) {
            $code = (string) ($diagnostic['code'] ?? '');
            if ( '' !== $code ) {
                $codeCounts[$code] = ($codeCounts[$code] ?? 0) + 1;
            }
        }
        ksort($codeCounts);

        return array(
            'schema' => self::SCHEMA,
            'input_schema' => isset($evidence['schema']) && is_scalar($evidence['schema']) ? (string) $evidence['schema'] : self::DOM_BOXES_SCHEMA,
            'status' => empty($generatedNodes) ? 'not_run' : (empty($diagnostics) ? 'pass' : 'fail'),
            'threshold' => $threshold,
            'size_threshold' => $sizeThreshold,
            'summary' => array(
                'source_node_count' => count($sourceNodes),
                'generated_node_count' => count($generatedNodes),
                'matched_node_count' => $matched,
                'unmatched_source_node_count' => $unmatchedSource,
                'diagnostic_count' => count($diagnostics),
                'code_counts' => $codeCounts,
                'clusters' => $this->diagnosticClusters($diagnostics),
            ),
            'diagnostics' => $diagnostics,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function diagnosticClusters(array $diagnostics): array
    {
        return array(
            'parent_delta' => $this->parentDeltaClusters($diagnostics),
            'repeated_position_delta' => $this->repeatedPositionDeltaClusters($diagnostics),
            'node_pattern' => $this->nodePatternClusters($diagnostics),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function parentDeltaClusters(array $diagnostics): array
    {
        $groups = array();
        foreach ( $diagnostics as $diagnostic ) {
            $node = is_array($diagnostic['node'] ?? null) ? $diagnostic['node'] : array();
            $parentId = isset($node['parent_id']) && is_scalar($node['parent_id']) && '' !== (string) $node['parent_id'] ? (string) $node['parent_id'] : '(root)';
            $this->addClusterDiagnostic($groups, $parentId, $diagnostic, array('parent_id' => $parentId));
        }

        return $this->clusterValues($groups);
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function repeatedPositionDeltaClusters(array $diagnostics): array
    {
        $groups = array();
        foreach ( $diagnostics as $diagnostic ) {
            $delta = is_array($diagnostic['delta'] ?? null) ? $diagnostic['delta'] : array();
            if ( ! is_numeric($delta['x'] ?? null) || ! is_numeric($delta['y'] ?? null) ) {
                continue;
            }

            $x = (int) round((float) $delta['x']);
            $y = (int) round((float) $delta['y']);
            $key = 'x:' . $x . '|y:' . $y;
            $this->addClusterDiagnostic($groups, $key, $diagnostic, array('delta' => array('x' => $x, 'y' => $y)));
        }

        return array_values(array_filter(
            $this->clusterValues($groups),
            static fn (array $cluster): bool => 1 < (int) ($cluster['count'] ?? 0)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function nodePatternClusters(array $diagnostics): array
    {
        $groups = array();
        foreach ( $diagnostics as $diagnostic ) {
            $node = is_array($diagnostic['node'] ?? null) ? $diagnostic['node'] : array();
            $type = isset($node['type']) && is_scalar($node['type']) && '' !== (string) $node['type'] ? (string) $node['type'] : '(unknown)';
            $namePattern = $this->namePattern(isset($node['name']) && is_scalar($node['name']) ? (string) $node['name'] : '');
            $key = $type . '|' . $namePattern;
            $this->addClusterDiagnostic($groups, $key, $diagnostic, array('type' => $type, 'name_pattern' => $namePattern));
        }

        return $this->clusterValues($groups);
    }

    /**
     * @param array<string, array<string, mixed>> $groups
     * @param array<string, mixed> $diagnostic
     * @param array<string, mixed> $fields
     */
    private function addClusterDiagnostic(array &$groups, string $key, array $diagnostic, array $fields): void
    {
        if ( ! isset($groups[$key]) ) {
            $groups[$key] = array_merge($fields, array(
                'count' => 0,
                'max_delta' => 0.0,
                'average_delta' => array('x' => 0.0, 'y' => 0.0),
                'code_counts' => array(),
                'sample_nodes' => array(),
                '_delta_x_total' => 0.0,
                '_delta_y_total' => 0.0,
            ));
        }

        $delta = is_array($diagnostic['delta'] ?? null) ? $diagnostic['delta'] : array();
        $deltaX = is_numeric($delta['x'] ?? null) ? (float) $delta['x'] : 0.0;
        $deltaY = is_numeric($delta['y'] ?? null) ? (float) $delta['y'] : 0.0;
        $groups[$key]['count']++;
        $groups[$key]['_delta_x_total'] += $deltaX;
        $groups[$key]['_delta_y_total'] += $deltaY;
        $groups[$key]['max_delta'] = max((float) $groups[$key]['max_delta'], is_numeric($diagnostic['max_delta'] ?? null) ? (float) $diagnostic['max_delta'] : 0.0);

        $code = isset($diagnostic['code']) && is_scalar($diagnostic['code']) ? (string) $diagnostic['code'] : '';
        if ( '' !== $code ) {
            $groups[$key]['code_counts'][$code] = ($groups[$key]['code_counts'][$code] ?? 0) + 1;
        }

        $node = is_array($diagnostic['node'] ?? null) ? $diagnostic['node'] : array();
        if ( count($groups[$key]['sample_nodes']) < 3 ) {
            $groups[$key]['sample_nodes'][] = array_filter(array(
                'id' => isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : null,
                'name' => isset($node['name']) && is_scalar($node['name']) ? (string) $node['name'] : null,
                'type' => isset($node['type']) && is_scalar($node['type']) ? (string) $node['type'] : null,
            ), static fn (mixed $value): bool => null !== $value && '' !== $value);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $groups
     * @return array<int, array<string, mixed>>
     */
    private function clusterValues(array $groups): array
    {
        $clusters = array_values(array_map(
            static function (array $cluster): array {
                $count = max(1, (int) $cluster['count']);
                $cluster['average_delta'] = array(
                    'x' => round((float) $cluster['_delta_x_total'] / $count, 2),
                    'y' => round((float) $cluster['_delta_y_total'] / $count, 2),
                );
                ksort($cluster['code_counts']);
                unset($cluster['_delta_x_total'], $cluster['_delta_y_total']);
                return $cluster;
            },
            $groups
        ));

        usort(
            $clusters,
            static fn (array $left, array $right): int => ((int) ($right['count'] ?? 0) <=> (int) ($left['count'] ?? 0)) ?: ((float) ($right['max_delta'] ?? 0.0) <=> (float) ($left['max_delta'] ?? 0.0))
        );

        return array_slice($clusters, 0, 10);
    }

    private function namePattern(string $name): string
    {
        $pattern = strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/\d+/', '#', $name) ?? '') ?? ''));
        $pattern = trim(preg_replace('/[^a-z#]+/', ' ', $pattern) ?? '');

        return '' === $pattern ? '(unnamed)' : $pattern;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function numericOption(array $values, string $key, float $default): float
    {
        return isset($values[$key]) && is_numeric($values[$key]) ? max(0.0, (float) $values[$key]) : $default;
    }

    /**
     * @param array<string, mixed> $htmlSourceReport
     * @return array<string, array<string, mixed>>
     */
    private function sourceNodesById(array $htmlSourceReport): array
    {
        $nodes = is_array($htmlSourceReport['visual_node_map'] ?? null) ? $htmlSourceReport['visual_node_map'] : array();
        return $this->nodesById($nodes);
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, array<string, mixed>>
     */
    private function generatedNodesById(array $evidence): array
    {
        $nodes = array();
        foreach ( array('boxes', 'nodes', 'dom_boxes', 'elements') as $key ) {
            if ( is_array($evidence[$key] ?? null) ) {
                $nodes = array_merge($nodes, $evidence[$key]);
            }
        }

        foreach ( is_array($evidence['entrypoints'] ?? null) ? $evidence['entrypoints'] : array() as $entrypoint ) {
            if ( is_array($entrypoint) && is_array($entrypoint['elements'] ?? null) ) {
                $nodes = array_merge($nodes, $entrypoint['elements']);
            }
        }

        if ( empty($nodes) && is_array($evidence['generated']['boxes'] ?? null) ) {
            $nodes = $evidence['generated']['boxes'];
        }

        return $this->nodesById($nodes);
    }

    /**
     * @param array<int, mixed> $nodes
     * @return array<string, array<string, mixed>>
     */
    private function nodesById(array $nodes): array
    {
        $byId = array();
        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }

            $id = $this->nodeId($node);
            if ( '' === $id ) {
                continue;
            }

            $node['id'] = $id;
            $byId[$id] = $node;
        }

        ksort($byId);
        return $byId;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeId(array $node): string
    {
        foreach ( array('id', 'node_id', 'figma_node_id', 'data_figma_node_id', 'data-figma-node-id') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                return (string) $node[$key];
            }
        }

        if ( isset($node['attributes']['data-figma-node-id']) && is_scalar($node['attributes']['data-figma-node-id']) ) {
            return (string) $node['attributes']['data-figma-node-id'];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $node
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function boxFromNode(array $node): ?array
    {
        foreach ( array('rect', 'box', 'bounding_client_rect', 'boundingClientRect', 'bounds') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                $box = $this->boxFromValue($node[$key]);
                if ( null !== $box ) {
                    return $box;
                }
            }
        }

        return $this->boxFromValue($node);
    }

    /**
     * @param array<string, mixed> $value
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function boxFromValue(array $value): ?array
    {
        $x = $value['x'] ?? $value['left'] ?? null;
        $y = $value['y'] ?? $value['top'] ?? null;
        $width = $value['width'] ?? null;
        $height = $value['height'] ?? null;
        if ( null === $width && isset($value['right']) && is_numeric($value['right']) && is_numeric($x) ) {
            $width = (float) $value['right'] - (float) $x;
        }
        if ( null === $height && isset($value['bottom']) && is_numeric($value['bottom']) && is_numeric($y) ) {
            $height = (float) $value['bottom'] - (float) $y;
        }

        foreach ( array($x, $y, $width, $height) as $part ) {
            if ( ! is_numeric($part) ) {
                return null;
            }
        }

        return array('x' => (float) $x, 'y' => (float) $y, 'width' => (float) $width, 'height' => (float) $height);
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $sourceBox
     * @param array{x: float, y: float, width: float, height: float} $generatedBox
     * @return array{x: float, y: float, width: float, height: float}
     */
    private function delta(array $sourceBox, array $generatedBox): array
    {
        return array(
            'x' => $generatedBox['x'] - $sourceBox['x'],
            'y' => $generatedBox['y'] - $sourceBox['y'],
            'width' => $generatedBox['width'] - $sourceBox['width'],
            'height' => $generatedBox['height'] - $sourceBox['height'],
        );
    }

    /**
     * @param array<string, mixed> $sourceNode
     * @param array<string, mixed> $generatedNode
     * @param array<string, array<string, mixed>> $generatedNodes
     * @return array<string, mixed>|null
     */
    private function outsideGeneratedParent(array $sourceNode, array $generatedNode, array $generatedNodes, float $threshold): ?array
    {
        $parentId = isset($sourceNode['parent_id']) && is_scalar($sourceNode['parent_id']) ? (string) $sourceNode['parent_id'] : '';
        if ( '' === $parentId || ! isset($generatedNodes[$parentId]) ) {
            return null;
        }

        $parentBox = $this->boxFromNode($generatedNodes[$parentId]);
        $childBox = $this->boxFromNode($generatedNode);
        if ( null === $parentBox || null === $childBox ) {
            return null;
        }

        $overflow = array(
            'left' => max(0.0, $parentBox['x'] - $childBox['x']),
            'top' => max(0.0, $parentBox['y'] - $childBox['y']),
            'right' => max(0.0, ($childBox['x'] + $childBox['width']) - ($parentBox['x'] + $parentBox['width'])),
            'bottom' => max(0.0, ($childBox['y'] + $childBox['height']) - ($parentBox['y'] + $parentBox['height'])),
        );
        $maxOverflow = max($overflow);
        if ( $maxOverflow <= $threshold ) {
            return null;
        }

        return array(
            'id' => $parentId,
            'generated_box' => $parentBox,
            'overflow' => $overflow,
            'max_overflow' => $maxOverflow,
        );
    }

    /**
     * @param array<string, mixed> $sourceNode
     * @param array<string, mixed> $generatedNode
     * @param array{x: float, y: float, width: float, height: float} $sourceBox
     * @param array{x: float, y: float, width: float, height: float} $generatedBox
     * @param array{x: float, y: float, width: float, height: float} $delta
     * @return array<string, mixed>
     */
    private function diagnostic(string $code, array $sourceNode, array $generatedNode, array $sourceBox, array $generatedBox, array $delta, float $threshold, float $sizeThreshold): array
    {
        return array_filter(array(
            'severity' => 'warning',
            'code' => $code,
            'node' => array(
                'id' => (string) ($sourceNode['id'] ?? ''),
                'name' => (string) ($sourceNode['name'] ?? ''),
                'type' => (string) ($sourceNode['type'] ?? ''),
                'parent_id' => (string) ($sourceNode['parent_id'] ?? ''),
            ),
            'source_box' => $sourceBox,
            'generated_box' => $generatedBox,
            'delta' => $delta,
            'max_delta' => max(abs($delta['x']), abs($delta['y']), abs($delta['width']), abs($delta['height'])),
            'threshold' => $threshold,
            'size_threshold' => $sizeThreshold,
            'page_path' => isset($generatedNode['page_path']) && is_scalar($generatedNode['page_path']) ? (string) $generatedNode['page_path'] : null,
            'frame_id' => isset($generatedNode['frame_id']) && is_scalar($generatedNode['frame_id']) ? (string) $generatedNode['frame_id'] : null,
            'selector' => isset($generatedNode['selector']) && is_scalar($generatedNode['selector']) ? (string) $generatedNode['selector'] : null,
        ), static fn (mixed $value): bool => null !== $value);
    }
}
