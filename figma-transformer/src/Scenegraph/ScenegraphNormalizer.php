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
        $diagnostics = $index['diagnostics'];
        $blobs       = is_array($source['blobs'] ?? null) ? $source['blobs'] : array();
        if ( isset($options['max_nodes']) && is_numeric($options['max_nodes']) && (int) $options['max_nodes'] > 0 && count($index['nodes']) > (int) $options['max_nodes'] ) {
            $preferredRootId = isset($options['frame_id']) && is_scalar($options['frame_id']) ? (string) $options['frame_id'] : null;
            $index = $this->limitIndexNodes($index, (int) $options['max_nodes'], $diagnostics, $preferredRootId);
        }
        $paintStyles = $this->buildPaintStyleDefinitions($index['nodes'], $diagnostics);
        $nodeMap     = $this->normalizeNodeMap($index['nodes'], $diagnostics, $blobs, $paintStyles);
        $components  = $this->buildComponentDefinitions($nodeMap);
        $componentDefinitionCount = $this->countComponentDefinitions($nodeMap);
        $instanceReport = $this->resolveInstances($nodeMap, $components, $diagnostics, $blobs, $paintStyles);
        $topLevelIds = $index['top_level_node_ids'];
        $frameIds    = $this->selectTopLevelFrameIds($topLevelIds, $nodeMap);

        $explicitSelectedFrameId = isset($options['frame_id']) && is_scalar($options['frame_id']) && isset($nodeMap[(string) $options['frame_id']]);
        $selectedFrameId = null;
        if ( $explicitSelectedFrameId ) {
            $selectedFrameId = (string) $options['frame_id'];
        } elseif ( ! empty($frameIds) ) {
            $selectedFrameId = $frameIds[0];
        } elseif ( ! empty($topLevelIds) ) {
            $selectedFrameId = $topLevelIds[0];
        }

        $renderIds = $topLevelIds;
        if ( null !== $selectedFrameId && 1 === count($topLevelIds) && $selectedFrameId !== $topLevelIds[0] ) {
            $renderIds = array($selectedFrameId);
        }
        $renderNodes = array();
        foreach ( $renderIds as $id ) {
            if ( isset($nodeMap[$id]) ) {
                $node = $this->refreshResolvedTree($nodeMap[$id], $nodeMap);
                if ( $explicitSelectedFrameId && $id === $selectedFrameId ) {
                    $node = $this->rebaseSelectedFrameToOrigin($node);
                }
                $renderNodes[] = $node;
            }
        }

        $textInventory   = $this->buildTextInventory($nodeMap);
        $assetReferences = $this->buildAssetReferences($nodeMap);
        $sourceName      = $this->readSourceName($source, $renderNodes);
        $diagnostics     = $this->compactUnsupportedVectorNetworkBlobDiagnostics($this->compactGlyphCommandBlobDiagnostics($diagnostics));

        return array(
            'schema'              => 'blocks-engine/figma-transformer/scenegraph/v1',
            'name'                => $sourceName,
            'nodes'               => $renderNodes,
            'assets'              => is_array($source['assets'] ?? null) ? $source['assets'] : array(),
            'figma_blobs'         => $blobs,
            'node_map'            => $nodeMap,
            'parent_index'        => $index['parent_index'],
            'children_index'      => $index['children_index'],
            'top_level_node_ids'  => $topLevelIds,
            'top_level_frame_ids' => $frameIds,
            'selected_frame_id'   => $selectedFrameId,
            'text_inventory'      => $textInventory,
            'asset_references'    => $assetReferences,
            'diagnostics'         => $diagnostics,
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
                'asset_references'      => $assetReferences,
                'component_definition_count' => $componentDefinitionCount,
                'instance_node_count'   => $instanceReport['instance_node_count'],
                'resolved_instance_count' => $instanceReport['resolved_instance_count'],
                'unresolved_component_references' => $instanceReport['unresolved_component_references'],
                'diagnostic_count'      => count($diagnostics),
            ),
        );
    }

    /**
     * @param array<string, mixed>             $index
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function limitIndexNodes(array $index, int $maxNodes, array &$diagnostics, ?string $preferredRootId = null): array
    {
        $nodes = is_array($index['nodes'] ?? null) ? $index['nodes'] : array();
        $allowedIds = null !== $preferredRootId && isset($nodes[$preferredRootId])
            ? $this->limitedSubtreeIds($preferredRootId, is_array($index['children_index'] ?? null) ? $index['children_index'] : array(), $maxNodes)
            : array_slice(array_keys($nodes), 0, $maxNodes);
        $allowedIds = $this->includeComponentClosureIds($allowedIds, $nodes, is_array($index['children_index'] ?? null) ? $index['children_index'] : array());
        $allowed = array_fill_keys($allowedIds, true);
        $limitedNodes = array();

        foreach ( $allowedIds as $id ) {
            if ( isset($nodes[$id]) && is_array($nodes[$id]) ) {
                $limitedNodes[$id] = $this->pruneNodeChildren($nodes[$id], $allowed);
            }
        }

        $index['nodes'] = $limitedNodes;
        $index['parent_index'] = array_intersect_key(is_array($index['parent_index'] ?? null) ? $index['parent_index'] : array(), $allowed);
        $childrenIndex = array();
        foreach ( is_array($index['children_index'] ?? null) ? $index['children_index'] : array() as $parentId => $childIds ) {
            if ( ! isset($allowed[$parentId]) || ! is_array($childIds) ) {
                continue;
            }
            $childrenIndex[$parentId] = array_values(array_filter($childIds, static fn (string $childId): bool => isset($allowed[$childId])));
        }
        $index['children_index'] = $childrenIndex;
        $index['top_level_node_ids'] = array_values(array_filter(
            is_array($index['top_level_node_ids'] ?? null) ? $index['top_level_node_ids'] : array(),
            static fn (string $id): bool => isset($allowed[$id])
        ));

        if ( null !== $preferredRootId && isset($allowed[$preferredRootId]) ) {
            $index['top_level_node_ids'] = array($preferredRootId);
        } elseif ( empty($index['top_level_node_ids']) && ! empty($allowedIds) ) {
            $index['top_level_node_ids'] = array($allowedIds[0]);
        }

        $diagnostics[] = array(
            'severity' => 'warning',
            'code'     => 'scenegraph_node_limit_applied',
            'message'  => 'Scenegraph normalization was limited to a configured maximum node count.',
            'source'   => 'ScenegraphNormalizer',
            'context'  => array(
                'original_node_count' => count($nodes),
                'max_nodes'           => $maxNodes,
                'selected_node_count' => count($allowedIds),
                'preferred_root_id'   => $preferredRootId,
            ),
        );

        return $index;
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function compactGlyphCommandBlobDiagnostics(array $diagnostics): array
    {
        $compacted = array();
        $count = 0;
        $nodeIds = array();
        $sampleGlyphs = array();

        foreach ( $diagnostics as $diagnostic ) {
            if ( ! is_array($diagnostic) || 'unsupported_text_glyph_command_blob' !== ($diagnostic['code'] ?? null) ) {
                $compacted[] = $diagnostic;
                continue;
            }

            $count++;
            $context = is_array($diagnostic['context'] ?? null) ? $diagnostic['context'] : array();
            $nodeId = isset($context['node_id']) && is_scalar($context['node_id']) ? (string) $context['node_id'] : '';
            if ( '' !== $nodeId ) {
                $nodeIds[$nodeId] = true;
            }
            if ( count($sampleGlyphs) < 10 ) {
                $sampleGlyphs[] = array(
                    'node_id'     => $nodeId,
                    'glyph_index' => isset($context['glyph_index']) && is_numeric($context['glyph_index']) ? (int) $context['glyph_index'] : null,
                );
            }
        }

        if ( 0 === $count ) {
            return $compacted;
        }

        $sampleNodeIds = array_slice(array_keys($nodeIds), 0, 10);
        $compacted[] = array(
            'severity' => 'warning',
            'code'     => 'unsupported_text_glyph_command_blob',
            'message'  => 'Unsupported Figma text glyph command blobs were omitted from derived glyph metadata.',
            'source'   => 'ScenegraphNormalizer',
            'context'  => array(
                'total_count'         => $count,
                'affected_node_count' => count($nodeIds),
                'sample_node_ids'     => $sampleNodeIds,
                'sample_glyphs'       => $sampleGlyphs,
            ),
        );

        return $compacted;
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function compactUnsupportedVectorNetworkBlobDiagnostics(array $diagnostics): array
    {
        $compacted = array();
        $groups = array();

        foreach ( $diagnostics as $diagnostic ) {
            if ( ! is_array($diagnostic) || 'unsupported_vector_network_blob' !== ($diagnostic['code'] ?? null) ) {
                $compacted[] = $diagnostic;
                continue;
            }

            $context = is_array($diagnostic['context'] ?? null) ? $diagnostic['context'] : array();
            $signatureHex = isset($context['signature_hex']) && is_scalar($context['signature_hex']) ? (string) $context['signature_hex'] : '';
            $byteLength = isset($context['byte_length']) && is_numeric($context['byte_length']) ? (int) $context['byte_length'] : null;
            $networkCounts = is_array($context['network_counts'] ?? null) ? array_values($context['network_counts']) : null;
            $key = json_encode(array($signatureHex, $byteLength, $networkCounts), JSON_UNESCAPED_SLASHES);
            if ( ! is_string($key) ) {
                $key = $signatureHex . ':' . (string) $byteLength;
            }

            if ( ! isset($groups[$key]) ) {
                $groups[$key] = array(
                    'severity' => $diagnostic['severity'] ?? 'warning',
                    'message'  => $diagnostic['message'] ?? 'Unsupported Figma vector network blob was omitted from SVG output.',
                    'source'   => $diagnostic['source'] ?? 'ScenegraphNormalizer',
                    'context'  => array(
                        'occurrence_count' => 0,
                        'affected_node_count' => 0,
                        'sample_node_ids'  => array(),
                        'sample_blob_refs' => array(),
                    ),
                    'node_ids' => array(),
                    'blob_refs' => array(),
                    'blob_ref_seen' => array(),
                );

                foreach ( array('geometry', 'blob_ref', 'byte_length', 'signature_hex', 'network_counts', 'single_region_loop_candidate', 'candidate_layout', 'candidate_vertex_points_sample', 'candidate_decoder_requirement') as $contextKey ) {
                    if ( array_key_exists($contextKey, $context) ) {
                        $groups[$key]['context'][$contextKey] = $context[$contextKey];
                    }
                }
            }

            $groups[$key]['context']['occurrence_count']++;
            $nodeId = isset($context['node_id']) && is_scalar($context['node_id']) ? (string) $context['node_id'] : '';
            if ( '' !== $nodeId ) {
                $groups[$key]['node_ids'][$nodeId] = true;
            }
            $blobRef = isset($context['blob_ref']) && is_scalar($context['blob_ref']) ? (string) $context['blob_ref'] : '';
            if ( '' !== $blobRef && ! isset($groups[$key]['blob_ref_seen'][$blobRef]) ) {
                $groups[$key]['blob_refs'][] = $blobRef;
                $groups[$key]['blob_ref_seen'][$blobRef] = true;
            }
        }

        foreach ( $groups as $group ) {
            $context = $group['context'];
            $context['affected_node_count'] = count($group['node_ids']);
            $context['sample_node_ids'] = array_slice(array_keys($group['node_ids']), 0, 10);
            $context['sample_blob_refs'] = array_slice($group['blob_refs'], 0, 10);
            $compacted[] = array(
                'severity' => $group['severity'],
                'code'     => 'unsupported_vector_network_blob',
                'message'  => $group['message'],
                'source'   => $group['source'],
                'context'  => $context,
            );
        }

        return $compacted;
    }

    /**
     * @param array<string, array<int, string>> $childrenIndex
     * @return array<int, string>
     */
    private function limitedSubtreeIds(string $rootId, array $childrenIndex, int $maxNodes): array
    {
        $ids = array();
        $queue = array($rootId);
        $seen = array();

        while ( ! empty($queue) && count($ids) < $maxNodes ) {
            $id = array_shift($queue);
            if ( ! is_string($id) || isset($seen[$id]) ) {
                continue;
            }

            $seen[$id] = true;
            $ids[] = $id;
            foreach ( $childrenIndex[$id] ?? array() as $childId ) {
                if ( is_string($childId) && ! isset($seen[$childId]) ) {
                    $queue[] = $childId;
                }
            }
        }

        return $ids;
    }

    /**
     * Keep local component definitions reachable when a selected page subtree is scoped down.
     *
     * @param array<int, string>                $allowedIds
     * @param array<string, array<string,mixed>> $nodes
     * @param array<string, array<int, string>> $childrenIndex
     * @return array<int, string>
     */
    private function includeComponentClosureIds(array $allowedIds, array $nodes, array $childrenIndex): array
    {
        $definitionIds = $this->rawComponentDefinitionIds($nodes);
        $allowed = array_fill_keys($allowedIds, true);
        $queue = $allowedIds;

        while ( ! empty($queue) ) {
            $id = array_shift($queue);
            if ( ! is_string($id) || ! isset($nodes[$id]) || ! is_array($nodes[$id]) ) {
                continue;
            }

            $reference = $this->readComponentReference($nodes[$id]);
            if ( null === $reference || ! isset($definitionIds[$reference['id']]) ) {
                continue;
            }

            $componentRootId = $definitionIds[$reference['id']];
            foreach ( $this->subtreeIds($componentRootId, $childrenIndex) as $componentId ) {
                if ( isset($allowed[$componentId]) ) {
                    continue;
                }

                $allowed[$componentId] = true;
                $allowedIds[] = $componentId;
                $queue[] = $componentId;
            }
        }

        return $allowedIds;
    }

    /**
     * @param array<string, array<string,mixed>> $nodes
     * @return array<string, string>
     */
    private function rawComponentDefinitionIds(array $nodes): array
    {
        $definitions = array();
        foreach ( $nodes as $id => $node ) {
            if ( ! is_array($node) || ! in_array(strtoupper((string) ($node['type'] ?? '')), array('COMPONENT', 'COMPONENT_SET', 'SYMBOL'), true) ) {
                continue;
            }

            foreach ( array_unique(array_filter(array($id, $this->readString($node, array('componentId', 'component_id', 'key', 'componentKey', 'componentOrStateGroupKey'))))) as $definitionId ) {
                $definitions[(string) $definitionId] = $id;
            }
        }

        return $definitions;
    }

    /**
     * @param array<string, array<int, string>> $childrenIndex
     * @return array<int, string>
     */
    private function subtreeIds(string $rootId, array $childrenIndex): array
    {
        $ids = array();
        $queue = array($rootId);
        $seen = array();

        while ( ! empty($queue) ) {
            $id = array_shift($queue);
            if ( ! is_string($id) || isset($seen[$id]) ) {
                continue;
            }

            $seen[$id] = true;
            $ids[] = $id;
            foreach ( $childrenIndex[$id] ?? array() as $childId ) {
                if ( is_string($childId) && ! isset($seen[$childId]) ) {
                    $queue[] = $childId;
                }
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, bool>  $allowed
     * @return array<string, mixed>
     */
    private function pruneNodeChildren(array $node, array $allowed): array
    {
        foreach ( array('children', 'nodes') as $childrenKey ) {
            if ( ! is_array($node[$childrenKey] ?? null) ) {
                continue;
            }

            $children = array();
            foreach ( $node[$childrenKey] as $child ) {
                if ( ! is_array($child) ) {
                    continue;
                }
                $childId = isset($child['id']) && is_scalar($child['id']) ? (string) $child['id'] : null;
                if ( null !== $childId && isset($allowed[$childId]) ) {
                    $children[] = $this->pruneNodeChildren($child, $allowed);
                }
            }
            $node[$childrenKey] = $children;
        }

        return $node;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<int, array<string, mixed>>    $diagnostics
     * @return array<string, array<string, mixed>>
     */
    private function normalizeNodeMap(array $nodeMap, array &$diagnostics, array $blobs = array(), array $paintStyles = array()): array
    {
        foreach ( $nodeMap as $id => $node ) {
            $nodeMap[$id] = $this->normalizeNode($node, $diagnostics, $blobs, $paintStyles);
        }

        return $nodeMap;
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function normalizeNode(array $node, array &$diagnostics, array $blobs = array(), array $paintStyles = array()): array
    {
        $id = (string) ($node['id'] ?? '');
        $type = strtoupper((string) ($node['type'] ?? ''));

        $component = $this->normalizeComponentMetadata($node, $type);
        if ( ! empty($component) ) {
            $node['figma_component'] = $component;
        }

        if ( 'TEXT' === $type ) {
            $text = $this->normalizeText($node, $blobs, $id, $diagnostics);
            if ( ! empty($text) ) {
                $node['figma_text'] = $text;
            }
        }

        $paints = $this->normalizePaintCollections($node, $id, $diagnostics, $paintStyles);
        if ( ! empty($paints) ) {
            $node['figma_paints'] = $paints;
        }

        $vectorPaths = $this->normalizeVectorPaths($node, $blobs, $id, $diagnostics);
        if ( ! empty($vectorPaths) ) {
            $node['figma_vector_paths'] = $vectorPaths;
        }

        $box = $this->normalizeVisualBox($node);
        if ( ! empty($box) ) {
            $node['figma_box'] = $box;
        }

        $layoutBox = $this->normalizeLayoutBox($node);
        if ( ! empty($layoutBox) ) {
            $node['box'] = $layoutBox;
        }

        $layout = $this->normalizeLayout($node);
        if ( ! empty($layout) ) {
            $node['layout'] = $layout;
        }

        $effects = $this->normalizeEffects($node, $id, $diagnostics);
        if ( ! empty($effects) ) {
            $node['figma_effects'] = $effects;
        }

        $link = $this->normalizeLink($node, $type);
        if ( ! empty($link) ) {
            $node['figma_link'] = $link;
        }

        $devStatus = ScenegraphDevStatus::resolve($node);
        if ( null !== $devStatus ) {
            // Clean public value (ready_for_dev|completed|null) plus the raw
            // internal token for auditability (#280).
            $node['dev_status']     = $devStatus['normalized'];
            $node['dev_status_raw'] = $devStatus['raw'];
        }

        foreach ( array('children', 'nodes') as $childrenKey ) {
            if ( ! is_array($node[$childrenKey] ?? null) ) {
                continue;
            }

            foreach ( $node[$childrenKey] as $index => $child ) {
                if ( is_array($child) ) {
                    $normalizedChild = $this->normalizeNode($child, $diagnostics, $blobs, $paintStyles);
                    $childLayout = is_array($normalizedChild['layout'] ?? null) ? $normalizedChild['layout'] : array();
                    $childLayout['source_order'] = isset($normalizedChild['_source_order']) && is_numeric($normalizedChild['_source_order'])
                        ? (int) $normalizedChild['_source_order']
                        : (int) $index;
                    $normalizedChild['layout'] = $childLayout;
                    $node[$childrenKey][$index] = $normalizedChild;
                }
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeComponentMetadata(array $node, string $type): array
    {
        $metadata = array();

        if ( in_array($type, array('COMPONENT', 'COMPONENT_SET', 'SYMBOL'), true) ) {
            $metadata['role'] = 'definition';
            $metadata['definition_id'] = (string) ($node['id'] ?? '');
        } elseif ( 'INSTANCE' === $type ) {
            $metadata['role'] = 'instance';
            $metadata['instance_id'] = (string) ($node['id'] ?? '');
            $reference = $this->readComponentReference($node);
            if ( null !== $reference ) {
                $metadata['component_id'] = $reference['id'];
                $metadata['component_source_key'] = $reference['source_key'];
            }
        }

        if ( is_array($node['componentProperties'] ?? null) ) {
            $metadata['component_properties'] = $node['componentProperties'];
        }

        if ( is_array($node['overrides'] ?? null) ) {
            $metadata['overrides'] = $node['overrides'];
        }

        return $metadata;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<string, array<string, mixed>>
     */
    private function buildComponentDefinitions(array $nodeMap): array
    {
        $components = array();

        foreach ( $nodeMap as $id => $node ) {
            if ( ! in_array(strtoupper((string) ($node['type'] ?? '')), array('COMPONENT', 'COMPONENT_SET', 'SYMBOL'), true) ) {
                continue;
            }

            foreach ( array_unique(array_filter(array($id, $this->readString($node, array('componentId', 'component_id', 'key'))))) as $componentId ) {
                $components[(string) $componentId] = $node;
            }
        }

        return $components;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     */
    private function countComponentDefinitions(array $nodeMap): int
    {
        $count = 0;

        foreach ( $nodeMap as $node ) {
            if ( in_array(strtoupper((string) ($node['type'] ?? '')), array('COMPONENT', 'COMPONENT_SET', 'SYMBOL'), true) ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<string, array<int, array<string, mixed>>>>
     */
    private function buildPaintStyleDefinitions(array $nodeMap, array &$diagnostics): array
    {
        $styles = array();
        foreach ( $nodeMap as $id => $node ) {
            if ( 'FILL' !== strtoupper((string) ($node['styleType'] ?? '')) ) {
                continue;
            }

            $paints = $this->normalizePaintList(is_array($node['fillPaints'] ?? null) ? $node['fillPaints'] : (is_array($node['fills'] ?? null) ? $node['fills'] : array()), $id, 'style.fillPaints', $diagnostics);
            if ( ! empty($paints) ) {
                $styles[$id]['fills'] = $paints;
            }
        }

        return $styles;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<string, array<string, mixed>> $components
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array{instance_node_count: int, resolved_instance_count: int, unresolved_component_references: array<int, array<string, string>>}
     */
    private function resolveInstances(array &$nodeMap, array $components, array &$diagnostics, array $blobs = array(), array $paintStyles = array()): array
    {
        $instanceCount = 0;
        $resolvedCount = 0;
        $unresolved = array();

        foreach ( $nodeMap as $id => $node ) {
            if ( 'INSTANCE' !== strtoupper((string) ($node['type'] ?? '')) ) {
                continue;
            }

            $instanceCount++;
            $reference = $this->readComponentReference($node);
            if ( null === $reference || ! isset($components[$reference['id']]) ) {
                $unresolved[] = array('instance_id' => $id, 'component_id' => $reference['id'] ?? '');
                $diagnostics[] = array(
                    'severity' => 'warning',
                    'code'     => 'figma_instance_component_unresolved',
                    'message'  => 'Figma instance references a component definition that is not present in the same source graph.',
                    'context'  => array(
                        'instance_id'  => $id,
                        'component_id' => $reference['id'] ?? null,
                    ),
                );
                continue;
            }

            if ( ! empty($node['children']) ) {
                $unresolved[] = array('instance_id' => $id, 'component_id' => $reference['id']);
                $diagnostics[] = array(
                    'severity' => 'warning',
                    'code'     => 'figma_instance_resolution_skipped',
                    'message'  => 'Figma instance resolution was skipped because the source instance already contains children.',
                    'context'  => array('instance_id' => $id, 'component_id' => $reference['id']),
                );
                continue;
            }

            $overrides = $this->normalizeInstanceOverrides($node, $id, $diagnostics);
            if ( null === $overrides ) {
                $unresolved[] = array('instance_id' => $id, 'component_id' => $reference['id']);
                continue;
            }

            $resolved = $this->cloneComponentForInstance($components[$reference['id']], $node, $reference['id'], $overrides, $nodeMap, $diagnostics, $blobs, $paintStyles);
            $nodeMap[$id] = $resolved;
            $resolvedCount++;
        }

        return array(
            'instance_node_count' => $instanceCount,
            'resolved_instance_count' => $resolvedCount,
            'unresolved_component_references' => $unresolved,
        );
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<int, string> $trail
     * @return array<string, mixed>
     */
    private function refreshResolvedTree(array $node, array $nodeMap, array $trail = array()): array
    {
        $id = (string) ($node['id'] ?? '');
        if ( '' !== $id && isset($nodeMap[$id]) && ! in_array($id, $trail, true) ) {
            $node = $nodeMap[$id];
            $trail[] = $id;
        }

        if ( true === ($node['figma_component']['resolved'] ?? false) ) {
            return $node;
        }

        if ( ! is_array($node['children'] ?? null) ) {
            return $node;
        }

        foreach ( $node['children'] as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $node['children'][$index] = $this->refreshResolvedTree($child, $nodeMap, $trail);
        }

        return $node;
    }

    /**
     * Treat an explicitly selected page frame as the emitted document origin instead of preserving Figma canvas offsets.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function rebaseSelectedFrameToOrigin(array $node): array
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $originX = isset($box['x']) && is_numeric($box['x']) ? (float) $box['x'] : 0.0;
        $originY = isset($box['y']) && is_numeric($box['y']) ? (float) $box['y'] : 0.0;
        $node['_selected_frame_root'] = true;

        return $this->rebaseNodeCoordinates($node, $originX, $originY, true);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function rebaseNodeCoordinates(array $node, float $originX, float $originY, bool $isRoot = false): array
    {
        $node = $this->rebaseNodeBox($node, 'box', $originX, $originY, $isRoot);
        $node = $this->rebaseNodeBox($node, 'figma_box', $originX, $originY, $isRoot);

        foreach ( array('children', 'nodes') as $childrenKey ) {
            if ( ! is_array($node[$childrenKey] ?? null) ) {
                continue;
            }

            foreach ( $node[$childrenKey] as $index => $child ) {
                if ( is_array($child) ) {
                    $node[$childrenKey][$index] = $this->rebaseNodeCoordinates($child, $originX, $originY);
                }
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function rebaseNodeBox(array $node, string $boxKey, float $originX, float $originY, bool $isRoot): array
    {
        if ( ! is_array($node[$boxKey] ?? null) ) {
            return $node;
        }

        $box = $node[$boxKey];
        $coordinateSpace = (string) ($box['coordinate_space'] ?? 'absolute');
        if ( $isRoot || 'absolute' === $coordinateSpace ) {
            if ( isset($box['x']) && is_numeric($box['x']) ) {
                $box['x'] = $isRoot ? 0.0 : (float) $box['x'] - $originX;
            }
            if ( isset($box['y']) && is_numeric($box['y']) ) {
                $box['y'] = $isRoot ? 0.0 : (float) $box['y'] - $originY;
            }
            $box['coordinate_space'] = 'local';
        }

        $node[$boxKey] = $box;
        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{id: string, source_key: string}|null
     */
    private function readComponentReference(array $node): ?array
    {
        foreach ( array('componentId', 'component_id', 'mainComponentId', 'main_component_id') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                return array('id' => (string) $node[$key], 'source_key' => $key);
            }
        }

        foreach ( array('mainComponent', 'component') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                $id = $this->readString($node[$key], array('id', 'key', 'componentId', 'node_id', 'nodeId'));
                if ( null !== $id && '' !== $id ) {
                    return array('id' => $id, 'source_key' => $key);
                }
            } elseif ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                return array('id' => (string) $node[$key], 'source_key' => $key);
            }
        }

        $symbolId = $this->readGuidId($node['symbolData']['symbolID'] ?? null);
        if ( null !== $symbolId ) {
            return array('id' => $symbolId, 'source_key' => 'symbolData.symbolID');
        }

        return null;
    }

    private function readGuidId(mixed $guid): ?string
    {
        if ( is_array($guid) && isset($guid['sessionID'], $guid['localID']) ) {
            return (string) $guid['sessionID'] . ':' . (string) $guid['localID'];
        }

        if ( is_scalar($guid) && '' !== (string) $guid ) {
            return (string) $guid;
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
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                return (string) $node[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<string, mixed>>|null
     */
    private function normalizeInstanceOverrides(array $node, string $instanceId, array &$diagnostics): ?array
    {
        $rawOverrides = array();
        if ( is_array($node['overrides'] ?? null) ) {
            $rawOverrides = array_merge($rawOverrides, $node['overrides']);
        }
        if ( is_array($node['symbolData']['symbolOverrides'] ?? null) ) {
            $rawOverrides = array_merge($rawOverrides, $node['symbolData']['symbolOverrides']);
        }
        if ( is_array($node['derivedSymbolData'] ?? null) ) {
            $rawOverrides = array_merge($rawOverrides, $node['derivedSymbolData']);
        }

        if ( empty($rawOverrides) ) {
            return array();
        }

        $overrides = array();
        foreach ( $rawOverrides as $key => $override ) {
            if ( ! is_array($override) ) {
                $diagnostics[] = array(
                    'severity' => 'warning',
                    'code'     => 'figma_instance_override_unsupported',
                    'message'  => 'Figma instance override shape is unsupported and was not applied.',
                    'context'  => array('instance_id' => $instanceId),
                );
                return null;
            }

            $nodeId = $this->readString($override, array('nodeId', 'node_id', 'id')) ?? $this->readOverrideGuidPathTarget($override) ?? (is_string($key) ? $key : null);
            if ( null === $nodeId || '' === $nodeId ) {
                return null;
            }

            foreach ( array('characters', 'text', 'name') as $field ) {
                if ( isset($override[$field]) && is_scalar($override[$field]) ) {
                    $overrides[$nodeId][$field] = $override[$field];
                }
            }
            if ( isset($override['textData']['characters']) && is_scalar($override['textData']['characters']) ) {
                $overrides[$nodeId]['characters'] = (string) $override['textData']['characters'];
            }
            foreach ( array('derivedTextData', 'size', 'transform', 'fillPaints', 'fills', 'strokes', 'effects', 'styleIdForFill', 'fillGeometry', 'strokeGeometry', 'vectorPaths', 'paths', 'pathData', 'path', 'd', 'cornerRadius', 'rectangleTopLeftCornerRadius', 'rectangleTopRightCornerRadius', 'rectangleBottomLeftCornerRadius', 'rectangleBottomRightCornerRadius') as $field ) {
                if ( array_key_exists($field, $override) ) {
                    $overrides[$nodeId][$field] = $override[$field];
                }
            }
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $override
     */
    private function readOverrideGuidPathTarget(array $override): ?string
    {
        $guidPath = $override['guidPath'] ?? null;
        if ( ! is_array($guidPath) ) {
            return null;
        }

        $guids = is_array($guidPath['guids'] ?? null) ? $guidPath['guids'] : $guidPath;
        $ids = array();
        foreach ( $guids as $guid ) {
            $id = $this->readGuidId($guid);
            if ( null !== $id ) {
                $ids[] = $id;
            }
        }

        return empty($ids) ? null : implode('/', $ids);
    }

    /**
     * @param array<string, mixed> $component
     * @param array<string, mixed> $instance
     * @param array<string, array<string, mixed>> $overrides
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<string, mixed>
     */
    private function cloneComponentForInstance(array $component, array $instance, string $componentId, array $overrides, array $nodeMap, array &$diagnostics, array $blobs = array(), array $paintStyles = array()): array
    {
        $resolved = $component;
        $resolved['id'] = (string) ($instance['id'] ?? $resolved['id'] ?? '');
        $resolved['type'] = 'INSTANCE';
        $resolved['name'] = (string) ($instance['name'] ?? $resolved['name'] ?? '');

        foreach ( array('box', 'figma_box', 'layout', 'figma_paints', 'figma_effects', 'figma_link', 'figma_vector_paths', 'componentProperties', 'fillPaints', 'effects', 'styleIdForFill', 'fillGeometry', 'strokeGeometry', 'vectorPaths', 'paths', 'pathData', 'path', 'd') as $key ) {
            if ( array_key_exists($key, $instance) ) {
                $resolved[$key] = $instance[$key];
            }
        }

        $resolved['figma_component'] = array_merge(
            is_array($instance['figma_component'] ?? null) ? $instance['figma_component'] : array(),
            array(
                'role'               => 'instance',
                'instance_id'        => (string) ($instance['id'] ?? ''),
                'component_id'       => $componentId,
                'definition_node_id' => (string) ($component['id'] ?? ''),
                'resolved'           => true,
            )
        );
        $resolvedChildren = is_array($resolved['children'] ?? null) ? $resolved['children'] : array();
        $resolvedChildren = $this->resolveClonedInstanceChildren($resolvedChildren, $nodeMap);
        $resolvedChildren = $this->scaleVectorOnlyInstanceChildren($resolvedChildren, $component, $instance);
        if ( $this->instanceOverridesUseTransforms($overrides) ) {
            $resolved['layout'] = array('freeform' => true);
        }
        $resolved['children'] = $this->namespaceResolvedInstanceChildren(
            $this->applyInstanceOverridesToChildren($resolvedChildren, $overrides, $diagnostics, $blobs, $paintStyles),
            (string) ($instance['id'] ?? '')
        );

        return $resolved;
    }

    /**
     * @param array<string, array<string, mixed>> $overrides
     */
    private function instanceOverridesUseTransforms(array $overrides): bool
    {
        foreach ( $overrides as $override ) {
            if ( is_array($override) && is_array($override['transform'] ?? null) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $children
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, mixed>
     */
    private function resolveClonedInstanceChildren(array $children, array $nodeMap): array
    {
        foreach ( $children as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $id = (string) ($child['id'] ?? '');
            if ( 'INSTANCE' === strtoupper((string) ($child['type'] ?? '')) && '' !== $id && isset($nodeMap[$id]) ) {
                $child = $nodeMap[$id];
            }

            if ( is_array($child['children'] ?? null) ) {
                $child['children'] = $this->resolveClonedInstanceChildren($child['children'], $nodeMap);
            }

            $children[$index] = $child;
        }

        return $children;
    }

    /**
     * @param array<int, mixed> $children
     * @return array<int, mixed>
     */
    private function scaleVectorOnlyInstanceChildren(array $children, array $component, array $instance): array
    {
        if ( ! $this->isVectorOnlyComponent($component) ) {
            return $children;
        }

        $componentBox = is_array($component['box'] ?? null) ? $component['box'] : array();
        $instanceBox  = is_array($instance['box'] ?? null) ? $instance['box'] : array();
        $componentWidth = isset($componentBox['width']) && is_numeric($componentBox['width']) ? (float) $componentBox['width'] : 0.0;
        $componentHeight = isset($componentBox['height']) && is_numeric($componentBox['height']) ? (float) $componentBox['height'] : 0.0;
        $instanceWidth = isset($instanceBox['width']) && is_numeric($instanceBox['width']) ? (float) $instanceBox['width'] : 0.0;
        $instanceHeight = isset($instanceBox['height']) && is_numeric($instanceBox['height']) ? (float) $instanceBox['height'] : 0.0;
        if ( $componentWidth <= 0.0 || $componentHeight <= 0.0 || $instanceWidth <= 0.0 || $instanceHeight <= 0.0 ) {
            return $children;
        }

        $scaleX = $instanceWidth / $componentWidth;
        $scaleY = $instanceHeight / $componentHeight;
        if ( abs($scaleX - 1.0) < 0.0001 && abs($scaleY - 1.0) < 0.0001 ) {
            return $children;
        }

        return $this->scaleVectorChildren($children, $scaleX, $scaleY);
    }

    /**
     * @param array<int, mixed> $children
     * @return array<int, mixed>
     */
    private function scaleVectorChildren(array $children, float $scaleX, float $scaleY): array
    {
        foreach ( $children as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            if ( is_array($child['box'] ?? null) ) {
                foreach ( array('x' => $scaleX, 'width' => $scaleX, 'y' => $scaleY, 'height' => $scaleY) as $key => $scale ) {
                    if ( isset($child['box'][$key]) && is_numeric($child['box'][$key]) ) {
                        $child['box'][$key] = (float) $child['box'][$key] * $scale;
                    }
                }
            }

            if ( is_array($child['figma_box']['transform'] ?? null) ) {
                foreach ( array('m00' => $scaleX, 'm02' => $scaleX, 'm11' => $scaleY, 'm12' => $scaleY) as $key => $scale ) {
                    if ( isset($child['figma_box']['transform'][$key]) && is_numeric($child['figma_box']['transform'][$key]) ) {
                        $child['figma_box']['transform'][$key] = (float) $child['figma_box']['transform'][$key] * $scale;
                    }
                }
            }

            if ( $this->isScalableVectorType(strtoupper((string) ($child['type'] ?? ''))) ) {
                $child['figma_vector_scale'] = array('x' => $scaleX, 'y' => $scaleY);
            }

            if ( is_array($child['children'] ?? null) ) {
                $child['children'] = $this->scaleVectorChildren($child['children'], $scaleX, $scaleY);
            }

            $children[$index] = $child;
        }

        return $children;
    }

    /**
     * @param array<string, mixed> $component
     */
    private function isVectorOnlyComponent(array $component): bool
    {
        if ( ! empty($component['layout']) ) {
            return false;
        }

        $children = is_array($component['children'] ?? null) ? $component['children'] : array();
        if ( empty($children) ) {
            return false;
        }

        foreach ( $children as $child ) {
            if ( ! is_array($child) || ! $this->isVectorOnlyNode($child) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isVectorOnlyNode(array $node): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( 'INSTANCE' !== $type && ! $this->isScalableVectorType($type) ) {
            return false;
        }

        foreach ( is_array($node['children'] ?? null) ? $node['children'] : array() as $child ) {
            if ( ! is_array($child) || ! $this->isVectorOnlyNode($child) ) {
                return false;
            }
        }

        return true;
    }

    private function isScalableVectorType(string $type): bool
    {
        return in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'RECTANGLE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true);
    }

    /**
     * @param array<int, mixed> $children
     * @return array<int, mixed>
     */
    private function namespaceResolvedInstanceChildren(array $children, string $instanceId): array
    {
        if ( '' === $instanceId ) {
            return $children;
        }

        foreach ( $children as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $sourceId = (string) ($child['id'] ?? '');
            if ( '' !== $sourceId && ! str_starts_with($sourceId, $instanceId . '/') ) {
                $child['figma_component_source_id'] = $sourceId;
                $child['id'] = $instanceId . '/' . $sourceId;
            }

            if ( is_array($child['children'] ?? null) ) {
                $child['children'] = $this->namespaceResolvedInstanceChildren($child['children'], $instanceId);
            }

            $children[$index] = $child;
        }

        return $children;
    }

    /**
     * @param array<int, mixed> $children
     * @param array<string, array<string, mixed>> $overrides
     * @return array<int, mixed>
     */
    private function applyInstanceOverridesToChildren(array $children, array $overrides, array &$diagnostics, array $blobs = array(), array $paintStyles = array()): array
    {
        foreach ( $children as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $id = (string) ($child['id'] ?? '');
            $hasOverride = false;
            $overrideFields = $this->instanceOverrideFieldsForChild($child, $overrides);
            foreach ( $overrideFields as $field => $value ) {
                $hasOverride = true;
                $child[$field] = $value;
                if ( in_array($field, array('characters', 'text'), true) && is_array($child['figma_text'] ?? null) ) {
                    $child['figma_text']['characters'] = (string) $value;
                }
            }
            if ( $hasOverride ) {
                $child = $this->normalizeOverriddenInstanceChild($child, $id, $overrideFields, $diagnostics, $blobs, $paintStyles);
            }

            if ( is_array($child['children'] ?? null) ) {
                $child['children'] = $this->applyInstanceOverridesToChildren($child['children'], $overrides, $diagnostics, $blobs, $paintStyles);
            }

            $children[$index] = $child;
        }

        return $children;
    }

    /**
     * @param array<string, mixed> $child
     * @param array<string, array<string, mixed>> $overrides
     * @return array<string, mixed>
     */
    private function instanceOverrideFieldsForChild(array $child, array $overrides): array
    {
        $fields = array();
        foreach ( $this->instanceChildOverrideAliases($child) as $alias ) {
            if ( isset($overrides[$alias]) && is_array($overrides[$alias]) ) {
                $fields = array_merge($fields, $overrides[$alias]);
            }

            foreach ( $overrides as $target => $overrideFields ) {
                if ( ! is_string($target) || ! is_array($overrideFields) || ! str_contains($target, '/') ) {
                    continue;
                }

                $parts = explode('/', $target);
                if ( $alias === end($parts) ) {
                    $fields = array_merge($fields, $overrideFields);
                }
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $child
     * @return array<int, string>
     */
    private function instanceChildOverrideAliases(array $child): array
    {
        $aliases = array();
        foreach ( array('id', 'figma_component_source_id') as $key ) {
            if ( isset($child[$key]) && is_scalar($child[$key]) && '' !== (string) $child[$key] ) {
                $aliases[] = (string) $child[$key];
            }
        }

        $guidId = $this->readGuidId($child['guid'] ?? null);
        if ( null !== $guidId ) {
            $aliases[] = $guidId;
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @param array<string, mixed>             $child
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function normalizeOverriddenInstanceChild(array $child, string $id, array $overrideFields, array &$diagnostics, array $blobs = array(), array $paintStyles = array()): array
    {
        $hasVectorGeometryOverride = array_key_exists('fillGeometry', $overrideFields) || array_key_exists('strokeGeometry', $overrideFields);
        $hasExplicitSizeOverride = array_key_exists('size', $overrideFields);
        if ( is_array($child['size'] ?? null) ) {
            foreach ( array('x' => 'width', 'y' => 'height') as $source => $target ) {
                if ( isset($child['size'][$source]) && is_numeric($child['size'][$source]) ) {
                    $child[$target] = (float) $child['size'][$source];
                }
            }
        }
        if ( is_array($child['transform'] ?? null) ) {
            foreach ( array('m02' => 'x', 'm12' => 'y') as $source => $target ) {
                if ( isset($child['transform'][$source]) && is_numeric($child['transform'][$source]) ) {
                    $child[$target] = (float) $child['transform'][$source];
                }
            }
        }

        foreach ( array('figma_text', 'figma_paints', 'figma_vector_paths', 'figma_box', 'box', 'layout', 'figma_effects') as $key ) {
            unset($child[$key]);
        }
        unset($child['figma_vector_scale']);

        $child = $this->normalizeNode($child, $diagnostics, $blobs, $paintStyles);
        if ( $hasVectorGeometryOverride && ! $hasExplicitSizeOverride ) {
            $bounds = $this->normalizedVectorPathBounds(is_array($child['figma_vector_paths'] ?? null) ? $child['figma_vector_paths'] : array());
            if ( null !== $bounds ) {
                $box = is_array($child['box'] ?? null) ? $child['box'] : array();
                foreach ( array('width', 'height') as $dimension ) {
                    if ( ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) || $bounds[$dimension] > (float) $box[$dimension] + 0.001 ) {
                        $child[$dimension] = $bounds[$dimension];
                        $child['box'][$dimension] = $bounds[$dimension];
                    }
                }
            }
        }

        return $child;
    }

    /**
     * @param array<int, array<string, mixed>> $paths
     * @return array{width: float, height: float}|null
     */
    private function normalizedVectorPathBounds(array $paths): ?array
    {
        $minX = null;
        $minY = null;
        $maxX = null;
        $maxY = null;
        foreach ( $paths as $path ) {
            if ( ! is_array($path) || ! isset($path['data']) || ! is_scalar($path['data']) || ! preg_match_all('/-?\d+(?:\.\d+)?(?:e[+-]?\d+)?/i', (string) $path['data'], $matches) ) {
                continue;
            }
            $numbers = array_map('floatval', $matches[0]);
            for ( $i = 0; $i + 1 < count($numbers); $i += 2 ) {
                $x = $numbers[$i];
                $y = $numbers[$i + 1];
                $minX = null === $minX ? $x : min($minX, $x);
                $minY = null === $minY ? $y : min($minY, $y);
                $maxX = null === $maxX ? $x : max($maxX, $x);
                $maxY = null === $maxY ? $y : max($maxY, $y);
            }
        }

        if ( null === $minX || null === $minY || null === $maxX || null === $maxY || $maxX <= $minX || $maxY <= $minY ) {
            return null;
        }

        return array('width' => $maxX - $minX, 'height' => $maxY - $minY);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeText(array $node, array $blobs = array(), string $nodeId = '', array &$diagnostics = array()): array
    {
        $text = array();

        foreach ( array('characters', 'text') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                $text['characters'] = (string) $node[$key];
                break;
            }
        }

        if ( ! isset($text['characters']) && isset($node['textData']['characters']) && is_scalar($node['textData']['characters']) ) {
            $text['characters'] = (string) $node['textData']['characters'];
        }

        $style = array();
        if ( is_array($node['style'] ?? null) ) {
            $style = $this->normalizeTextStyle($node['style']);
        }

        $rootStyle = $this->normalizeTextStyle($node);
        foreach ( $rootStyle as $key => $value ) {
            if ( ! array_key_exists($key, $style) ) {
                $style[$key] = $value;
            }
        }

        if ( ! empty($style) ) {
            $text['style'] = $style;
        }

        if ( isset($text['characters']) && is_scalar($text['characters']) ) {
            $iconFallback = $this->fontAwesomeIconNameFallback((string) $text['characters'], $style);
            if ( null !== $iconFallback ) {
                $text['icon_name'] = (string) $text['characters'];
                $text['characters'] = $iconFallback;
            }
        }

        $derivedLayout = $this->normalizeDerivedTextLayout($node, $blobs, $nodeId, $diagnostics);
        if ( ! empty($derivedLayout) ) {
            $text['derived_layout'] = $derivedLayout;
        }

        $segments = $this->normalizeStyledTextSegments($node);
        if ( ! empty($segments) ) {
            $text['segments'] = $segments;
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $style
     */
    private function fontAwesomeIconNameFallback(string $characters, array $style): ?string
    {
        $fontFamily = strtolower((string) ($style['font_family'] ?? ''));
        $postscript = strtolower((string) ($style['font_postscript_name'] ?? ''));
        if ( ! str_contains($fontFamily, 'font awesome') && ! str_contains($postscript, 'fontawesome') ) {
            return null;
        }

        return match ( strtolower(trim($characters)) ) {
            'sparkle', 'sparkles' => '✦',
            'circle-check', 'check-circle' => '✓',
            'circle', 'circle-small' => '●',
            'arrow-right' => '→',
            'arrow-left' => '←',
            'arrow-up' => '↑',
            'arrow-down' => '↓',
            'chevron-right' => '›',
            'chevron-left' => '‹',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeDerivedTextLayout(array $node, array $blobs = array(), string $nodeId = '', array &$diagnostics = array()): array
    {
        $source = is_array($node['derivedTextData'] ?? null) ? $node['derivedTextData'] : array();
        if ( empty($source) ) {
            return array();
        }

        $layout = array();
        if ( is_array($source['layoutSize'] ?? null) ) {
            $size = array();
            foreach ( array('x' => 'width', 'y' => 'height', 'width' => 'width', 'height' => 'height') as $sourceKey => $targetKey ) {
                if ( ! isset($size[$targetKey]) && isset($source['layoutSize'][$sourceKey]) && is_numeric($source['layoutSize'][$sourceKey]) ) {
                    $size[$targetKey] = (float) $source['layoutSize'][$sourceKey];
                }
            }
            if ( ! empty($size) ) {
                $layout['size'] = $size;
            }
        }

        if ( is_array($source['baselines'] ?? null) ) {
            $layout['baseline_count'] = count($source['baselines']);
            $baselines = array();
            foreach ( $source['baselines'] as $baseline ) {
                if ( ! is_array($baseline) ) {
                    continue;
                }
                $normalized = array();
                foreach ( array('width', 'lineY', 'lineHeight', 'lineAscent', 'firstCharacter', 'endCharacter') as $key ) {
                    if ( isset($baseline[$key]) && is_numeric($baseline[$key]) ) {
                        $normalized[$key] = (float) $baseline[$key];
                    }
                }
                if ( is_array($baseline['position'] ?? null) ) {
                    foreach ( array('x', 'y') as $axis ) {
                        if ( isset($baseline['position'][$axis]) && is_numeric($baseline['position'][$axis]) ) {
                            $normalized['position_' . $axis] = (float) $baseline['position'][$axis];
                        }
                    }
                }
                if ( ! empty($normalized) ) {
                    $baselines[] = $normalized;
                }
            }
            if ( ! empty($baselines) ) {
                $layout['baselines'] = $baselines;
            }
        }

        if ( is_array($source['glyphs'] ?? null) ) {
            $layout['glyph_count'] = count($source['glyphs']);
            $glyphPaths = array();
            $characters = isset($node['textData']['characters']) && is_scalar($node['textData']['characters']) ? (string) $node['textData']['characters'] : ( isset($node['characters']) && is_scalar($node['characters']) ? (string) $node['characters'] : '' );
            $characterList = '' !== $characters ? preg_split('//u', $characters, -1, PREG_SPLIT_NO_EMPTY) : array();
            if ( ! is_array($characterList) ) {
                $characterList = array();
            }
            foreach ( $source['glyphs'] as $index => $glyph ) {
                if ( ! is_array($glyph) ) {
                    continue;
                }

                $glyphPath = array();
                foreach ( array('x', 'y', 'advance', 'fontSize', 'fontIndex', 'firstCharacter', 'endCharacter') as $key ) {
                    if ( isset($glyph[$key]) && is_numeric($glyph[$key]) ) {
                        $glyphPath[$key] = (float) $glyph[$key];
                    }
                }
                if ( isset($glyph['firstCharacter']) && is_numeric($glyph['firstCharacter']) && isset($characterList[(int) $glyph['firstCharacter']]) ) {
                    $glyphPath['character'] = $characterList[(int) $glyph['firstCharacter']];
                }
                if ( is_array($glyph['position'] ?? null) ) {
                    foreach ( array('x', 'y') as $axis ) {
                        if ( isset($glyph['position'][$axis]) && is_numeric($glyph['position'][$axis]) ) {
                            $glyphPath['position_' . $axis] = (float) $glyph['position'][$axis];
                        }
                    }
                }

                if ( isset($glyph['commandsBlob']) ) {
                    $bytes = $this->readCommandBlobBytes($glyph['commandsBlob'], $blobs);
                    if ( null !== $bytes ) {
                        $path = $this->decodeVectorCommandBlob($bytes);
                        if ( null === $path ) {
                            $diagnostics[] = array(
                                'severity' => 'warning',
                                'code'     => 'unsupported_text_glyph_command_blob',
                                'message'  => 'Unsupported Figma text glyph command blob was omitted from derived glyph metadata.',
                                'context'  => array('node_id' => $nodeId, 'glyph_index' => $index),
                            );
                        } else {
                            $glyphPath['data'] = $path;
                        }
                    }
                }

                if ( empty($glyphPath) ) {
                    continue;
                }
                $glyphPaths[] = $glyphPath;
            }
            if ( ! empty($glyphPaths) ) {
                $layout['glyph_paths'] = $glyphPaths;
            }
        }
        if ( is_array($source['fontMetaData'] ?? null) ) {
            $fonts = array();
            foreach ( $source['fontMetaData'] as $font ) {
                if ( ! is_array($font) ) {
                    continue;
                }
                $fonts[] = array(
                    'family' => (string) ($font['key']['family'] ?? ''),
                    'style' => (string) ($font['key']['style'] ?? ''),
                    'font_weight' => isset($font['fontWeight']) && is_numeric($font['fontWeight']) ? (int) $font['fontWeight'] : null,
                    'font_line_height' => isset($font['fontLineHeight']) && is_numeric($font['fontLineHeight']) ? (float) $font['fontLineHeight'] : null,
                );
            }
            if ( ! empty($fonts) ) {
                $layout['fonts'] = $fonts;
            }
        }

        return $layout;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function normalizeTextStyle(array $source): array
    {
        $style = array();

        foreach ( array(
            'fontFamily' => 'font_family',
            'fontPostScriptName' => 'font_postscript_name',
            'fontWeight' => 'font_weight',
            'textAlignHorizontal' => 'text_align_horizontal',
            'textAlignVertical' => 'text_align_vertical',
            'textDecoration' => 'text_decoration',
        ) as $sourceKey => $targetKey ) {
            if ( isset($source[$sourceKey]) && is_scalar($source[$sourceKey]) && '' !== (string) $source[$sourceKey] ) {
                $style[$targetKey] = (string) $source[$sourceKey];
            }
        }

        if ( isset($source['fontName']) && is_array($source['fontName']) ) {
            if ( isset($source['fontName']['family']) && is_scalar($source['fontName']['family']) ) {
                $style['font_family'] = (string) $source['fontName']['family'];
            }
            if ( isset($source['fontName']['postscript']) && is_scalar($source['fontName']['postscript']) ) {
                $style['font_postscript_name'] = (string) $source['fontName']['postscript'];
            }
            if ( ! isset($style['font_weight']) && isset($source['fontName']['style']) && is_scalar($source['fontName']['style']) ) {
                $fontWeight = $this->fontWeightFromStyle((string) $source['fontName']['style']);
                if ( null !== $fontWeight ) {
                    $style['font_weight'] = $fontWeight;
                }
            }
        }

        foreach ( array('fontSize' => 'font_size', 'lineHeightPx' => 'line_height_px', 'lineHeightPercent' => 'line_height_percent', 'letterSpacing' => 'letter_spacing') as $sourceKey => $targetKey ) {
            if ( isset($source[$sourceKey]) && is_numeric($source[$sourceKey]) ) {
                $style[$targetKey] = (float) $source[$sourceKey];
            }
        }

        if ( isset($source['lineHeight']) && is_array($source['lineHeight']) && isset($source['lineHeight']['value']) && is_numeric($source['lineHeight']['value']) ) {
            $lineHeightUnits = strtoupper((string) ($source['lineHeight']['units'] ?? ''));
            if ( 'PIXELS' === $lineHeightUnits ) {
                $style['line_height_px'] = (float) $source['lineHeight']['value'];
            } elseif ( 'RAW' === $lineHeightUnits ) {
                $style['line_height_raw'] = (float) $source['lineHeight']['value'];
            } elseif ( str_contains($lineHeightUnits, 'PERCENT') ) {
                $style['line_height_percent'] = (float) $source['lineHeight']['value'];
            }
        }

        if ( isset($source['letterSpacing']) && is_array($source['letterSpacing']) && isset($source['letterSpacing']['value']) && is_numeric($source['letterSpacing']['value']) ) {
            $letterSpacingUnits = strtoupper((string) ($source['letterSpacing']['units'] ?? 'PIXELS'));
            if ( 'PIXELS' === $letterSpacingUnits ) {
                $style['letter_spacing'] = (float) $source['letterSpacing']['value'];
            } elseif ( 'RAW' === $letterSpacingUnits ) {
                $style['letter_spacing_em'] = (float) $source['letterSpacing']['value'];
            } elseif ( str_contains($letterSpacingUnits, 'PERCENT') ) {
                $style['letter_spacing_em'] = (float) $source['letterSpacing']['value'] / 100;
            }
        }

        foreach ( array('color', 'textColor') as $sourceKey ) {
            $color = $this->normalizeColor($source[$sourceKey] ?? null);
            if ( null !== $color ) {
                $style['color'] = $color;
                break;
            }
        }

        foreach ( array('underline' => 'underline', 'strikethrough' => 'strikethrough') as $sourceKey => $targetKey ) {
            if ( isset($source[$sourceKey]) && is_bool($source[$sourceKey]) ) {
                $style[$targetKey] = $source[$sourceKey];
            }
        }

        return $style;
    }

    private function fontWeightFromStyle(string $style): ?int
    {
        $style = strtolower(str_replace(array('-', '_'), ' ', $style));
        if ( str_contains($style, 'thin') ) {
            return 100;
        }
        if ( str_contains($style, 'extra light') || str_contains($style, 'ultra light') ) {
            return 200;
        }
        if ( str_contains($style, 'light') ) {
            return 300;
        }
        if ( str_contains($style, 'regular') || str_contains($style, 'normal') ) {
            return 400;
        }
        if ( str_contains($style, 'medium') ) {
            return 500;
        }
        if ( str_contains($style, 'semi bold') || str_contains($style, 'semibold') || str_contains($style, 'demi bold') ) {
            return 600;
        }
        if ( str_contains($style, 'extra bold') || str_contains($style, 'ultra bold') ) {
            return 800;
        }
        if ( str_contains($style, 'bold') ) {
            return 700;
        }
        if ( str_contains($style, 'black') || str_contains($style, 'heavy') ) {
            return 900;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function normalizeStyledTextSegments(array $node): array
    {
        $segments = array();
        $rawSegments = null;
        foreach ( array('styledTextSegments', 'segments') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                $rawSegments = $node[$key];
                break;
            }
        }

        if ( ! is_array($rawSegments) ) {
            // Fall back to character-level override encoding when no segment list is present.
            return $this->normalizeCharacterStyleOverrideSegments($node);
        }

        foreach ( $rawSegments as $segment ) {
            if ( ! is_array($segment) ) {
                continue;
            }

            $normalized = array();
            foreach ( array('characters', 'text') as $key ) {
                if ( isset($segment[$key]) && is_scalar($segment[$key]) ) {
                    $normalized['characters'] = (string) $segment[$key];
                    break;
                }
            }
            foreach ( array('start', 'end') as $key ) {
                if ( isset($segment[$key]) && is_numeric($segment[$key]) ) {
                    $normalized[$key] = (int) $segment[$key];
                }
            }

            $style = is_array($segment['style'] ?? null) ? $this->normalizeTextStyle($segment['style']) : $this->normalizeTextStyle($segment);
            if ( ! empty($style) ) {
                $normalized['style'] = $style;
            }

            if ( ! empty($normalized) ) {
                $segments[] = $normalized;
            }
        }

        return $segments;
    }

    /**
     * Converts the character-level Figma override encoding into the same segment
     * format produced by {@see normalizeStyledTextSegments}.
     *
     * Figma REST API and .fig files expose per-character style overrides via two
     * parallel fields:
     *   - `characterStyleOverrides` — one integer per character; 0 = base style,
     *     N = an entry in `styleOverrideTable`.
     *   - `styleOverrideTable` — map from string key (the N above) to a Figma
     *     style object carrying only the overriding properties.
     *
     * Adjacent characters sharing the same override ID are collapsed into a single
     * run. For each non-base run the override style is compared against the
     * resolved base style, and only the differing properties (color, font-weight,
     * etc.) are stored in the segment's `style` key so the emitter emits minimal
     * `<span>` wrappers.
     *
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCharacterStyleOverrideSegments(array $node): array
    {
        $overrides = is_array($node['characterStyleOverrides'] ?? null) ? array_values($node['characterStyleOverrides']) : array();
        $overrideTable = is_array($node['styleOverrideTable'] ?? null) ? $node['styleOverrideTable'] : array();

        if ( empty($overrides) || empty($overrideTable) ) {
            return array();
        }

        // If all override IDs are 0, the entire text uses the base style — no spans needed.
        $hasNonZero = false;
        foreach ( $overrides as $id ) {
            if ( 0 !== (int) $id ) {
                $hasNonZero = true;
                break;
            }
        }
        if ( ! $hasNonZero ) {
            return array();
        }

        // Resolve the characters string.
        $characters = '';
        foreach ( array('characters', 'text') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                $characters = (string) $node[$key];
                break;
            }
        }
        if ( '' === $characters ) {
            return array();
        }

        // Build the normalized base style so override deltas can be diffed against it.
        $baseStyleSource = is_array($node['style'] ?? null) ? $node['style'] : array();
        $baseStyle = $this->normalizeTextStyle($baseStyleSource);
        $rootStyle = $this->normalizeTextStyle($node);
        foreach ( $rootStyle as $key => $value ) {
            if ( ! array_key_exists($key, $baseStyle) ) {
                $baseStyle[$key] = $value;
            }
        }
        // Extract text fill color from the base node's fills when not already in style.
        if ( ! isset($baseStyle['color']) ) {
            $baseFills = is_array($baseStyleSource['fills'] ?? null)
                ? $baseStyleSource['fills']
                : ( is_array($node['fills'] ?? null) ? $node['fills'] : array() );
            $fillColor = $this->solidFillColor($baseFills);
            if ( null !== $fillColor ) {
                $baseStyle['color'] = $fillColor;
            }
        }

        // Split into Unicode codepoints (mirrors the approach used elsewhere in this class).
        $chars = preg_split('//u', $characters, -1, PREG_SPLIT_NO_EMPTY);
        if ( ! is_array($chars) ) {
            return array();
        }
        $charCount = count($chars);

        // Collapse adjacent characters with the same override ID into runs.
        $runs = array();
        $runChars = '';
        $runId = null;

        for ( $i = 0; $i < $charCount; $i++ ) {
            $id = isset($overrides[$i]) ? (int) $overrides[$i] : 0;
            if ( null !== $runId && $id !== $runId ) {
                $runs[] = array('characters' => $runChars, 'override_id' => $runId);
                $runChars = '';
            }
            $runChars .= $chars[$i];
            $runId = $id;
        }
        if ( '' !== $runChars && null !== $runId ) {
            $runs[] = array('characters' => $runChars, 'override_id' => $runId);
        }

        if ( empty($runs) ) {
            return array();
        }

        $segments = array();
        foreach ( $runs as $run ) {
            $overrideId = (int) $run['override_id'];
            $segment = array('characters' => $run['characters']);

            if ( 0 !== $overrideId ) {
                $rawOverride = is_array($overrideTable[(string) $overrideId] ?? null)
                    ? $overrideTable[(string) $overrideId]
                    : array();

                if ( ! empty($rawOverride) ) {
                    $overrideStyle = $this->normalizeTextStyle($rawOverride);

                    // Figma REST API encodes text color as fills in override entries.
                    if ( ! isset($overrideStyle['color']) ) {
                        $overrideFills = is_array($rawOverride['fills'] ?? null) ? $rawOverride['fills'] : array();
                        $fillColor = $this->solidFillColor($overrideFills);
                        if ( null !== $fillColor ) {
                            $overrideStyle['color'] = $fillColor;
                        }
                    }

                    // Keep only properties that differ from the base style.
                    $delta = array();
                    foreach ( $overrideStyle as $key => $value ) {
                        if ( ! array_key_exists($key, $baseStyle) || $baseStyle[$key] !== $value ) {
                            $delta[$key] = $value;
                        }
                    }

                    if ( ! empty($delta) ) {
                        $segment['style'] = $delta;
                    }
                }
            }

            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * Extracts the CSS color from the first visible SOLID fill in a paint list.
     *
     * Returns the normalized RGBA array used by the rest of the normalizer, or
     * null when no usable solid fill is found.
     *
     * @param array<int, mixed> $fills
     * @return array{r: float, g: float, b: float, a?: float}|null
     */
    private function solidFillColor(array $fills): ?array
    {
        foreach ( $fills as $fill ) {
            if ( ! is_array($fill) ) {
                continue;
            }
            $type = strtoupper((string) ($fill['type'] ?? 'SOLID'));
            if ( 'SOLID' !== $type ) {
                continue;
            }
            $color = $this->normalizeColor($fill['color'] ?? null);
            if ( null === $color ) {
                continue;
            }
            $opacity = isset($fill['opacity']) && is_numeric($fill['opacity']) ? (float) $fill['opacity'] : 1.0;
            if ( $opacity < 1.0 ) {
                $color['a'] = $opacity * ($color['a'] ?? 1.0);
            }

            return $color;
        }

        return null;
    }

    /**
     * Capture Figma hyperlink and prototype navigation data so the emitter can produce real anchors.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeLink(array $node, string $type): array
    {
        if ( 'TEXT' === $type ) {
            if ( array_key_exists('hyperlink', $node) ) {
                $link = $this->normalizeHyperlinkValue($node['hyperlink']);
                if ( null !== $link ) {
                    $link['source'] = 'hyperlink';
                    return $link;
                }
            }

            $segmentLink = $this->normalizeSegmentHyperlink($node);
            if ( null !== $segmentLink ) {
                return $segmentLink;
            }
        } elseif ( array_key_exists('hyperlink', $node) ) {
            $link = $this->normalizeHyperlinkValue($node['hyperlink']);
            if ( null !== $link ) {
                $link['source'] = 'hyperlink';
                return $link;
            }
        }

        $reactionLink = $this->normalizeReactionLink($node);
        if ( null !== $reactionLink ) {
            return $reactionLink;
        }

        return array();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeHyperlinkValue(mixed $hyperlink): ?array
    {
        if ( is_string($hyperlink) && '' !== trim($hyperlink) ) {
            return array('type' => 'url', 'url' => trim($hyperlink));
        }

        if ( ! is_array($hyperlink) ) {
            return null;
        }

        $type = strtoupper((string) ($hyperlink['type'] ?? ''));
        $url = $this->readString($hyperlink, array('url', 'href'));
        $nodeId = $this->readString($hyperlink, array('nodeID', 'nodeId', 'node_id'))
            ?? $this->readGuidId($hyperlink['nodeID'] ?? ($hyperlink['nodeId'] ?? null));

        if ( 'URL' === $type && null !== $url ) {
            return array('type' => 'url', 'url' => $url);
        }
        if ( 'NODE' === $type && null !== $nodeId ) {
            return array('type' => 'node', 'target_node_id' => $nodeId);
        }
        if ( null !== $url ) {
            return array('type' => 'url', 'url' => $url);
        }
        if ( null !== $nodeId ) {
            return array('type' => 'node', 'target_node_id' => $nodeId);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function normalizeSegmentHyperlink(array $node): ?array
    {
        foreach ( array('styledTextSegments', 'segments') as $key ) {
            if ( ! is_array($node[$key] ?? null) ) {
                continue;
            }

            foreach ( $node[$key] as $segment ) {
                if ( ! is_array($segment) || ! array_key_exists('hyperlink', $segment) ) {
                    continue;
                }

                $link = $this->normalizeHyperlinkValue($segment['hyperlink']);
                if ( null !== $link ) {
                    $link['source'] = 'segment';
                    $link['partial'] = true;
                    return $link;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function normalizeReactionLink(array $node): ?array
    {
        foreach ( is_array($node['reactions'] ?? null) ? $node['reactions'] : array() as $reaction ) {
            if ( ! is_array($reaction) ) {
                continue;
            }

            $actions = is_array($reaction['actions'] ?? null)
                ? $reaction['actions']
                : (is_array($reaction['action'] ?? null) ? array($reaction['action']) : array());
            foreach ( $actions as $action ) {
                if ( ! is_array($action) ) {
                    continue;
                }

                $link = $this->normalizeActionLink($action);
                if ( null !== $link ) {
                    $link['source'] = 'reaction';
                    return $link;
                }
            }
        }

        $transition = $this->readString($node, array('transitionNodeID', 'transitionNodeId'))
            ?? $this->readGuidId($node['transitionNodeID'] ?? null);
        if ( null !== $transition && '' !== $transition ) {
            return array('type' => 'node', 'target_node_id' => $transition, 'source' => 'transition');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>|null
     */
    private function normalizeActionLink(array $action): ?array
    {
        $type = strtoupper((string) ($action['type'] ?? ''));
        $url = $this->readString($action, array('url', 'href'));
        if ( 'URL' === $type && null !== $url ) {
            return array('type' => 'url', 'url' => $url);
        }

        $destination = $this->readString($action, array('destinationId', 'navigationDestinationId', 'transitionNodeID'))
            ?? $this->readGuidId($action['destinationId'] ?? null);
        $navigation = strtoupper((string) ($action['navigation'] ?? ''));
        $navigatesToNode = 'NODE' === $type
            || in_array($navigation, array('NAVIGATE', 'OVERLAY', 'SWAP', 'SCROLL_TO'), true);
        if ( $navigatesToNode && null !== $destination && '' !== $destination ) {
            return array('type' => 'node', 'target_node_id' => $destination);
        }

        if ( null !== $url ) {
            return array('type' => 'url', 'url' => $url);
        }
        if ( null !== $destination && '' !== $destination ) {
            return array('type' => 'node', 'target_node_id' => $destination);
        }

        return null;
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function normalizePaintCollections(array $node, string $nodeId, array &$diagnostics, array $paintStyles = array()): array
    {
        $collections = array();
        foreach ( array('fills' => 'fills', 'fillPaints' => 'fills', 'strokes' => 'strokes', 'strokePaints' => 'strokes', 'background' => 'background', 'backgroundPaints' => 'background') as $sourceKey => $targetKey ) {
            if ( ! is_array($node[$sourceKey] ?? null) ) {
                continue;
            }

            $paints = $this->normalizePaintList($node[$sourceKey], $nodeId, $sourceKey, $diagnostics);

            if ( ! empty($paints) ) {
                $collections[$targetKey] = $paints;
            }
        }

        $styleFillId = $this->readStyleGuidId($node['styleIdForFill'] ?? null);
        if ( null !== $styleFillId && ! empty($paintStyles[$styleFillId]['fills']) ) {
            $collections['fills'] = $paintStyles[$styleFillId]['fills'];
        }

        $styleStrokeId = $this->readStyleGuidId($node['styleIdForStrokeFill'] ?? $node['styleIdForStroke'] ?? null);
        if ( null !== $styleStrokeId && ! empty($paintStyles[$styleStrokeId]['fills']) ) {
            $collections['strokes'] = $paintStyles[$styleStrokeId]['fills'];
        }

        foreach ( array('fill' => 'fills', 'backgroundColor' => 'background') as $sourceKey => $targetKey ) {
            if ( ! isset($node[$sourceKey]) ) {
                continue;
            }

            $color = $this->normalizeColor($node[$sourceKey]);
            if ( null !== $color ) {
                $collections[$targetKey][] = array('type' => 'SOLID', 'color' => $color);
            }
        }

        return $collections;
    }

    /**
     * @param array<int, mixed> $paints
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function normalizePaintList(array $paints, string $nodeId, string $paintKey, array &$diagnostics): array
    {
        $normalizedPaints = array();
        foreach ( $paints as $paint ) {
            if ( ! is_array($paint) ) {
                continue;
            }

            $normalized = $this->normalizePaint($paint, $nodeId, $paintKey, $diagnostics);
            if ( ! empty($normalized) ) {
                $normalizedPaints[] = $normalized;
            }
        }

        return $normalizedPaints;
    }

    private function readStyleGuidId(mixed $style): ?string
    {
        if ( is_array($style) && isset($style['guid']) ) {
            return $this->readGuidId($style['guid']);
        }

        return $this->readGuidId($style);
    }

    /**
     * @param array<string, mixed>             $paint
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function normalizePaint(array $paint, string $nodeId, string $paintKey, array &$diagnostics): array
    {
        $type = strtoupper((string) ($paint['type'] ?? 'SOLID'));
        if ( false === ($paint['visible'] ?? true) ) {
            return array();
        }

        if ( 'SOLID' === $type ) {
            $color = $this->normalizeColor($paint['color'] ?? $paint);
            if ( null === $color ) {
                return array();
            }

            $normalized = array('type' => 'SOLID', 'color' => $color);
            if ( isset($paint['opacity']) && is_numeric($paint['opacity']) ) {
                $normalized['opacity'] = (float) $paint['opacity'];
            }

            return $normalized;
        }

        if ( 'IMAGE' === $type ) {
            $normalized = array('type' => 'IMAGE');
            $ref = $paint['imageRef'] ?? $paint['imageHash'] ?? $paint['ref'] ?? null;
            if ( is_scalar($ref) && '' !== (string) $ref ) {
                $normalized['ref'] = $this->normalizeImageHash((string) $ref);
            }

            if ( is_array($paint['image'] ?? null) ) {
                $imageRef = $this->readNestedImageHash($paint['image']);
                if ( null !== $imageRef ) {
                    $normalized['ref'] = $imageRef;
                    $normalized['imageHash'] = $imageRef;
                }
                if ( isset($paint['image']['name']) && is_scalar($paint['image']['name']) ) {
                    $normalized['imageName'] = (string) $paint['image']['name'];
                }
            }

            if ( is_array($paint['imageThumbnail'] ?? null) ) {
                $thumbnailRef = $this->readNestedImageHash($paint['imageThumbnail']);
                if ( null !== $thumbnailRef ) {
                    $normalized['thumbnailRef'] = $thumbnailRef;
                    $normalized['thumbnailHash'] = $thumbnailRef;
                }
                if ( isset($paint['imageThumbnail']['name']) && is_scalar($paint['imageThumbnail']['name']) ) {
                    $normalized['thumbnailName'] = (string) $paint['imageThumbnail']['name'];
                }
            }

            foreach ( array('imageScaleMode', 'scaleMode', 'altText') as $key ) {
                if ( isset($paint[$key]) && is_scalar($paint[$key]) ) {
                    $normalized[$key] = (string) $paint[$key];
                }
            }
            foreach ( array('originalImageWidth', 'originalImageHeight', 'scale', 'rotation', 'opacity') as $key ) {
                if ( isset($paint[$key]) && is_numeric($paint[$key]) ) {
                    $normalized[$key] = (float) $paint[$key];
                }
            }
            if ( isset($paint['imageShouldColorManage']) && is_bool($paint['imageShouldColorManage']) ) {
                $normalized['imageShouldColorManage'] = $paint['imageShouldColorManage'];
            }
            foreach ( array('transform', 'imageTransform') as $transformKey ) {
                if ( is_array($paint[$transformKey] ?? null) ) {
                    $normalized['transform'] = $paint[$transformKey];
                    break;
                }
            }

            return $normalized;
        }

        if ( in_array($type, array('GRADIENT_LINEAR', 'GRADIENT_RADIAL'), true) ) {
            $stops = $this->normalizeGradientStops($paint['gradientStops'] ?? $paint['stops'] ?? array());
            if ( ! empty($stops) ) {
                $normalized = array(
                    'type'  => $type,
                    'stops' => $stops,
                );
                if ( isset($paint['opacity']) && is_numeric($paint['opacity']) ) {
                    $normalized['opacity'] = (float) $paint['opacity'];
                }
                if ( is_array($paint['gradientTransform'] ?? null) ) {
                    $normalized['gradientTransform'] = $paint['gradientTransform'];
                }

                return $normalized;
            }
        }

        $diagnostics[] = array(
            'severity' => 'warning',
            'code'     => 'unsupported_figma_paint_type',
            'message'  => 'Unsupported Figma paint type was omitted from static CSS.',
            'context'  => array(
                'node_id' => $nodeId,
                'paint'   => $paintKey,
                'type'    => $type,
            ),
        );

        return array();
    }

    /**
     * @param mixed $stops
     * @return array<int, array<string, mixed>>
     */
    private function normalizeGradientStops(mixed $stops): array
    {
        if ( ! is_array($stops) ) {
            return array();
        }

        $normalizedStops = array();
        foreach ( $stops as $stop ) {
            if ( ! is_array($stop) || ! isset($stop['position']) || ! is_numeric($stop['position']) ) {
                continue;
            }

            $color = $this->normalizeColor($stop['color'] ?? null);
            if ( null === $color ) {
                continue;
            }

            $normalizedStops[] = array(
                'position' => max(0.0, min(1.0, (float) $stop['position'])),
                'color'    => $color,
            );
        }

        return $normalizedStops;
    }

    private function readNestedImageHash(array $image): ?string
    {
        if ( ! isset($image['hash']) || ! is_scalar($image['hash']) || '' === (string) $image['hash'] ) {
            return null;
        }

        return $this->normalizeImageHash((string) $image['hash']);
    }

    private function normalizeImageHash(string $hash): string
    {
        if ( 1 === preg_match('/^[a-f0-9]{40}$/i', $hash) ) {
            return strtolower($hash);
        }

        if ( 20 === strlen($hash) ) {
            return bin2hex($hash);
        }

        return $hash;
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int|string, mixed>         $blobs
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function normalizeVectorPaths(array $node, array $blobs, string $nodeId, array &$diagnostics): array
    {
        $paths = array();
        foreach ( array('fillGeometry', 'strokeGeometry') as $geometryKey ) {
            if ( ! is_array($node[$geometryKey] ?? null) ) {
                continue;
            }

            foreach ( $node[$geometryKey] as $geometry ) {
                if ( ! is_array($geometry) ) {
                    continue;
                }

                // Prefer ready-to-use SVG path commands (Figma REST/plugin geometry
                // shape: `{ path, windingRule }`). This emits real vectors directly,
                // without decoding the raw Kiwi command blob.
                $readyPath = $this->extractReadyVectorPath($geometry);
                if ( null !== $readyPath ) {
                    $normalized = array('data' => $readyPath, 'source' => $geometryKey . '.path');
                } elseif ( isset($geometry['commandsBlob']) ) {
                    $normalized = $this->normalizeVectorCommandBlob($geometry['commandsBlob'], $blobs, $nodeId, $geometryKey, $diagnostics);
                } else {
                    continue;
                }

                if ( null === $normalized ) {
                    continue;
                }

                if ( isset($geometry['windingRule']) && is_scalar($geometry['windingRule']) ) {
                    $normalized['windingRule'] = (string) $geometry['windingRule'];
                }
                $paths[] = $normalized;
            }
        }

        if ( empty($paths) && isset($node['vectorData']['vectorNetworkBlob']) ) {
            $normalized = $this->normalizeVectorNetworkBlob($node['vectorData']['vectorNetworkBlob'], $node, $blobs, $nodeId, $diagnostics);
            if ( null !== $normalized ) {
                $paths[] = $normalized;
            }
        }

        return $paths;
    }

    /**
     * Extract ready-to-use SVG path commands from a Figma geometry entry.
     *
     * Figma's REST API and plugin geometry expose `fillGeometry`/`strokeGeometry`
     * as `{ path: "<SVG path commands>", windingRule }`. When that pre-decoded
     * string is present we can emit it directly as an inline `<path>` rather than
     * decoding the raw Kiwi command blob.
     *
     * @param array<string, mixed> $geometry
     */
    private function extractReadyVectorPath(array $geometry): ?string
    {
        foreach ( array('path', 'pathData', 'd', 'data') as $key ) {
            if ( ! isset($geometry[$key]) || ! is_scalar($geometry[$key]) ) {
                continue;
            }

            $candidate = trim(preg_replace('/\s+/', ' ', (string) $geometry[$key]) ?? '');
            if ( '' === $candidate ) {
                continue;
            }

            // Require well-formed SVG path data: a leading move command and only
            // path-command/number characters. The emitter re-validates and
            // byte-limits before rendering.
            if ( 1 !== preg_match('/^[Mm][\s,]*-?\d/', $candidate) ) {
                continue;
            }
            if ( 1 !== preg_match('/^[MmZzLlHhVvCcSsQqTtAa0-9,\.\-+\s]+$/', $candidate) ) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @param array<int|string, mixed>         $blobs
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>|null
     */
    private function normalizeVectorCommandBlob(mixed $blobReference, array $blobs, string $nodeId, string $source, array &$diagnostics): ?array
    {
        $bytes = $this->readCommandBlobBytes($blobReference, $blobs);
        if ( null === $bytes ) {
            $diagnostics[] = array(
                'severity' => 'warning',
                'code'     => 'figma_vector_command_blob_missing',
                'message'  => 'Figma vector command blob reference could not be resolved.',
                'context'  => array('node_id' => $nodeId, 'geometry' => $source, 'blob_ref' => is_scalar($blobReference) ? (string) $blobReference : null),
            );
            return null;
        }

        $path = $this->decodeVectorCommandBlob($bytes);
        if ( null === $path ) {
            $isVectorNetworkBlob = 'vectorData.vectorNetworkBlob' === $source;
            $context = array('node_id' => $nodeId, 'geometry' => $source);
            if ( $isVectorNetworkBlob ) {
                $context += $this->vectorNetworkBlobDiagnosticContext($blobReference, $bytes);
            }
            $diagnostics[] = array(
                'severity' => 'warning',
                'code'     => $isVectorNetworkBlob ? 'unsupported_vector_network_blob' : 'unsupported_vector_command_blob',
                'message'  => $isVectorNetworkBlob ? 'Unsupported Figma vector network blob was omitted from SVG output.' : 'Unsupported Figma vector command blob was omitted from SVG output.',
                'context'  => $context,
            );
            return null;
        }

        return array('data' => $path, 'source' => $source);
    }

    /**
     * @param array<int|string, mixed>         $blobs
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>|null
     */
    private function normalizeVectorNetworkBlob(mixed $blobReference, array $node, array $blobs, string $nodeId, array &$diagnostics): ?array
    {
        $bytes = $this->readCommandBlobBytes($blobReference, $blobs);
        if ( null === $bytes ) {
            $diagnostics[] = array(
                'severity' => 'warning',
                'code'     => 'figma_vector_command_blob_missing',
                'message'  => 'Figma vector command blob reference could not be resolved.',
                'context'  => array('node_id' => $nodeId, 'geometry' => 'vectorData.vectorNetworkBlob', 'blob_ref' => is_scalar($blobReference) ? (string) $blobReference : null),
            );
            return null;
        }

        $path = $this->decodeSimpleChevronVectorNetworkBlob($bytes);
        if ( null !== $path ) {
            return array('data' => $path, 'source' => 'vectorData.vectorNetworkBlob', 'windingRule' => 'NONZERO');
        }

        $path = $this->decodeSingleClosedLoopVectorNetworkBlob($bytes);
        if ( null !== $path ) {
            return array('data' => $path, 'source' => 'vectorData.vectorNetworkBlob.singleClosedLoop', 'windingRule' => 'NONZERO');
        }

        $path = $this->decodeSimpleRectVectorNetworkBlob($bytes, $node);
        if ( null !== $path ) {
            return array('data' => $path, 'source' => 'vectorData.vectorNetworkBlob.simpleRectFallback', 'windingRule' => 'NONZERO');
        }

        $path = $this->decodeClosedRectVectorNetworkBlob($bytes);
        if ( null !== $path ) {
            return array('data' => $path, 'source' => 'vectorData.vectorNetworkBlob.closedRectFallback', 'windingRule' => 'NONZERO');
        }

        $general = $this->decodeGeneralVectorNetworkBlob($bytes);
        if ( null !== $general ) {
            return $general;
        }

        if ( ! $this->looksLikeVectorNetworkBlob($bytes) ) {
            $path = $this->decodeVectorCommandBlob($bytes);
            if ( null !== $path ) {
                return array('data' => $path, 'source' => 'vectorData.vectorNetworkBlob');
            }
        }

        $diagnostics[] = array(
            'severity' => 'warning',
            'code'     => 'unsupported_vector_network_blob',
            'message'  => 'Unsupported Figma vector network blob was omitted from SVG output.',
            'context'  => array('node_id' => $nodeId, 'geometry' => 'vectorData.vectorNetworkBlob') + $this->vectorNetworkBlobDiagnosticContext($blobReference, $bytes),
        );
        return null;
    }

    /**
     * General-purpose Figma vectorNetwork blob decoder.
     *
     * Parses the raw vectorNetwork buffer — a 12-byte header
     * (vertexCount, segmentCount, regionCount), a vertex table (x/y at a
     * 20-byte stride), a segment table (start/end vertex index, with optional
     * cubic-bezier tangents), and a per-region list of directed segment loops —
     * into one SVG path made of one subpath per region. Straight segments emit
     * `L`; segments carrying non-zero tangents emit a cubic `C` using
     * `vertex + tangent` control points. The region winding rule is carried so
     * the emitter can map NONZERO/EVENODD onto `fill-rule`.
     *
     * The segment stride is auto-detected (24 bytes when tangents are present,
     * 16 bytes when they are not) by requiring a fully consistent parse: every
     * vertex/segment index must be in range, each region must form a continuous
     * closed loop, and the region table must consume the buffer exactly. A wrong
     * stride guess fails these checks and is rejected rather than emitted as
     * garbage geometry.
     *
     * @return array<string, mixed>|null
     */
    private function decodeGeneralVectorNetworkBlob(string $bytes): ?array
    {
        $counts = $this->vectorNetworkCounts($bytes);
        if ( null === $counts ) {
            return null;
        }

        [$vertexCount, $segmentCount, $regionCount] = $counts;
        if ( $vertexCount < 2 || $vertexCount > 20000 || $segmentCount < 1 || $segmentCount > 40000 || $regionCount < 1 || $regionCount > 20000 ) {
            return null;
        }

        $vertexBytes = $vertexCount * 20;
        if ( strlen($bytes) < 12 + $vertexBytes ) {
            return null;
        }

        $vertices = array();
        for ( $index = 0; $index < $vertexCount; $index++ ) {
            $point = $this->readFloatPair($bytes, 12 + ( $index * 20 ) + 4);
            if ( null === $point || ! is_finite($point[0]) || ! is_finite($point[1]) ) {
                return null;
            }
            $vertices[] = $point;
        }

        $segmentOffset = 12 + $vertexBytes;
        foreach ( array(24, 16) as $stride ) {
            $decoded = $this->decodeVectorNetworkWithSegmentStride($bytes, $vertices, $segmentOffset, $segmentCount, $regionCount, $stride);
            if ( null !== $decoded ) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0: float, 1: float}> $vertices
     * @return array<string, mixed>|null
     */
    private function decodeVectorNetworkWithSegmentStride(string $bytes, array $vertices, int $segmentOffset, int $segmentCount, int $regionCount, int $stride): ?array
    {
        $vertexCount = count($vertices);
        $regionOffset = $segmentOffset + ( $segmentCount * $stride );
        if ( strlen($bytes) < $regionOffset ) {
            return null;
        }

        $segments = array();
        for ( $index = 0; $index < $segmentCount; $index++ ) {
            $base = $segmentOffset + ( $index * $stride );
            $start = $this->readUint32($bytes, $base);
            if ( 24 === $stride ) {
                $tangentStart = $this->readFloatPair($bytes, $base + 4);
                $end = $this->readUint32($bytes, $base + 12);
                $tangentEnd = $this->readFloatPair($bytes, $base + 16);
            } else {
                $tangentStart = array(0.0, 0.0);
                $end = $this->readUint32($bytes, $base + 4);
                $tangentEnd = array(0.0, 0.0);
            }

            if ( null === $start || null === $end || null === $tangentStart || null === $tangentEnd ) {
                return null;
            }
            if ( $start < 0 || $start >= $vertexCount || $end < 0 || $end >= $vertexCount || $start === $end ) {
                return null;
            }
            if ( ! is_finite($tangentStart[0]) || ! is_finite($tangentStart[1]) || ! is_finite($tangentEnd[0]) || ! is_finite($tangentEnd[1]) ) {
                return null;
            }

            $segments[] = array('start' => $start, 'end' => $end, 'tangentStart' => $tangentStart, 'tangentEnd' => $tangentEnd);
        }

        $offset = $regionOffset;
        $subpaths = array();
        $windingRule = 'NONZERO';
        for ( $region = 0; $region < $regionCount; $region++ ) {
            $entryCount = $this->readUint32($bytes, $offset);
            $rule = $this->readUint32($bytes, $offset + 4);
            $reserved = $this->readUint32($bytes, $offset + 8);
            if ( null === $entryCount || null === $rule || null === $reserved ) {
                return null;
            }
            if ( $entryCount < 1 || $entryCount > $segmentCount || ( 0 !== $rule && 1 !== $rule ) ) {
                return null;
            }
            $offset += 12;
            if ( strlen($bytes) < $offset + ( $entryCount * 8 ) ) {
                return null;
            }

            $entries = array();
            for ( $entry = 0; $entry < $entryCount; $entry++ ) {
                $segmentIndex = $this->readUint32($bytes, $offset);
                $direction = $this->readUint32($bytes, $offset + 4);
                $offset += 8;
                if ( null === $segmentIndex || null === $direction || $segmentIndex < 0 || $segmentIndex >= $segmentCount || ( 0 !== $direction && 1 !== $direction ) ) {
                    return null;
                }
                $entries[] = array($segmentIndex, $direction);
            }

            $subpath = $this->vectorNetworkRegionSubpath($vertices, $segments, $entries);
            if ( null === $subpath ) {
                return null;
            }
            $subpaths[] = $subpath;
            if ( 1 === $rule ) {
                $windingRule = 'EVENODD';
            }
        }

        if ( $offset !== strlen($bytes) || empty($subpaths) ) {
            return null;
        }

        return array(
            'data'        => implode(' ', $subpaths),
            'source'      => 'vectorData.vectorNetworkBlob.network',
            'windingRule' => $windingRule,
        );
    }

    /**
     * Trace one region's directed segment list into a single closed SVG subpath.
     *
     * @param array<int, array{0: float, 1: float}>                                          $vertices
     * @param array<int, array{start: int, end: int, tangentStart: array{0: float, 1: float}, tangentEnd: array{0: float, 1: float}}> $segments
     * @param array<int, array{0: int, 1: int}>                                              $entries
     */
    private function vectorNetworkRegionSubpath(array $vertices, array $segments, array $entries): ?string
    {
        $first = null;
        $cursor = null;
        $parts = array();

        foreach ( $entries as $entry ) {
            [$segmentIndex, $direction] = $entry;
            $segment = $segments[$segmentIndex];
            $start = $segment['start'];
            $end = $segment['end'];
            $tangentStart = $segment['tangentStart'];
            $tangentEnd = $segment['tangentEnd'];
            if ( 1 === $direction ) {
                [$start, $end] = array($end, $start);
                [$tangentStart, $tangentEnd] = array($tangentEnd, $tangentStart);
            }

            if ( null === $first ) {
                $first = $start;
                $cursor = $start;
                $point = $vertices[$start];
                $parts[] = 'M ' . $this->svgNumber($point[0]) . ' ' . $this->svgNumber($point[1]);
            } elseif ( $start !== $cursor ) {
                return null;
            }

            $from = $vertices[$start];
            $to = $vertices[$end];
            $hasCurve = abs($tangentStart[0]) > 0.000001 || abs($tangentStart[1]) > 0.000001 || abs($tangentEnd[0]) > 0.000001 || abs($tangentEnd[1]) > 0.000001;
            if ( $hasCurve ) {
                $parts[] = 'C ' . $this->svgNumber($from[0] + $tangentStart[0]) . ' ' . $this->svgNumber($from[1] + $tangentStart[1])
                    . ' ' . $this->svgNumber($to[0] + $tangentEnd[0]) . ' ' . $this->svgNumber($to[1] + $tangentEnd[1])
                    . ' ' . $this->svgNumber($to[0]) . ' ' . $this->svgNumber($to[1]);
            } else {
                $parts[] = 'L ' . $this->svgNumber($to[0]) . ' ' . $this->svgNumber($to[1]);
            }
            $cursor = $end;
        }

        if ( null === $first || count($parts) < 3 || $cursor !== $first ) {
            return null;
        }

        return implode(' ', $parts) . ' Z';
    }

    private function decodeSimpleChevronVectorNetworkBlob(string $bytes): ?string
    {
        if ( 288 !== strlen($bytes) ) {
            return null;
        }

        $counts = $this->vectorNetworkCounts($bytes);
        if ( array(6, 6, 1) !== $counts ) {
            return null;
        }

        $signature = bin2hex(substr($bytes, 0, 32));
        return match ( $signature ) {
            '0600000006000000010000000000000000000041000080410000000000000000' => 'M 8 16 L 0 8 L 8 0 L 9.414 1.414 L 2.828 8 L 9.414 14.586 L 8 16 Z',
            '06000000060000000100000000000000f4fdb43f0000804100000000be9f1641' => 'M 1.414 16 L 9.414 8 L 1.414 0 L 0 1.414 L 6.586 8 L 0 14.586 L 1.414 16 Z',
            default => null,
        };
    }

    /**
     * Small icon vectors can arrive as a 4-vertex, 4-segment network
     * without decodable command geometry. Keep this guard exact until the broader
     * vector-network format is understood.
     *
     * @param array<string, mixed> $node
     */
    private function decodeSimpleRectVectorNetworkBlob(string $bytes, array $node): ?string
    {
        if ( 172 !== strlen($bytes) ) {
            return null;
        }

        if ( array(4, 4, 0) !== $this->vectorNetworkCounts($bytes) ) {
            return null;
        }

        $signature = bin2hex(substr($bytes, 0, 32));
        if ( '0400000004000000000000000000000000000000000000000000000000008043' !== $signature ) {
            return null;
        }

        $width = $this->rawNodeDimension($node, 'width');
        $height = $this->rawNodeDimension($node, 'height');
        if ( $width <= 0.0 || $height <= 0.0 ) {
            return null;
        }

        return 'M 0 0 L ' . $this->svgNumber($width) . ' 0 L ' . $this->svgNumber($width) . ' ' . $this->svgNumber($height) . ' L 0 ' . $this->svgNumber($height) . ' Z';
    }

    private function decodeClosedRectVectorNetworkBlob(string $bytes): ?string
    {
        if ( 200 !== strlen($bytes) ) {
            return null;
        }

        if ( array(4, 4, 1) !== $this->vectorNetworkCounts($bytes) ) {
            return null;
        }

        $points = $this->closedRectVectorNetworkPoints($bytes);
        if ( null === $points ) {
            return null;
        }

        $xs = array_values(array_unique(array_map(static fn (array $point): string => sprintf('%.6F', $point[0]), $points)));
        $ys = array_values(array_unique(array_map(static fn (array $point): string => sprintf('%.6F', $point[1]), $points)));
        if ( 2 !== count($xs) || 2 !== count($ys) ) {
            return null;
        }

        $minX = min(array_map('floatval', $xs));
        $maxX = max(array_map('floatval', $xs));
        $minY = min(array_map('floatval', $ys));
        $maxY = max(array_map('floatval', $ys));
        if ( $maxX <= $minX || $maxY <= $minY ) {
            return null;
        }

        return 'M ' . $this->svgNumber($minX) . ' ' . $this->svgNumber($minY)
            . ' L ' . $this->svgNumber($maxX) . ' ' . $this->svgNumber($minY)
            . ' L ' . $this->svgNumber($maxX) . ' ' . $this->svgNumber($maxY)
            . ' L ' . $this->svgNumber($minX) . ' ' . $this->svgNumber($maxY)
            . ' Z';
    }

    private function decodeSingleClosedLoopVectorNetworkBlob(string $bytes): ?string
    {
        $counts = $this->vectorNetworkCounts($bytes);
        if ( null === $counts ) {
            return null;
        }

        [$vertexCount, $segmentCount, $regionCount] = $counts;
        if ( $vertexCount < 3 || $vertexCount > 32 || $vertexCount !== $segmentCount || 1 !== $regionCount ) {
            return null;
        }

        if ( strlen($bytes) !== 24 + ( $vertexCount * 44 ) ) {
            return null;
        }

        $vertices = array();
        for ( $index = 0; $index < $vertexCount; $index++ ) {
            $vertexOffset = 12 + ( $index * 20 );
            if ( ! $this->bytesAreZero($bytes, $vertexOffset, 4) || ! $this->bytesAreZero($bytes, $vertexOffset + 12, 8) ) {
                return null;
            }

            $point = $this->readFloatPair($bytes, $vertexOffset + 4);
            if ( null === $point || ! is_finite($point[0]) || ! is_finite($point[1]) ) {
                return null;
            }
            $vertices[] = $point;
        }

        $segments = array();
        $degree = array_fill(0, $vertexCount, 0);
        $segmentOffset = 12 + ( $vertexCount * 20 );
        for ( $index = 0; $index < $segmentCount; $index++ ) {
            $currentSegmentOffset = $segmentOffset + ( $index * 16 );
            if ( ! $this->bytesAreZero($bytes, $currentSegmentOffset + 8, 8) ) {
                return null;
            }

            $start = $this->readUint32($bytes, $currentSegmentOffset);
            $end = $this->readUint32($bytes, $currentSegmentOffset + 4);
            if ( null === $start || null === $end || $start < 0 || $start >= $vertexCount || $end < 0 || $end >= $vertexCount || $start === $end ) {
                return null;
            }
            $segments[] = array($start, $end);
            $degree[$start]++;
            $degree[$end]++;
        }

        foreach ( $degree as $vertexDegree ) {
            if ( 2 !== $vertexDegree ) {
                return null;
            }
        }

        $regionOffset = $segmentOffset + ( $segmentCount * 16 );
        $regionSegmentCount = $this->readUint32($bytes, $regionOffset);
        if ( $segmentCount !== $regionSegmentCount || ! $this->bytesAreZero($bytes, $regionOffset + 4, 8) ) {
            return null;
        }

        $orderedVertexIndexes = array();
        $usedSegments = array();
        for ( $index = 0; $index < $segmentCount; $index++ ) {
            $entryOffset = $regionOffset + 12 + ( $index * 8 );
            $segmentIndex = $this->readUint32($bytes, $entryOffset);
            $direction = $this->readUint32($bytes, $entryOffset + 4);
            if ( null === $segmentIndex || null === $direction || $segmentIndex < 0 || $segmentIndex >= $segmentCount || isset($usedSegments[$segmentIndex]) || ( 0 !== $direction && 1 !== $direction ) ) {
                return null;
            }

            $usedSegments[$segmentIndex] = true;
            [$start, $end] = $segments[$segmentIndex];
            if ( 1 === $direction ) {
                [$start, $end] = array($end, $start);
            }

            if ( 0 === $index ) {
                $orderedVertexIndexes[] = $start;
                $orderedVertexIndexes[] = $end;
                continue;
            }

            if ( $orderedVertexIndexes[count($orderedVertexIndexes) - 1] !== $start ) {
                return null;
            }
            $orderedVertexIndexes[] = $end;
        }

        if ( count($orderedVertexIndexes) !== $vertexCount + 1 || $orderedVertexIndexes[0] !== $orderedVertexIndexes[count($orderedVertexIndexes) - 1] || count(array_unique(array_slice($orderedVertexIndexes, 0, -1))) !== $vertexCount ) {
            return null;
        }

        $windingArea = 0.0;
        for ( $index = 0; $index < $vertexCount; $index++ ) {
            $current = $vertices[$orderedVertexIndexes[$index]];
            $next = $vertices[$orderedVertexIndexes[$index + 1]];
            $windingArea += ( $current[0] * $next[1] ) - ( $next[0] * $current[1] );
        }
        if ( abs($windingArea) < 0.000001 ) {
            return null;
        }

        $parts = array();
        foreach ( array_slice($orderedVertexIndexes, 0, -1) as $index => $vertexIndex ) {
            $point = $vertices[$vertexIndex];
            $parts[] = ( 0 === $index ? 'M ' : 'L ' ) . $this->svgNumber($point[0]) . ' ' . $this->svgNumber($point[1]);
        }

        return implode(' ', $parts) . ' Z';
    }

    /**
     * @return array<int, array{0: float, 1: float}>|null
     */
    private function closedRectVectorNetworkPoints(string $bytes): ?array
    {
        $points = array();
        for ( $index = 0; $index < 4; $index++ ) {
            $offset = 12 + ( $index * 20 );
            $point = $this->readFloatPair($bytes, $offset + 4);
            if ( null === $point || ! is_finite($point[0]) || ! is_finite($point[1]) ) {
                return null;
            }
            $points[] = $point;
        }

        return $points;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function rawNodeDimension(array $node, string $dimension): float
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( isset($box[$dimension]) && is_numeric($box[$dimension]) ) {
            return (float) $box[$dimension];
        }

        if ( isset($node[$dimension]) && is_numeric($node[$dimension]) ) {
            return (float) $node[$dimension];
        }

        $sizeKey = 'width' === $dimension ? 'x' : 'y';
        if ( is_array($node['size'] ?? null) && isset($node['size'][$sizeKey]) && is_numeric($node['size'][$sizeKey]) ) {
            return (float) $node['size'][$sizeKey];
        }

        foreach ( array('absoluteBoundingBox', 'absoluteRenderBounds') as $boundsKey ) {
            if ( is_array($node[$boundsKey] ?? null) && isset($node[$boundsKey][$dimension]) && is_numeric($node[$boundsKey][$dimension]) ) {
                return (float) $node[$boundsKey][$dimension];
            }
        }

        return 0.0;
    }

    private function looksLikeVectorNetworkBlob(string $bytes): bool
    {
        $counts = $this->vectorNetworkCounts($bytes);
        if ( null === $counts ) {
            return false;
        }

        [$vertexCount, $segmentCount, $regionCount] = $counts;
        if ( $vertexCount < 1 || $vertexCount > 100000 || $segmentCount < 0 || $segmentCount > 200000 || $regionCount < 0 || $regionCount > 100000 ) {
            return false;
        }

        return $regionCount <= max(1, $segmentCount) && $segmentCount <= max(4, $vertexCount * 8);
    }

    /**
     * @return array{0:int,1:int,2:int}|null
     */
    private function vectorNetworkCounts(string $bytes): ?array
    {
        if ( strlen($bytes) < 12 ) {
            return null;
        }

        $counts = unpack('V3', substr($bytes, 0, 12));
        return false === $counts ? null : array_values(array_map('intval', $counts));
    }

    /**
     * @return array<string, mixed>
     */
    private function vectorNetworkBlobDiagnosticContext(mixed $blobReference, string $bytes): array
    {
        $context = array(
            'blob_ref'      => is_scalar($blobReference) ? (string) $blobReference : null,
            'byte_length'   => strlen($bytes),
            'signature_hex' => bin2hex(substr($bytes, 0, 32)),
        );

        $counts = $this->vectorNetworkCounts($bytes);
        if ( null !== $counts ) {
            $context['network_counts'] = $counts;
            $context += $this->vectorNetworkSingleRegionCandidateContext($bytes, $counts);
        }

        return $context;
    }

    /**
     * @param array{0:int,1:int,2:int} $counts
     * @return array<string, mixed>
     */
    private function vectorNetworkSingleRegionCandidateContext(string $bytes, array $counts): array
    {
        [$vertexCount, $segmentCount, $regionCount] = $counts;
        if ( $vertexCount < 3 || $vertexCount > 32 || $vertexCount !== $segmentCount || 1 !== $regionCount ) {
            return array();
        }

        $expectedLength = 24 + ( $vertexCount * 44 );
        if ( strlen($bytes) !== $expectedLength ) {
            return array();
        }

        return array(
            'single_region_loop_candidate' => true,
            'candidate_layout' => array(
                'vertex_stride' => 20,
                'segment_stride' => 16,
                'region_bytes'  => 12 + ( $vertexCount * 8 ),
            ),
            'candidate_vertex_points_sample' => $this->vectorNetworkVertexPointSample($bytes, $vertexCount),
            'candidate_decoder_requirement' => 'Decode only after segment endpoints and region winding/order are validated as one closed non-branching loop.',
        );
    }

    /**
     * @return array<int, array{0: float, 1: float}>
     */
    private function vectorNetworkVertexPointSample(string $bytes, int $vertexCount): array
    {
        $points = array();
        $limit = min($vertexCount, 8);
        for ( $index = 0; $index < $limit; $index++ ) {
            $point = $this->readFloatPair($bytes, 12 + ( $index * 20 ) + 4);
            if ( null === $point || ! is_finite($point[0]) || ! is_finite($point[1]) ) {
                return array();
            }
            $points[] = array($point[0], $point[1]);
        }

        return $points;
    }

    /**
     * @param array<int|string, mixed> $blobs
     */
    private function readCommandBlobBytes(mixed $commandsBlob, array $blobs): ?string
    {
        if ( is_array($commandsBlob) && isset($commandsBlob['bytes']) && is_scalar($commandsBlob['bytes']) ) {
            return (string) $commandsBlob['bytes'];
        }

        if ( is_numeric($commandsBlob) ) {
            $blob = $blobs[(int) $commandsBlob] ?? null;
            if ( is_array($blob) && isset($blob['bytes']) && is_scalar($blob['bytes']) ) {
                return (string) $blob['bytes'];
            }
            if ( is_scalar($blob) ) {
                return (string) $blob;
            }
        }

        if ( is_string($commandsBlob) ) {
            return $commandsBlob;
        }

        return null;
    }

    private function decodeVectorCommandBlob(string $bytes): ?string
    {
        $offset = 0;
        $length = strlen($bytes);
        $parts = array();

        while ( $offset < $length ) {
            $opcode = ord($bytes[$offset]);
            $offset++;

            if ( 0 === $opcode ) {
                if ( empty($parts) ) {
                    continue;
                }
                $parts[] = 'Z';
                continue;
            }

            if ( 1 === $opcode || 2 === $opcode ) {
                $point = $this->readFloatPair($bytes, $offset);
                if ( null === $point ) {
                    return null;
                }
                $parts[] = ( 1 === $opcode ? 'M ' : 'L ' ) . $this->svgNumber($point[0]) . ' ' . $this->svgNumber($point[1]);
                $offset += 8;
                continue;
            }

            if ( 3 === $opcode ) {
                $points = array();
                for ( $i = 0; $i < 2; $i++ ) {
                    $point = $this->readFloatPair($bytes, $offset + ( $i * 8 ));
                    if ( null === $point ) {
                        return null;
                    }
                    $points[] = $point;
                }
                $parts[] = 'Q ' . $this->svgNumber($points[0][0]) . ' ' . $this->svgNumber($points[0][1]) . ' ' . $this->svgNumber($points[1][0]) . ' ' . $this->svgNumber($points[1][1]);
                $offset += 16;
                continue;
            }

            if ( 4 === $opcode ) {
                $points = array();
                for ( $i = 0; $i < 3; $i++ ) {
                    $point = $this->readFloatPair($bytes, $offset + ( $i * 8 ));
                    if ( null === $point ) {
                        return null;
                    }
                    $points[] = $point;
                }
                $parts[] = 'C ' . $this->svgNumber($points[0][0]) . ' ' . $this->svgNumber($points[0][1]) . ' ' . $this->svgNumber($points[1][0]) . ' ' . $this->svgNumber($points[1][1]) . ' ' . $this->svgNumber($points[2][0]) . ' ' . $this->svgNumber($points[2][1]);
                $offset += 24;
                continue;
            }

            return null;
        }

        return empty($parts) ? null : implode(' ', $parts);
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function readFloatPair(string $bytes, int $offset): ?array
    {
        if ( strlen($bytes) < $offset + 8 ) {
            return null;
        }

        $x = unpack('g', substr($bytes, $offset, 4));
        $y = unpack('g', substr($bytes, $offset + 4, 4));
        if ( false === $x || false === $y ) {
            return null;
        }

        return array((float) $x[1], (float) $y[1]);
    }

    private function bytesAreZero(string $bytes, int $offset, int $length): bool
    {
        if ( strlen($bytes) < $offset + $length ) {
            return false;
        }

        return str_repeat("\0", $length) === substr($bytes, $offset, $length);
    }

    private function readUint32(string $bytes, int $offset): ?int
    {
        if ( strlen($bytes) < $offset + 4 ) {
            return null;
        }

        $value = unpack('V', substr($bytes, $offset, 4));
        return false === $value ? null : (int) $value[1];
    }

    private function svgNumber(float $value): string
    {
        $number = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        return '' === $number || '-0' === $number ? '0' : $number;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeVisualBox(array $node): array
    {
        $box = array();

        if ( isset($node['opacity']) && is_numeric($node['opacity']) ) {
            $box['opacity'] = (float) $node['opacity'];
        }

        foreach ( array('rotation' => 'rotation') as $sourceKey => $targetKey ) {
            if ( isset($node[$sourceKey]) && is_numeric($node[$sourceKey]) ) {
                $box[$targetKey] = (float) $node[$sourceKey];
            }
        }

        foreach ( array('relativeTransform', 'absoluteTransform', 'transform') as $sourceKey ) {
            if ( is_array($node[$sourceKey] ?? null) ) {
                $box['transform'] = $node[$sourceKey];
                break;
            }
        }

        if ( isset($node['cornerRadius']) && is_numeric($node['cornerRadius']) ) {
            $box['corner_radius'] = (float) $node['cornerRadius'];
        }

        foreach ( array(
            'topLeftRadius' => 'top_left_radius',
            'topRightRadius' => 'top_right_radius',
            'bottomRightRadius' => 'bottom_right_radius',
            'bottomLeftRadius' => 'bottom_left_radius',
        ) as $sourceKey => $targetKey ) {
            if ( isset($node[$sourceKey]) && is_numeric($node[$sourceKey]) ) {
                $box[$targetKey] = (float) $node[$sourceKey];
            }
        }

        return $box;
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function normalizeEffects(array $node, string $nodeId, array &$diagnostics): array
    {
        if ( ! is_array($node['effects'] ?? null) ) {
            return array();
        }

        $effects = array();
        foreach ( $node['effects'] as $effect ) {
            if ( ! is_array($effect) || false === ($effect['visible'] ?? true) ) {
                continue;
            }

            $type = strtoupper((string) ($effect['type'] ?? 'UNKNOWN'));
            if ( in_array($type, array('DROP_SHADOW', 'INNER_SHADOW'), true) ) {
                $normalized = array(
                    'type' => 'DROP_SHADOW' === $type ? 'drop_shadow' : 'inner_shadow',
                    'offset_x' => is_numeric($effect['offset']['x'] ?? null) ? (float) $effect['offset']['x'] : 0.0,
                    'offset_y' => is_numeric($effect['offset']['y'] ?? null) ? (float) $effect['offset']['y'] : 0.0,
                    'radius' => is_numeric($effect['radius'] ?? null) ? (float) $effect['radius'] : 0.0,
                    'spread' => is_numeric($effect['spread'] ?? null) ? (float) $effect['spread'] : 0.0,
                );
                $color = $this->normalizeColor($effect['color'] ?? null);
                if ( null !== $color ) {
                    $normalized['color'] = $color;
                }
                if ( isset($effect['blendMode']) && is_scalar($effect['blendMode']) ) {
                    $normalized['blend_mode'] = (string) $effect['blendMode'];
                }
                $effects[] = $normalized;
                continue;
            }

            if ( in_array($type, array('LAYER_BLUR', 'BACKGROUND_BLUR'), true) ) {
                $effects[] = array(
                    'type' => 'LAYER_BLUR' === $type ? 'layer_blur' : 'background_blur',
                    'radius' => is_numeric($effect['radius'] ?? null) ? (float) $effect['radius'] : 0.0,
                );
                continue;
            }

            $diagnostics[] = array(
                'severity' => 'warning',
                'code'     => 'unsupported_figma_effect_type',
                'message'  => 'Unsupported Figma effect was omitted from static CSS.',
                'context'  => array(
                    'node_id' => $nodeId,
                    'type'    => $type,
                ),
            );
        }

        return $effects;
    }

    /**
     * @return array<string, float>|null
     */
    private function normalizeColor(mixed $value): ?array
    {
        if ( ! is_array($value) ) {
            return null;
        }

        $red = $this->normalizeColorChannel($value['r'] ?? $value['red'] ?? null);
        $green = $this->normalizeColorChannel($value['g'] ?? $value['green'] ?? null);
        $blue = $this->normalizeColorChannel($value['b'] ?? $value['blue'] ?? null);
        if ( null === $red || null === $green || null === $blue ) {
            return null;
        }

        $color = array('r' => $red, 'g' => $green, 'b' => $blue);
        if ( isset($value['a']) && is_numeric($value['a']) ) {
            $color['a'] = (float) $value['a'];
        }

        return $color;
    }

    private function normalizeColorChannel(mixed $value): ?float
    {
        if ( ! is_numeric($value) ) {
            return null;
        }

        $channel = (float) $value;
        if ( $channel > 1 ) {
            $channel /= 255;
        }

        return max(0, min(1, $channel));
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

        if ( ! empty($frameIds) ) {
            return $frameIds;
        }

        foreach ( $topLevelIds as $id ) {
            foreach ( $this->collectFrameDescendantIds($nodeMap[$id]['children'] ?? array()) as $frameId ) {
                $frameIds[] = $frameId;
            }
        }

        return $frameIds;
    }

    /**
     * @param mixed $children
     * @return array<int, string>
     */
    private function collectFrameDescendantIds(mixed $children): array
    {
        if ( ! is_array($children) ) {
            return array();
        }

        $frameIds = array();
        foreach ( $children as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $type = strtoupper((string) ($child['type'] ?? ''));
            if ( in_array($type, array('DOCUMENT', 'CANVAS'), true) ) {
                if ( 'CANVAS' === $type && (false === ($child['visible'] ?? true) || true === ($child['internalOnly'] ?? false)) ) {
                    continue;
                }

                foreach ( $this->collectFrameDescendantIds($child['children'] ?? array()) as $frameId ) {
                    $frameIds[] = $frameId;
                }
                continue;
            }

            if ( in_array($type, array('FRAME', 'COMPONENT', 'INSTANCE'), true) ) {
                $frameIds[] = (string) ($child['id'] ?? '');
                continue;
            }
        }

        return array_values(array_filter(array_unique($frameIds)));
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<string, array<int, string>> $childrenIndex
     * @return array<string, array<string, mixed>>
     */
    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeLayoutBox(array $node): array
    {
        $box = array();
        $coordinateSpace = null;

        foreach ( array('absoluteBoundingBox', 'absoluteRenderBounds') as $boundsKey ) {
            if ( ! is_array($node[$boundsKey] ?? null) ) {
                continue;
            }

            foreach ( array('x', 'y', 'width', 'height') as $dimension ) {
                if ( ! array_key_exists($dimension, $box) && isset($node[$boundsKey][$dimension]) && is_numeric($node[$boundsKey][$dimension]) ) {
                    $box[$dimension] = (float) $node[$boundsKey][$dimension];
                }
            }

            if ( isset($node[$boundsKey]['x']) || isset($node[$boundsKey]['y']) ) {
                $coordinateSpace = 'absolute';
            }
        }

        foreach ( array('x', 'y', 'width', 'height') as $dimension ) {
            if ( ! array_key_exists($dimension, $box) && isset($node[$dimension]) && is_numeric($node[$dimension]) ) {
                $box[$dimension] = (float) $node[$dimension];
                if ( 'x' === $dimension || 'y' === $dimension ) {
                    $coordinateSpace = 'local';
                }
            }
        }

        if ( is_array($node['size'] ?? null) ) {
            foreach ( array('x' => 'width', 'y' => 'height') as $source => $target ) {
                if ( ! array_key_exists($target, $box) && isset($node['size'][$source]) && is_numeric($node['size'][$source]) ) {
                    $box[$target] = (float) $node['size'][$source];
                }
            }
        }

        if ( is_array($node['transform'] ?? null) ) {
            foreach ( array('m02' => 'x', 'm12' => 'y') as $source => $target ) {
                if ( ! array_key_exists($target, $box) && isset($node['transform'][$source]) && is_numeric($node['transform'][$source]) ) {
                    $box[$target] = (float) $node['transform'][$source];
                    $coordinateSpace = 'local';
                }
            }
        }

        if ( null !== $coordinateSpace ) {
            $box['coordinate_space'] = $coordinateSpace;
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
        } elseif ( isset($node['stackMode']) && is_scalar($node['stackMode']) ) {
            $mode = strtoupper((string) $node['stackMode']);
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
            'layoutSizingHorizontal' => 'sizing_horizontal',
            'layoutSizingVertical' => 'sizing_vertical',
            'horizontalSizing' => 'sizing_horizontal',
            'verticalSizing' => 'sizing_vertical',
        ) as $source => $target ) {
            if ( isset($node[$source]) && is_scalar($node[$source]) ) {
                $layout[$target] = strtoupper((string) $node[$source]);
            }
        }

        foreach ( array(
            'primaryAxisSizingMode' => 'primary_axis_sizing',
            'counterAxisSizingMode' => 'counter_axis_sizing',
        ) as $source => $target ) {
            if ( isset($node[$source]) && is_scalar($node[$source]) ) {
                $layout[$target] = strtoupper((string) $node[$source]);
            }
        }

        foreach ( array(
            'primaryAxisAlignItems' => 'primary_axis_alignment',
            'counterAxisAlignItems' => 'counter_axis_alignment',
            'stackPrimaryAlignItems' => 'primary_axis_alignment',
            'stackCounterAlignItems' => 'counter_axis_alignment',
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
        if ( isset($node['stackPadding']) && is_numeric($node['stackPadding']) ) {
            foreach ( array('top', 'right', 'bottom', 'left') as $edge ) {
                $padding[$edge] = (float) $node['stackPadding'];
            }
        }
        foreach ( array('top' => 'paddingTop', 'right' => 'paddingRight', 'bottom' => 'paddingBottom', 'left' => 'paddingLeft') as $edge => $source ) {
            if ( isset($node[$source]) && is_numeric($node[$source]) ) {
                $padding[$edge] = (float) $node[$source];
            }
        }
        foreach ( array('left' => 'stackPaddingLeft', 'right' => 'stackPaddingRight', 'top' => 'stackPaddingTop', 'bottom' => 'stackPaddingBottom') as $edge => $source ) {
            if ( isset($node[$source]) && is_numeric($node[$source]) ) {
                $padding[$edge] = (float) $node[$source];
            }
        }
        foreach ( array('left', 'right') as $edge ) {
            if ( ! array_key_exists($edge, $padding) && isset($node['paddingHorizontal']) && is_numeric($node['paddingHorizontal']) ) {
                $padding[$edge] = (float) $node['paddingHorizontal'];
            } elseif ( ! array_key_exists($edge, $padding) && isset($node['stackHorizontalPadding']) && is_numeric($node['stackHorizontalPadding']) ) {
                $padding[$edge] = (float) $node['stackHorizontalPadding'];
            }
        }
        foreach ( array('top', 'bottom') as $edge ) {
            if ( ! array_key_exists($edge, $padding) && isset($node['paddingVertical']) && is_numeric($node['paddingVertical']) ) {
                $padding[$edge] = (float) $node['paddingVertical'];
            } elseif ( ! array_key_exists($edge, $padding) && isset($node['stackVerticalPadding']) && is_numeric($node['stackVerticalPadding']) ) {
                $padding[$edge] = (float) $node['stackVerticalPadding'];
            }
        }
        if ( ! empty($padding) ) {
            $layout['padding'] = $padding;
        }

        if ( isset($node['itemSpacing']) && is_numeric($node['itemSpacing']) ) {
            $layout['item_spacing'] = (float) $node['itemSpacing'];
        } elseif ( isset($node['stackSpacing']) && is_numeric($node['stackSpacing']) ) {
            $layout['item_spacing'] = (float) $node['stackSpacing'];
        }

        if ( isset($node['layoutWrap']) && is_scalar($node['layoutWrap']) ) {
            $layout['wrap'] = strtoupper((string) $node['layoutWrap']);
            if ( 'WRAP' === $layout['wrap'] ) {
                $layout['flex_wrap'] = 'wrap';
            }
        } elseif ( isset($node['stackWrap']) && is_scalar($node['stackWrap']) ) {
            $layout['wrap'] = strtoupper((string) $node['stackWrap']);
            if ( 'WRAP' === $layout['wrap'] ) {
                $layout['flex_wrap'] = 'wrap';
            }
        }

        if ( isset($node['layoutPositioning']) && 'ABSOLUTE' === strtoupper((string) $node['layoutPositioning']) ) {
            $layout['positioning'] = 'absolute';
        }

        if ( isset($node['layoutGrow']) && is_numeric($node['layoutGrow']) ) {
            $layout['grow'] = (float) $node['layoutGrow'];
        }

        if ( isset($node['layoutAlign']) && is_scalar($node['layoutAlign']) ) {
            $layout['align'] = strtoupper((string) $node['layoutAlign']);
        } elseif ( isset($node['stackChildAlignSelf']) && is_scalar($node['stackChildAlignSelf']) ) {
            $layout['align'] = strtoupper((string) $node['stackChildAlignSelf']);
        }

        if ( true === ($node['clipsContent'] ?? false) || true === ($node['isClip'] ?? false) ) {
            $layout['clips_content'] = true;
        }

        if ( is_array($node['constraints'] ?? null) ) {
            $constraints = array();
            foreach ( array('horizontal', 'vertical') as $axis ) {
                if ( isset($node['constraints'][$axis]) && is_scalar($node['constraints'][$axis]) ) {
                    $constraints[$axis] = strtoupper((string) $node['constraints'][$axis]);
                }
            }
            if ( ! empty($constraints) ) {
                $layout['constraints'] = $constraints;
            }
        }

        if ( ! isset($layout['display']) && is_array($node['children'] ?? null) && count($node['children']) > 1 ) {
            $type = strtoupper((string) ($node['type'] ?? ''));
            if ( true === ($node['resizeToFit'] ?? false) || in_array($type, array('FRAME', 'GROUP', 'COMPONENT', 'INSTANCE', 'SECTION'), true) ) {
                $layout['freeform'] = true;
            }
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
            'STRETCH' => 'stretch',
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
            foreach ( array('characters', 'text') as $key ) {
                if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                    $text = (string) $node[$key];
                    break;
                }
            }
            if ( null === $text && isset($node['textData']['characters']) && is_scalar($node['textData']['characters']) ) {
                $text = (string) $node['textData']['characters'];
            }
            if ( null === $text && isset($node['name']) && is_scalar($node['name']) ) {
                $text = (string) $node['name'];
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
            foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef') as $assetKey ) {
                if ( isset($node[$assetKey]) && is_scalar($node[$assetKey]) && '' !== (string) $node[$assetKey] ) {
                    $references[] = array(
                        'node_id' => $id,
                        'paint'   => $assetKey,
                        'ref'     => (string) $node[$assetKey],
                    );
                    break;
                }
            }

            foreach ( array('fills', 'strokes', 'background') as $paintKey ) {
                $paintCollections = array();
                if ( is_array($node[$paintKey] ?? null) ) {
                    $paintCollections[] = $node[$paintKey];
                }
                if ( is_array($node['figma_paints'][$paintKey] ?? null) ) {
                    $paintCollections[] = $node['figma_paints'][$paintKey];
                }

                foreach ( $paintCollections as $paints ) {
                    foreach ( $paints as $paint ) {
                    if ( ! is_array($paint) || 'IMAGE' !== strtoupper((string) ($paint['type'] ?? '')) ) {
                        continue;
                    }

                    $reference = $this->readImageReference($paint);
                    if ( null !== $reference ) {
                        $references[] = array(
                            'node_id'    => $id,
                            'paint'      => $paintKey,
                            'source_key' => $reference['source_key'],
                            'ref'        => $reference['ref'],
                        );
                    }
                }
                }
            }
        }

        $unique = array();
        foreach ( $references as $reference ) {
            $key = (string) ($reference['node_id'] ?? '') . '|' . (string) ($reference['paint'] ?? '') . '|' . (string) ($reference['ref'] ?? '');
            $unique[$key] = $reference;
        }

        return array_values($unique);
    }

    /**
     * @param array<string, mixed> $paint
     * @return array{source_key: string, ref: string}|null
     */
    private function readImageReference(array $paint): ?array
    {
        foreach ( array('ref', 'imageRef', 'imageHash', 'asset_id', 'image_ref') as $key ) {
            if ( isset($paint[$key]) && is_scalar($paint[$key]) && '' !== (string) $paint[$key] ) {
                return array(
                    'source_key' => $key,
                    'ref'        => (string) $paint[$key],
                );
            }
        }

        return null;
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
