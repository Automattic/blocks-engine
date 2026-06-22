<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Normalizes decoded Figma scenegraph payloads into a deterministic transformer contract.
 */
final class ScenegraphNormalizer
{
    public function __construct(
        private readonly ScenegraphIndex $index = new ScenegraphIndex()
    ) {
    }

    /**
     * @param array<string, mixed> $source Decoded NODE_CHANGES-shaped source array.
     * @param array<string, mixed> $options Normalization options.
     * @return array<string, mixed>
     */
    public function normalize(array $source, array $options = array()): array
    {
        $index       = $this->index->build($source);
        $nodeMap     = $this->normalizeNodeMap($index['nodes'], $index['children_index']);
        $topLevelIds = $index['top_level_node_ids'];
        $frameIds    = $this->selectTopLevelFrameIds($topLevelIds, $nodeMap);

        $selectedFrameId = null;
        if ( isset($options['frame_id']) && is_scalar($options['frame_id']) && isset($nodeMap[(string) $options['frame_id']]) ) {
            $selectedFrameId = (string) $options['frame_id'];
        } elseif ( ! empty($frameIds) ) {
            $selectedFrameId = $frameIds[0];
        } elseif ( ! empty($topLevelIds) ) {
            $selectedFrameId = $topLevelIds[0];
        }

        $renderIds = $topLevelIds;
        $renderNodes = array();
        foreach ( $renderIds as $id ) {
            if ( isset($nodeMap[$id]) ) {
                $renderNodes[] = $nodeMap[$id];
            }
        }

        $textInventory   = $this->buildTextInventory($nodeMap);
        $assetReferences = $this->buildAssetReferences($nodeMap);
        $sourceName      = $this->readSourceName($source, $renderNodes);

        return array(
            'schema'              => 'blocks-engine/figma-transformer/scenegraph/v1',
            'name'                => $sourceName,
            'assets'              => is_array($source['assets'] ?? null) ? $source['assets'] : array(),
            'nodes'               => $renderNodes,
            'node_map'            => $nodeMap,
            'assets'              => is_array($source['assets'] ?? null) ? $source['assets'] : array(),
            'parent_index'        => $index['parent_index'],
            'children_index'      => $index['children_index'],
            'top_level_node_ids'  => $topLevelIds,
            'top_level_frame_ids' => $frameIds,
            'selected_frame_id'   => $selectedFrameId,
            'text_inventory'      => $textInventory,
            'asset_references'    => $assetReferences,
            'diagnostics'         => $index['diagnostics'],
            'source_report'       => array(
                'schema'                => 'blocks-engine/figma-transformer/scenegraph-source/v1',
                'input_shape'           => $this->detectInputShape($source),
                'name'                  => $sourceName,
                'node_count'            => count($nodeMap),
                'top_level_node_ids'    => $topLevelIds,
                'top_level_frame_ids'   => $frameIds,
                'selected_frame_id'     => $selectedFrameId,
                'text_node_count'       => count($textInventory),
                'asset_reference_count' => count($assetReferences),
                'diagnostic_count'      => count($index['diagnostics']),
            ),
        );
    }

    /**
     * @param array<int, string> $topLevelIds
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, string>
     */
    private function selectTopLevelFrameIds(array $topLevelIds, array $nodeMap): array
    {
        $frameIds = array();

        foreach ( $topLevelIds as $id ) {
            $type = strtoupper((string) ($nodeMap[$id]['type'] ?? ''));
            if ( in_array($type, array('FRAME', 'COMPONENT', 'INSTANCE'), true) ) {
                $frameIds[] = $id;
            }
        }

        return $frameIds;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<string, array<int, string>> $childrenIndex
     * @return array<string, array<string, mixed>>
     */
    private function normalizeNodeMap(array $nodeMap, array $childrenIndex): array
    {
        $normalized = array();

        foreach ( $nodeMap as $id => $node ) {
            $node['box'] = $this->normalizeBox($node);
            $layout = $this->normalizeLayout($node);
            if ( ! empty($layout) ) {
                $node['layout'] = $layout;
            }

            $node['children'] = array();
            $normalized[$id] = $node;
        }

        $buildNode = function (string $id) use (&$buildNode, &$normalized, $childrenIndex): array {
            $node = $normalized[$id];
            $node['children'] = array();

            foreach ( $childrenIndex[$id] ?? array() as $childId ) {
                if ( isset($normalized[$childId]) ) {
                    $node['children'][] = $buildNode($childId);
                }
            }

            return $node;
        };

        foreach ( array_keys($normalized) as $id ) {
            $normalized[$id] = $buildNode((string) $id);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, float>
     */
    private function normalizeBox(array $node): array
    {
        $box = array();

        foreach ( array('absoluteBoundingBox', 'absoluteRenderBounds') as $boundsKey ) {
            if ( ! is_array($node[$boundsKey] ?? null) ) {
                continue;
            }

            foreach ( array('x', 'y', 'width', 'height') as $dimension ) {
                if ( ! array_key_exists($dimension, $box) && isset($node[$boundsKey][$dimension]) && is_numeric($node[$boundsKey][$dimension]) ) {
                    $box[$dimension] = (float) $node[$boundsKey][$dimension];
                }
            }
        }

        foreach ( array('x', 'y', 'width', 'height') as $dimension ) {
            if ( ! array_key_exists($dimension, $box) && isset($node[$dimension]) && is_numeric($node[$dimension]) ) {
                $box[$dimension] = (float) $node[$dimension];
            }
        }

        return $box;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeLayout(array $node): array
    {
        $layout = array();

        if ( isset($node['layoutMode']) && is_scalar($node['layoutMode']) ) {
            $mode = strtoupper((string) $node['layoutMode']);
            $layout['mode'] = $mode;

            if ( 'HORIZONTAL' === $mode ) {
                $layout['display'] = 'flex';
                $layout['flex_direction'] = 'row';
            } elseif ( 'VERTICAL' === $mode ) {
                $layout['display'] = 'flex';
                $layout['flex_direction'] = 'column';
            }
        }

        foreach ( array(
            'primaryAxisAlignItems' => 'primary_axis_alignment',
            'counterAxisAlignItems' => 'counter_axis_alignment',
        ) as $source => $target ) {
            if ( isset($node[$source]) && is_scalar($node[$source]) ) {
                $layout[$target] = strtoupper((string) $node[$source]);
            }
        }

        if ( isset($layout['primary_axis_alignment']) ) {
            $layout['justify_content'] = $this->cssAxisAlignment((string) $layout['primary_axis_alignment']);
        }

        if ( isset($layout['counter_axis_alignment']) ) {
            $layout['align_items'] = $this->cssAxisAlignment((string) $layout['counter_axis_alignment']);
        }

        $padding = array();
        foreach ( array('top' => 'paddingTop', 'right' => 'paddingRight', 'bottom' => 'paddingBottom', 'left' => 'paddingLeft') as $edge => $source ) {
            if ( isset($node[$source]) && is_numeric($node[$source]) ) {
                $padding[$edge] = (float) $node[$source];
            }
        }
        foreach ( array('left', 'right') as $edge ) {
            if ( ! array_key_exists($edge, $padding) && isset($node['paddingHorizontal']) && is_numeric($node['paddingHorizontal']) ) {
                $padding[$edge] = (float) $node['paddingHorizontal'];
            }
        }
        foreach ( array('top', 'bottom') as $edge ) {
            if ( ! array_key_exists($edge, $padding) && isset($node['paddingVertical']) && is_numeric($node['paddingVertical']) ) {
                $padding[$edge] = (float) $node['paddingVertical'];
            }
        }
        if ( ! empty($padding) ) {
            $layout['padding'] = $padding;
        }

        if ( isset($node['itemSpacing']) && is_numeric($node['itemSpacing']) ) {
            $layout['item_spacing'] = (float) $node['itemSpacing'];
        }

        if ( isset($node['layoutWrap']) && is_scalar($node['layoutWrap']) ) {
            $layout['wrap'] = strtoupper((string) $node['layoutWrap']);
            if ( 'WRAP' === $layout['wrap'] ) {
                $layout['flex_wrap'] = 'wrap';
            }
        }

        if ( isset($node['layoutPositioning']) && 'ABSOLUTE' === strtoupper((string) $node['layoutPositioning']) ) {
            $layout['positioning'] = 'absolute';
        }

        return $layout;
    }

    private function cssAxisAlignment(string $alignment): ?string
    {
        return match ( strtoupper($alignment) ) {
            'MIN' => 'flex-start',
            'CENTER' => 'center',
            'MAX' => 'flex-end',
            'SPACE_BETWEEN' => 'space-between',
            'BASELINE' => 'baseline',
            default => null,
        };
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, array<string, string>>
     */
    private function buildTextInventory(array $nodeMap): array
    {
        $inventory = array();

        foreach ( $nodeMap as $id => $node ) {
            if ( 'TEXT' !== strtoupper((string) ($node['type'] ?? '')) ) {
                continue;
            }

            $text = null;
            foreach ( array('characters', 'text', 'name') as $key ) {
                if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                    $text = (string) $node[$key];
                    break;
                }
            }

            $inventory[] = array(
                'id'   => $id,
                'name' => (string) ($node['name'] ?? ''),
                'text' => $text ?? '',
            );
        }

        return $inventory;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, array<string, string>>
     */
    private function buildAssetReferences(array $nodeMap): array
    {
        $references = array();

        foreach ( $nodeMap as $id => $node ) {
            foreach ( array('fills', 'strokes', 'background') as $paintKey ) {
                if ( ! is_array($node[$paintKey] ?? null) ) {
                    continue;
                }

                foreach ( $node[$paintKey] as $paint ) {
                    if ( ! is_array($paint) || 'IMAGE' !== strtoupper((string) ($paint['type'] ?? '')) ) {
                        continue;
                    }

                    $ref = $paint['imageRef'] ?? $paint['imageHash'] ?? null;
                    if ( is_scalar($ref) && '' !== (string) $ref ) {
                        $references[] = array(
                            'node_id' => $id,
                            'paint'   => $paintKey,
                            'ref'     => (string) $ref,
                        );
                    }
                }
            }
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, array<string, mixed>> $renderNodes
     */
    private function readSourceName(array $source, array $renderNodes): string
    {
        if ( isset($source['name']) && is_scalar($source['name']) && '' !== (string) $source['name'] ) {
            return (string) $source['name'];
        }

        if ( isset($renderNodes[0]['name']) && is_scalar($renderNodes[0]['name']) && '' !== (string) $renderNodes[0]['name'] ) {
            return (string) $renderNodes[0]['name'];
        }

        return 'Figma Site';
    }

    /**
     * @param array<string, mixed> $source
     */
    private function detectInputShape(array $source): string
    {
        foreach ( array('NODE_CHANGES', 'node_changes', 'nodeChanges', 'document', 'nodes') as $key ) {
            if ( isset($source[$key]) ) {
                return $key;
            }
        }

        return 'unknown';
    }
}
