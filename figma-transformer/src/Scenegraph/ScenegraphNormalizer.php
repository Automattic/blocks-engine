<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Normalizes decoded Figma scenegraph payloads into a deterministic transformer contract.
 */
final class ScenegraphNormalizer
{
    private const GEOMETRY_SEMANTICS_COMPONENT_SOURCE_CLONE = 'component_source_clone';

    private readonly TextNormalizer $textNormalizer;

    public function __construct(
        private readonly ScenegraphIndex $index = new ScenegraphIndex(),
        private readonly VectorGeometryNormalizer $vectorGeometryNormalizer = new VectorGeometryNormalizer(),
        private readonly PaintNormalizer $paintNormalizer = new PaintNormalizer(),
        ?TextNormalizer $textNormalizer = null
    ) {
        $this->textNormalizer = $textNormalizer ?? new TextNormalizer($this->vectorGeometryNormalizer);
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
        $textStyles  = $this->buildTextStyleDefinitions($index['nodes']);
        $nodeMap     = $this->normalizeNodeMap($index['nodes'], $diagnostics, $blobs, $paintStyles, $textStyles);
        $this->applyReverseChildZIndex($nodeMap);
        $components  = $this->buildComponentDefinitions($nodeMap);
        $componentDefinitionCount = $this->countComponentDefinitions($nodeMap);
        $instanceReport = $this->resolveInstances($nodeMap, $components, $diagnostics, $blobs, $paintStyles, $textStyles);
        $this->applyReverseChildZIndex($nodeMap);
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
        $renderDocument = ! empty($options['render_document']);
        if ( $renderDocument ) {
            $documentFrameIds = $this->documentFrameIds($options, $frameIds, $nodeMap);
            foreach ( $documentFrameIds as $documentFrameId ) {
                $rebasedFrame = $this->rebasePageFrameToLocalOrigin($this->refreshResolvedTree($nodeMap[$documentFrameId], $nodeMap));
                $this->appendNodeMap($rebasedFrame, $nodeMap);
            }
        }
        if ( ! $renderDocument && null !== $selectedFrameId && 1 === count($topLevelIds) && $selectedFrameId !== $topLevelIds[0] ) {
            $renderIds = array($selectedFrameId);
        }
        $renderNodes = array();
        foreach ( $renderIds as $id ) {
            if ( isset($nodeMap[$id]) ) {
                $node = $this->refreshResolvedTree($nodeMap[$id], $nodeMap);
                if ( $explicitSelectedFrameId && $id === $selectedFrameId ) {
                    $node = $this->rebasePageFrameToLocalOrigin($node);
                }
                $renderNodes[] = $node;
            }
        }

        $textInventory   = $this->buildTextInventory($nodeMap);
        $assetReferences = $this->buildAssetReferences($nodeMap);
        $sourceName      = $this->readSourceName($source, $renderNodes);
        $diagnostics     = $this->vectorGeometryNormalizer->compactUnsupportedVectorNetworkBlobDiagnostics($this->compactGlyphCommandBlobDiagnostics($diagnostics));

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
    private function normalizeNodeMap(array $nodeMap, array &$diagnostics, array $blobs = array(), array $paintStyles = array(), array $textStyles = array()): array
    {
        foreach ( $nodeMap as $id => $node ) {
            $nodeMap[$id] = $this->normalizeNode($node, $diagnostics, $blobs, $paintStyles, $textStyles);
        }

        return $nodeMap;
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function normalizeNode(array $node, array &$diagnostics, array $blobs = array(), array $paintStyles = array(), array $textStyles = array()): array
    {
        $id = (string) ($node['id'] ?? '');
        $type = strtoupper((string) ($node['type'] ?? ''));

        $component = $this->normalizeComponentMetadata($node, $type);
        if ( ! empty($component) ) {
            $node['figma_component'] = $component;
        }

        if ( 'TEXT' === $type ) {
            $text = $this->textNormalizer->normalizeText($node, $blobs, $id, $diagnostics, $paintStyles, $textStyles);
            if ( ! empty($text) ) {
                $node['figma_text'] = $text;
            }
        }

        $paints = $this->normalizePaintCollections($node, $id, $diagnostics, $paintStyles);
        if ( ! empty($paints) ) {
            $node['figma_paints'] = $paints;
        }

        $vectorPaths = $this->vectorGeometryNormalizer->normalizeVectorPaths($node, $blobs, $id, $diagnostics);
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
                    $normalizedChild = $this->normalizeNode($child, $diagnostics, $blobs, $paintStyles, $textStyles);
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
     * @param array<string, array<string, mixed>> $nodeMap
     */
    private function applyReverseChildZIndex(array &$nodeMap): void
    {
        foreach ( $nodeMap as $node ) {
            if ( true !== ($node['layout']['reverse_z_index'] ?? false) || ! is_array($node['children'] ?? null) ) {
                continue;
            }

            $children = array_values(array_filter($node['children'], 'is_array'));
            $childCount = count($children);
            foreach ( $children as $index => $child ) {
                $childId = isset($child['id']) && is_scalar($child['id']) ? (string) $child['id'] : '';
                if ( '' === $childId || ! isset($nodeMap[$childId]) ) {
                    continue;
                }

                $layout = is_array($nodeMap[$childId]['layout'] ?? null) ? $nodeMap[$childId]['layout'] : array();
                $layout['z_index'] = $childCount - (int) $index;
                $nodeMap[$childId]['layout'] = $layout;
            }
        }
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

            $paints = $this->paintNormalizer->normalizePaintList(is_array($node['fillPaints'] ?? null) ? $node['fillPaints'] : (is_array($node['fills'] ?? null) ? $node['fills'] : array()), $id, 'style.fillPaints', $diagnostics);
            if ( ! empty($paints) ) {
                $styles[$id]['fills'] = $paints;
            }
        }

        return $styles;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<string, array<string, mixed>>
     */
    private function buildTextStyleDefinitions(array $nodeMap): array
    {
        $styles = array();
        foreach ( $nodeMap as $id => $node ) {
            if ( 'TEXT' !== strtoupper((string) ($node['styleType'] ?? '')) || 'TEXT' !== strtoupper((string) ($node['type'] ?? '')) ) {
                continue;
            }

            $styles[$id] = $node;
        }

        return $styles;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<string, array<string, mixed>> $components
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array{instance_node_count: int, resolved_instance_count: int, unresolved_component_references: array<int, array<string, string>>}
     */
    private function resolveInstances(array &$nodeMap, array $components, array &$diagnostics, array $blobs = array(), array $paintStyles = array(), array $textStyles = array()): array
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

            $resolved = $this->cloneComponentForInstance($components[$reference['id']], $node, $reference['id'], $overrides, $nodeMap, $components, $diagnostics, $blobs, $paintStyles, $textStyles, array($id));
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
        $sourceId = (string) ($node['figma_component_source_id'] ?? '');
        $refreshId = '' !== $id && isset($nodeMap[$id]) ? $id : $sourceId;
        $refreshesComponentSourceClone = '' !== $sourceId && $refreshId === $sourceId && $this->isRefreshableComponentSourceClone($node, $nodeMap[$refreshId] ?? array());
        if ( '' !== $refreshId && isset($nodeMap[$refreshId]) && ! in_array($refreshId, $trail, true) && ($refreshId === $id || ($refreshesComponentSourceClone && ! $this->subtreeHasInstanceOverrideApplied($node))) ) {
            $node = $refreshId === $id
                ? $nodeMap[$refreshId]
                : $this->mergeRefreshedComponentSource($node, $nodeMap[$refreshId], $refreshId);
            $trail[] = $refreshId;
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

		return $this->repairFarComponentSourceCloneGeometry($node, $nodeMap);
	}

	/**
	 * @param array<string, mixed> $node
	 * @param array<string, array<string, mixed>> $nodeMap
	 * @return array<string, mixed>
	 */
	private function repairFarComponentSourceCloneGeometry(array $node, array $nodeMap): array
	{
		$sourceId = isset($node['figma_component_source_id']) && is_scalar($node['figma_component_source_id']) ? (string) $node['figma_component_source_id'] : '';
		if ( '' === $sourceId || ! is_array($nodeMap[$sourceId]['box'] ?? null) || ! is_array($node['box'] ?? null) ) {
			return $node;
		}

		$sourceBox = $nodeMap[$sourceId]['box'];
		if ( GeometryBox::COORDINATE_SPACE_PARENT_LOCAL !== GeometryBox::coordinateSpace($sourceBox) ) {
			return $node;
		}

		$repaired = false;
		foreach ( array('x', 'y') as $dimension ) {
			if ( isset($sourceBox[$dimension], $node['box'][$dimension]) && is_numeric($sourceBox[$dimension]) && is_numeric($node['box'][$dimension]) && abs((float) $node['box'][$dimension] - (float) $sourceBox[$dimension]) >= 1000.0 ) {
				$node[$dimension] = (float) $sourceBox[$dimension];
				$node['box'][$dimension] = (float) $sourceBox[$dimension];
				if ( is_array($node['figma_box'] ?? null) && isset($node['figma_box'][$dimension]) && is_numeric($node['figma_box'][$dimension]) ) {
					$node['figma_box'][$dimension] = (float) $sourceBox[$dimension];
				}
				$repaired = true;
			}
		}

		if ( $repaired ) {
			$node['box']['coordinate_space'] = GeometryBox::COORDINATE_SPACE_PARENT_LOCAL;
			if ( is_array($node['figma_box'] ?? null) ) {
				$node['figma_box']['coordinate_space'] = GeometryBox::COORDINATE_SPACE_PARENT_LOCAL;
			}
		}

		return $node;
	}

	/**
	 * @param array<string, mixed> $clone
     * @param array<string, mixed> $refreshed
     */
    private function isRefreshableComponentSourceClone(array $clone, array $refreshed): bool
    {
        $cloneType = strtoupper((string) ($clone['type'] ?? ''));
        $refreshedType = strtoupper((string) ($refreshed['type'] ?? ''));

        return 'INSTANCE' === $cloneType || 'INSTANCE' === $refreshedType || true === ($clone['figma_component']['resolved'] ?? false) || true === ($refreshed['figma_component']['resolved'] ?? false);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function subtreeHasInstanceOverrideApplied(array $node): bool
    {
        if ( true === ($node['_figma_instance_override_applied'] ?? false) ) {
            return true;
        }

        foreach ( is_array($node['children'] ?? null) ? $node['children'] : array() as $child ) {
            if ( is_array($child) && $this->subtreeHasInstanceOverrideApplied($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refresh a namespaced component-source clone without replacing its instance placement.
     *
     * @param array<string, mixed> $clone
     * @param array<string, mixed> $refreshed
     * @return array<string, mixed>
     */
    private function mergeRefreshedComponentSource(array $clone, array $refreshed, string $sourceId): array
    {
        $cloneId = (string) ($clone['id'] ?? '');
        $sourceBox = is_array($refreshed['box'] ?? null) ? $refreshed['box'] : array();
        $merged = '' !== $cloneId ? $this->retargetComponentSourceIds($refreshed, $sourceId, $cloneId) : $refreshed;

		$preferRefreshedGeometry = $this->componentSourceCloneShouldUseRefreshedGeometry($clone, $refreshed);
		foreach ( array('id', 'figma_component_source_id', 'box', 'figma_box', 'layout', 'x', 'y', 'width', 'height') as $key ) {
			if ( $preferRefreshedGeometry && in_array($key, array('box', 'figma_box', 'x', 'y'), true) ) {
				continue;
			}
			if ( array_key_exists($key, $clone) ) {
				$merged[$key] = $clone[$key];
			}
		}
		if ( $preferRefreshedGeometry && is_array($refreshed['box'] ?? null) ) {
			foreach ( array('x', 'y') as $dimension ) {
				if ( isset($refreshed['box'][$dimension]) && is_numeric($refreshed['box'][$dimension]) ) {
					$merged[$dimension] = (float) $refreshed['box'][$dimension];
				}
			}
		}

        $sourceX = isset($sourceBox['x']) && is_numeric($sourceBox['x']) ? (float) $sourceBox['x'] : null;
        $sourceY = isset($sourceBox['y']) && is_numeric($sourceBox['y']) ? (float) $sourceBox['y'] : null;
        $sourceWidth = isset($sourceBox['width']) && is_numeric($sourceBox['width']) ? (float) $sourceBox['width'] : null;
        $sourceHeight = isset($sourceBox['height']) && is_numeric($sourceBox['height']) ? (float) $sourceBox['height'] : null;
        if ( null !== $sourceX || null !== $sourceY ) {
            $merged = $this->rebaseComponentSourceCloneDescendants($merged, $sourceX, $sourceY, $sourceWidth, $sourceHeight);
        }

		return $this->markComponentSourceCloneGeometry($merged);
	}

	/**
	 * @param array<string, mixed> $clone
	 * @param array<string, mixed> $refreshed
	 */
	private function componentSourceCloneShouldUseRefreshedGeometry(array $clone, array $refreshed): bool
	{
		$cloneBox = is_array($clone['box'] ?? null) ? $clone['box'] : array();
		$refreshedBox = is_array($refreshed['box'] ?? null) ? $refreshed['box'] : array();
		if ( GeometryBox::COORDINATE_SPACE_PARENT_LOCAL !== GeometryBox::coordinateSpace($refreshedBox) ) {
			return false;
		}

		$hasComponentSource = isset($clone['figma_component_source_id']) && is_scalar($clone['figma_component_source_id']) && '' !== (string) $clone['figma_component_source_id'];
		if ( ! $hasComponentSource && empty($clone['_component_source_clone_geometry']) && self::GEOMETRY_SEMANTICS_COMPONENT_SOURCE_CLONE !== ($cloneBox['geometry_semantics'] ?? null) ) {
			return false;
		}

		foreach ( array('x', 'y') as $dimension ) {
			if ( isset($cloneBox[$dimension], $refreshedBox[$dimension]) && is_numeric($cloneBox[$dimension]) && is_numeric($refreshedBox[$dimension]) && abs((float) $cloneBox[$dimension] - (float) $refreshedBox[$dimension]) >= 1000.0 ) {
				return true;
			}
			if ( isset($clone[$dimension], $refreshedBox[$dimension]) && is_numeric($clone[$dimension]) && is_numeric($refreshedBox[$dimension]) && abs((float) $clone[$dimension] - (float) $refreshedBox[$dimension]) >= 1000.0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function rebaseComponentSourceCloneDescendants(array $node, ?float $parentSourceX, ?float $parentSourceY, ?float $parentSourceWidth = null, ?float $parentSourceHeight = null): array
    {
        if ( ! is_array($node['children'] ?? null) ) {
            return $node;
        }

        foreach ( $node['children'] as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $childSourceX = $this->componentSourceCloneBoxCoordinate($child, 'x');
            $childSourceY = $this->componentSourceCloneBoxCoordinate($child, 'y');
            $childSourceWidth = $this->componentSourceCloneBoxCoordinate($child, 'width');
            $childSourceHeight = $this->componentSourceCloneBoxCoordinate($child, 'height');
            $child = $this->rebaseComponentSourceCloneBox($child, 'box', $parentSourceX, $parentSourceY, $parentSourceWidth, $parentSourceHeight);
            $child = $this->rebaseComponentSourceCloneBox($child, 'figma_box', $parentSourceX, $parentSourceY, $parentSourceWidth, $parentSourceHeight);
            $node['children'][$index] = $this->rebaseComponentSourceCloneDescendants(
                $child,
                null !== $childSourceX ? $childSourceX : $parentSourceX,
                null !== $childSourceY ? $childSourceY : $parentSourceY,
                null !== $childSourceWidth ? $childSourceWidth : $parentSourceWidth,
                null !== $childSourceHeight ? $childSourceHeight : $parentSourceHeight
            );
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function componentSourceCloneBoxCoordinate(array $node, string $dimension): ?float
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        return isset($box[$dimension]) && is_numeric($box[$dimension]) ? (float) $box[$dimension] : null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function rebaseComponentSourceCloneBox(array $node, string $boxKey, ?float $parentSourceX, ?float $parentSourceY, ?float $parentSourceWidth = null, ?float $parentSourceHeight = null): array
    {
        if ( ! is_array($node[$boxKey] ?? null) ) {
            return $node;
        }

        $box = $node[$boxKey];
        if ( 'page' !== ($box['local_origin'] ?? null) && GeometryBox::COORDINATE_SPACE_CANVAS_ABSOLUTE !== GeometryBox::coordinateSpace($box) ) {
            return $node;
        }

        foreach ( array('x' => array($parentSourceX, $parentSourceWidth), 'y' => array($parentSourceY, $parentSourceHeight)) as $dimension => $parentSource ) {
            [$parentSourceCoordinate, $parentSourceSize] = $parentSource;
            if ( null === $parentSourceCoordinate || ! isset($node[$boxKey][$dimension]) || ! is_numeric($node[$boxKey][$dimension]) ) {
                continue;
            }
            if ( GeometryBox::COORDINATE_SPACE_CANVAS_ABSOLUTE === GeometryBox::coordinateSpace($box) && ! $this->componentSourceCoordinateOverlapsParent((float) $node[$boxKey][$dimension], $parentSourceCoordinate, $parentSourceSize) ) {
                continue;
            }

            $node[$boxKey][$dimension] = (float) $node[$boxKey][$dimension] - $parentSourceCoordinate;
        }

        unset($node[$boxKey]['local_origin']);
        $node[$boxKey]['coordinate_space'] = GeometryBox::COORDINATE_SPACE_PARENT_LOCAL;

        return $node;
    }

    private function componentSourceCoordinateOverlapsParent(float $coordinate, float $parentSourceCoordinate, ?float $parentSourceSize): bool
    {
        if ( null === $parentSourceSize ) {
            return $coordinate >= $parentSourceCoordinate - 0.5;
        }

        return $coordinate >= $parentSourceCoordinate - 0.5 && $coordinate <= $parentSourceCoordinate + $parentSourceSize + 0.5;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function retargetComponentSourceIds(array $node, string $sourceId, string $cloneId): array
    {
        foreach ( array('id', 'figma_component_source_id') as $key ) {
            if ( ! isset($node[$key]) || ! is_scalar($node[$key]) ) {
                continue;
            }

            $id = (string) $node[$key];
            if ( $sourceId === $id ) {
                $node[$key] = 'id' === $key ? $cloneId : $sourceId;
            } elseif ( str_starts_with($id, $sourceId . '/') ) {
                $node[$key] = ('id' === $key ? $cloneId : $sourceId) . substr($id, strlen($sourceId));
            }
        }

        if ( is_array($node['children'] ?? null) ) {
            foreach ( $node['children'] as $index => $child ) {
                if ( is_array($child) ) {
                    $node['children'][$index] = $this->retargetComponentSourceIds($child, $sourceId, $cloneId);
                }
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function markComponentSourceCloneGeometry(array $node): array
    {
        $node['_component_source_clone_geometry'] = true;
        foreach ( array('box', 'figma_box') as $boxKey ) {
            if ( ! is_array($node[$boxKey] ?? null) ) {
                continue;
            }

			$sourceKind = GeometryBox::sourceKind($node[$boxKey]);
			foreach ( array('x', 'y', 'width', 'height') as $dimension ) {
				$hasRebasedLocalCoordinate = in_array($dimension, array('x', 'y'), true)
					&& isset($node[$boxKey][$dimension])
					&& GeometryBox::COORDINATE_SPACE_PARENT_LOCAL === GeometryBox::coordinateSpace($node[$boxKey]);
				if ( ! $hasRebasedLocalCoordinate && isset($node[$dimension]) && is_numeric($node[$dimension]) ) {
					$node[$boxKey][$dimension] = (float) $node[$dimension];
				}
			}

            $node[$boxKey] = GeometryBox::withoutProvenance(GeometryBox::withProvenance($node[$boxKey], GeometryBox::SOURCE_COMPONENT_CLONE));
            $node[$boxKey]['geometry_semantics'] = self::GEOMETRY_SEMANTICS_COMPONENT_SOURCE_CLONE;
            if ( null !== $sourceKind ) {
                $node[$boxKey]['component_clone_source_kind'] = $sourceKind;
            }
        }

        if ( is_array($node['children'] ?? null) ) {
            foreach ( $node['children'] as $index => $child ) {
                if ( is_array($child) ) {
                    $node['children'][$index] = $this->markComponentSourceCloneGeometry($child);
                }
            }
        }

        return $node;
    }

    /**
     * Treat an explicitly selected page frame as the emitted document origin instead of preserving Figma canvas offsets.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function rebasePageFrameToLocalOrigin(array $node): array
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $originX = isset($box['x']) && is_numeric($box['x']) ? (float) $box['x'] : 0.0;
        $originY = isset($box['y']) && is_numeric($box['y']) ? (float) $box['y'] : 0.0;
        $node['_selected_frame_root'] = true;

        return $this->rebaseCanvasCoordinateBoxesToPageLocal($node, $originX, $originY, true);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<int, string>   $fallbackFrameIds
     * @param array<string, mixed> $nodeMap
     * @return array<int, string>
     */
    private function documentFrameIds(array $options, array $fallbackFrameIds, array $nodeMap): array
    {
        $rawFrameIds = is_array($options['document_frame_ids'] ?? null) ? $options['document_frame_ids'] : $fallbackFrameIds;
        $frameIds = array();
        foreach ( $rawFrameIds as $id ) {
            if ( ! is_scalar($id) ) {
                continue;
            }

            $frameId = (string) $id;
            if ( '' !== $frameId && isset($nodeMap[$frameId]) ) {
                $frameIds[$frameId] = $frameId;
            }
        }

        return array_values($frameIds);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $nodeMap
     */
    private function appendNodeMap(array $node, array &$nodeMap): void
    {
        $id = (string) ($node['id'] ?? '');
        if ( '' !== $id ) {
            $nodeMap[$id] = $node;
        }

        foreach ( array('children', 'nodes') as $childrenKey ) {
            if ( ! is_array($node[$childrenKey] ?? null) ) {
                continue;
            }

            foreach ( $node[$childrenKey] as $child ) {
                if ( is_array($child) ) {
                    $this->appendNodeMap($child, $nodeMap);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function rebaseCanvasCoordinateBoxesToPageLocal(array $node, float $originX, float $originY, bool $isRoot = false): array
    {
        $node = $this->rebaseCanvasCoordinateBoxToPageLocal($node, 'box', $originX, $originY, $isRoot);
        $node = $this->rebaseCanvasCoordinateBoxToPageLocal($node, 'figma_box', $originX, $originY, $isRoot);

        foreach ( array('children', 'nodes') as $childrenKey ) {
            if ( ! is_array($node[$childrenKey] ?? null) ) {
                continue;
            }

            foreach ( $node[$childrenKey] as $index => $child ) {
                if ( is_array($child) ) {
                    $node[$childrenKey][$index] = $this->rebaseCanvasCoordinateBoxesToPageLocal($child, $originX, $originY);
                }
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function rebaseCanvasCoordinateBoxToPageLocal(array $node, string $boxKey, float $originX, float $originY, bool $isRoot): array
    {
        if ( ! is_array($node[$boxKey] ?? null) ) {
            return $node;
        }

        $box = $node[$boxKey];
        if ( ! $isRoot && $this->isComponentSourceCloneDescendant($node, $box) ) {
            $box = GeometryBox::withoutProvenance($box);
            $box['coordinate_space'] = GeometryBox::COORDINATE_SPACE_PARENT_LOCAL;
            $node[$boxKey] = $box;
            return $node;
        }

        $coordinateSpace = GeometryBox::coordinateSpace($box);
        if ( $isRoot || GeometryBox::COORDINATE_SPACE_CANVAS_ABSOLUTE === $coordinateSpace ) {
            if ( isset($box['x']) && is_numeric($box['x']) ) {
                $box['x'] = $isRoot ? 0.0 : (float) $box['x'] - $originX;
            }
            if ( isset($box['y']) && is_numeric($box['y']) ) {
                $box['y'] = $isRoot ? 0.0 : (float) $box['y'] - $originY;
            }
            $box = GeometryBox::withProvenance($box, GeometryBox::SOURCE_EXPLICIT_LOCAL, $isRoot);
            $box = GeometryBox::withoutProvenance($box);
            $box['local_origin'] = 'page';
        }

        $node[$boxKey] = $box;
        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $box
     */
    private function isComponentSourceCloneDescendant(array $node, array $box): bool
    {
        $sourceKind = isset($box['component_clone_source_kind']) && is_scalar($box['component_clone_source_kind']) ? (string) $box['component_clone_source_kind'] : null;
        if ( in_array($sourceKind, array(GeometryBox::SOURCE_TRANSFORM, GeometryBox::SOURCE_ABSOLUTE_TRANSFORM, GeometryBox::SOURCE_OVERRIDE_TRANSFORM), true) ) {
            return false;
        }

        if ( ! empty($node['_component_source_clone_geometry']) || self::GEOMETRY_SEMANTICS_COMPONENT_SOURCE_CLONE === ($box['geometry_semantics'] ?? null) ) {
            return true;
        }

        $id = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
        $sourceId = isset($node['figma_component_source_id']) && is_scalar($node['figma_component_source_id']) ? (string) $node['figma_component_source_id'] : '';
        if ( '' === $id || '' === $sourceId || $id === $sourceId || ! str_contains($id, '/') ) {
            return false;
        }

        $x = isset($box['x']) && is_numeric($box['x']) ? abs((float) $box['x']) : 0.0;
        $y = isset($box['y']) && is_numeric($box['y']) ? abs((float) $box['y']) : 0.0;
        return $x < 1000.0 && $y < 1000.0;
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
            foreach ( array('derivedTextData', 'fontName', 'fontFamily', 'fontPostScriptName', 'fontWeight', 'fontSize', 'lineHeight', 'lineHeightPx', 'lineHeightPercent', 'letterSpacing', 'styleIdForText', 'size', 'relativeTransform', 'absoluteTransform', 'transform', 'fillPaints', 'fills', 'strokes', 'strokePaints', 'strokeWeight', 'strokeAlign', 'dashPattern', 'borderStrokeWeightsIndependent', 'borderTopWeight', 'borderBottomWeight', 'borderLeftWeight', 'borderRightWeight', 'effects', 'styleIdForFill', 'fillGeometry', 'strokeGeometry', 'vectorPaths', 'paths', 'pathData', 'path', 'd', 'cornerRadius', 'rectangleTopLeftCornerRadius', 'rectangleTopRightCornerRadius', 'rectangleBottomLeftCornerRadius', 'rectangleBottomRightCornerRadius', 'componentPropAssignments') as $field ) {
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
    private function cloneComponentForInstance(array $component, array $instance, string $componentId, array $overrides, array $nodeMap, array $components, array &$diagnostics, array $blobs = array(), array $paintStyles = array(), array $textStyles = array(), array $resolutionTrail = array()): array
    {
        $context = $this->buildInstanceCloneContext($component, $instance, $componentId, $overrides);
        $resolved = $component;
        $resolved['id'] = $context['instance_id'];
        $resolved['type'] = 'INSTANCE';
        $resolved['name'] = (string) ($instance['name'] ?? $resolved['name'] ?? '');
        // The resolved node stands in for the instance placement, so its
        // visibility is governed by the instance, not the component definition.
        // Without this, a designer-hidden (visible:false) instance would inherit
        // the definition's visibility and incorrectly emit to HTML.
        $resolved['visible'] = $instance['visible'] ?? true;

        foreach ( array('box', 'figma_box', 'layout', 'figma_paints', 'figma_effects', 'figma_link', 'figma_vector_paths', 'componentProperties', 'fillPaints', 'effects', 'styleIdForFill', 'fillGeometry', 'strokeGeometry', 'vectorPaths', 'paths', 'pathData', 'path', 'd', 'strokeWeight', 'strokeAlign', 'dashPattern', 'borderStrokeWeightsIndependent', 'borderTopWeight', 'borderBottomWeight', 'borderLeftWeight', 'borderRightWeight') as $key ) {
            if ( array_key_exists($key, $instance) ) {
                $resolved[$key] = $instance[$key];
            }
        }

        $resolved['figma_component'] = array_merge(
            is_array($instance['figma_component'] ?? null) ? $instance['figma_component'] : array(),
            array(
                'role'               => 'instance',
                'instance_id'        => $context['instance_id'],
                'component_id'       => $context['component_id'],
                'definition_node_id' => $context['definition_node_id'],
                'resolved'           => true,
            )
        );
        $resolvedChildren = is_array($resolved['children'] ?? null) ? $resolved['children'] : array();
        $resolvedChildren = $this->resolveClonedInstanceChildren($resolvedChildren, $nodeMap, $components, $diagnostics, $blobs, $paintStyles, $textStyles, $resolutionTrail);
        $resolvedChildren = $this->scaleVectorOnlyInstanceChildren($resolvedChildren, $component, $instance);
        $componentBox = is_array($component['box'] ?? null) ? $component['box'] : array();
        $componentSourceX = isset($componentBox['x']) && is_numeric($componentBox['x']) ? (float) $componentBox['x'] : null;
        $componentSourceY = isset($componentBox['y']) && is_numeric($componentBox['y']) ? (float) $componentBox['y'] : null;
        $componentSourceWidth = isset($componentBox['width']) && is_numeric($componentBox['width']) ? (float) $componentBox['width'] : null;
        $componentSourceHeight = isset($componentBox['height']) && is_numeric($componentBox['height']) ? (float) $componentBox['height'] : null;
        if ( null !== $componentSourceX || null !== $componentSourceY ) {
            $rebasedSource = $this->rebaseComponentSourceCloneDescendants(array('children' => $resolvedChildren), $componentSourceX, $componentSourceY, $componentSourceWidth, $componentSourceHeight);
            $resolvedChildren = is_array($rebasedSource['children'] ?? null) ? $rebasedSource['children'] : $resolvedChildren;
        }
        // Figma binds per-instance text content through component properties: each
        // master text node references a property definition (componentPropRefs ->
        // componentPropNodeField: TEXT_DATA) and the instance assigns the real value
        // (componentPropAssignments -> value.textValue.characters). Fold those text
        // assignments into the override map keyed by the consuming node id so the
        // existing override machinery renders each instance's own content instead of
        // the component master's default placeholder.
        $overrides = $context['overrides'];
        if ( $this->instanceOverridesUseTransforms($overrides) ) {
            $resolved['layout'] = array('freeform' => true);
        }
        $resolved['children'] = $this->namespaceResolvedInstanceChildren(
            $this->applyInstanceOverridesToChildren($resolvedChildren, $overrides, $nodeMap, $components, $diagnostics, $blobs, $paintStyles, $textStyles),
            $context['instance_id']
        );

        return $resolved;
    }

    /**
     * Gather the stable identifiers and normalized overrides that drive a resolved
     * instance clone. Keeping these together makes the clone steps below explicit:
     * preserve instance placement, refresh source children, apply overrides, then
     * namespace cloned source ids under the instance id.
     *
     * @param array<string, mixed>                 $component
     * @param array<string, mixed>                 $instance
     * @param array<string, array<string, mixed>> $overrides
     * @return array{instance_id: string, component_id: string, definition_node_id: string, overrides: array<string, array<string, mixed>>}
     */
    private function buildInstanceCloneContext(array $component, array $instance, string $componentId, array $overrides): array
    {
        return array(
            'instance_id'        => (string) ($instance['id'] ?? $component['id'] ?? ''),
            'component_id'       => $componentId,
            'definition_node_id' => (string) ($component['id'] ?? ''),
            'overrides'          => $this->mergeComponentPropertyOverrides($overrides, $instance, $component),
        );
    }

    /**
     * Fold component-property assignments into the override map.
     *
     * @param array<string, array<string, mixed>> $overrides Existing override map keyed by node id.
     * @param array<string, mixed>                 $instance  Instance node carrying componentPropAssignments.
     * @param array<string, mixed>                 $component Component definition whose nodes carry componentPropRefs.
     * @return array<string, array<string, mixed>>
     */
    private function mergeComponentPropertyOverrides(array $overrides, array $instance, array $component): array
    {
        $overrides = $this->mergeComponentPropertyTextOverrides($overrides, $instance, $component);
        $overrides = $this->mergeComponentPropertyVisibilityOverrides($overrides, $instance, $component);
        return $this->mergeComponentPropertyInstanceSwapOverrides($overrides, $instance, $component);
    }

    /**
     * Fold the instance's component-property text assignments into the override map.
     *
     * Figma stores per-instance text overrides as component properties rather than as
     * descendant node changes: the instance carries componentPropAssignments (defID ->
     * value.textValue.characters) and each master text node carries componentPropRefs
     * (defID -> componentPropNodeField: TEXT_DATA). Matching them by defID yields the
     * real per-instance characters for each consuming text node.
     *
     * @param array<string, array<string, mixed>> $overrides Existing override map keyed by node id.
     * @param array<string, mixed>                 $instance  Instance node carrying componentPropAssignments.
     * @param array<string, mixed>                 $component Component definition whose text nodes carry componentPropRefs.
     * @return array<string, array<string, mixed>>
     */
    private function mergeComponentPropertyTextOverrides(array $overrides, array $instance, array $component): array
    {
        $assignments = $this->componentPropertyTextAssignments($instance);
        if ( empty($assignments) ) {
            return $overrides;
        }

        $targets = array();
        $this->collectComponentPropertyTextTargets($component, $assignments, $targets);

        foreach ( $targets as $nodeId => $fields ) {
            foreach ( $fields as $field => $value ) {
                // Do not clobber a value an explicit override already established;
                // component-property assignments only fill content that is otherwise
                // left at the component master default.
                if ( ! isset($overrides[$nodeId][$field]) ) {
                    $overrides[$nodeId][$field] = $value;
                }
            }
        }

        return $overrides;
    }

    /**
     * Read the text-valued component property assignments from an instance.
     *
     * @param array<string, mixed> $instance
     * @return array<string, string> Map of property definition id => assigned characters.
     */
    private function componentPropertyTextAssignments(array $instance): array
    {
        $assignmentsRaw = $instance['componentPropAssignments'] ?? null;
        if ( ! is_array($assignmentsRaw) ) {
            return array();
        }

        $assignments = array();
        foreach ( $assignmentsRaw as $assignment ) {
            if ( ! is_array($assignment) ) {
                continue;
            }

            $defId = $this->readGuidId($assignment['defID'] ?? $assignment['defId'] ?? null);
            if ( null === $defId || '' === $defId ) {
                continue;
            }

            $characters = $this->readComponentPropertyAssignmentCharacters($assignment);
            if ( null === $characters ) {
                continue;
            }

            $assignments[$defId] = $characters;
        }

        return $assignments;
    }

    /**
     * @param array<string, mixed> $assignment
     */
    private function readComponentPropertyAssignmentCharacters(array $assignment): ?string
    {
        $paths = array(
            array('value', 'textValue', 'characters'),
            array('value', 'textDataValue', 'characters'),
            array('varValue', 'value', 'textDataValue', 'characters'),
            array('varValue', 'value', 'textValue', 'characters'),
        );

        foreach ( $paths as $path ) {
            $cursor = $assignment;
            foreach ( $path as $key ) {
                if ( ! is_array($cursor) || ! array_key_exists($key, $cursor) ) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$key];
            }
            if ( is_scalar($cursor) ) {
                return (string) $cursor;
            }
        }

        return null;
    }

    /**
     * Walk a component subtree and record text overrides for nodes whose TEXT_DATA
     * property reference matches an instance assignment.
     *
     * @param array<string, mixed>  $node
     * @param array<string, string> $assignments Map of property definition id => characters.
     * @param array<string, array<string, mixed>> $targets Accumulator keyed by node id.
     */
    private function collectComponentPropertyTextTargets(array $node, array $assignments, array &$targets): void
    {
        foreach ( $this->componentPropertyTextRefDefIds($node) as $defId ) {
            if ( ! isset($assignments[$defId]) ) {
                continue;
            }

            $nodeId = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
            if ( '' === $nodeId ) {
                continue;
            }

            $targets[$nodeId]['characters'] = $assignments[$defId];
            $targets[$nodeId]['text'] = $assignments[$defId];
            break;
        }

        if ( is_array($node['children'] ?? null) ) {
            foreach ( $node['children'] as $child ) {
                if ( is_array($child) ) {
                    $this->collectComponentPropertyTextTargets($child, $assignments, $targets);
                }
            }
        }
    }

    /**
     * Read the property definition ids bound to a node's TEXT_DATA field.
     *
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function componentPropertyTextRefDefIds(array $node): array
    {
        $refs = $node['componentPropRefs'] ?? $node['componentPropRef'] ?? null;
        if ( ! is_array($refs) ) {
            return array();
        }

        $defIds = array();
        foreach ( $refs as $ref ) {
            if ( ! is_array($ref) ) {
                continue;
            }

            $field = strtoupper((string) ($ref['componentPropNodeField'] ?? ''));
            if ( 'TEXT_DATA' !== $field && 'TEXT' !== $field && 'CHARACTERS' !== $field ) {
                continue;
            }

            $defId = $this->readGuidId($ref['defID'] ?? $ref['defId'] ?? null);
            if ( null !== $defId && '' !== $defId ) {
                $defIds[] = $defId;
            }
        }

        return $defIds;
    }

    /**
     * @param array<string, array<string, mixed>> $overrides Existing override map keyed by node id.
     * @param array<string, mixed>                 $instance  Instance node carrying componentPropAssignments.
     * @param array<string, mixed>                 $component Component definition whose nodes carry componentPropRefs.
     * @return array<string, array<string, mixed>>
     */
    private function mergeComponentPropertyVisibilityOverrides(array $overrides, array $instance, array $component): array
    {
        $assignments = $this->componentPropertyBooleanAssignments($instance);
        if ( empty($assignments) ) {
            return $overrides;
        }

        $targets = array();
        $this->collectComponentPropertyVisibilityTargets($component, $assignments, $targets);

        foreach ( $targets as $nodeId => $visible ) {
            if ( ! isset($overrides[$nodeId]['visible']) ) {
                $overrides[$nodeId]['visible'] = $visible;
            }
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $instance
     * @return array<string, bool> Map of property definition id => assigned visibility.
     */
    private function componentPropertyBooleanAssignments(array $instance): array
    {
        $assignmentsRaw = $instance['componentPropAssignments'] ?? null;
        if ( ! is_array($assignmentsRaw) ) {
            return array();
        }

        $assignments = array();
        foreach ( $assignmentsRaw as $assignment ) {
            if ( ! is_array($assignment) ) {
                continue;
            }

            $defId = $this->readGuidId($assignment['defID'] ?? $assignment['defId'] ?? null);
            if ( null === $defId || '' === $defId ) {
                continue;
            }

            $visible = $this->readComponentPropertyAssignmentBoolean($assignment);
            if ( null !== $visible ) {
                $assignments[$defId] = $visible;
            }
        }

        return $assignments;
    }

    /**
     * @param array<string, mixed> $assignment
     */
    private function readComponentPropertyAssignmentBoolean(array $assignment): ?bool
    {
        $paths = array(
            array('value', 'boolValue'),
            array('value', 'booleanValue'),
            array('varValue', 'value', 'boolValue'),
            array('varValue', 'value', 'booleanValue'),
        );

        foreach ( $paths as $path ) {
            $cursor = $assignment;
            foreach ( $path as $key ) {
                if ( ! is_array($cursor) || ! array_key_exists($key, $cursor) ) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$key];
            }
            if ( is_bool($cursor) ) {
                return $cursor;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, bool>  $assignments Map of property definition id => visibility.
     * @param array<string, bool>  $targets Accumulator keyed by node id.
     */
    private function collectComponentPropertyVisibilityTargets(array $node, array $assignments, array &$targets): void
    {
        foreach ( $this->componentPropertyVisibilityRefDefIds($node) as $defId ) {
            if ( ! array_key_exists($defId, $assignments) ) {
                continue;
            }

            $nodeId = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
            if ( '' !== $nodeId ) {
                $targets[$nodeId] = $assignments[$defId];
            }
            break;
        }

        if ( is_array($node['children'] ?? null) ) {
            foreach ( $node['children'] as $child ) {
                if ( is_array($child) ) {
                    $this->collectComponentPropertyVisibilityTargets($child, $assignments, $targets);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function componentPropertyVisibilityRefDefIds(array $node): array
    {
        $refs = $node['componentPropRefs'] ?? $node['componentPropRef'] ?? null;
        if ( ! is_array($refs) ) {
            return array();
        }

        $defIds = array();
        foreach ( $refs as $ref ) {
            if ( ! is_array($ref) ) {
                continue;
            }

            $field = strtoupper((string) ($ref['componentPropNodeField'] ?? ''));
            if ( 'VISIBLE' !== $field && 'VISIBILITY' !== $field ) {
                continue;
            }

            $defId = $this->readGuidId($ref['defID'] ?? $ref['defId'] ?? null);
            if ( null !== $defId && '' !== $defId ) {
                $defIds[] = $defId;
            }
        }

        return $defIds;
    }

    /**
     * @param array<string, array<string, mixed>> $overrides Existing override map keyed by node id.
     * @param array<string, mixed>                 $instance  Instance node carrying componentPropAssignments.
     * @param array<string, mixed>                 $component Component definition whose nested instances carry componentPropRefs.
     * @return array<string, array<string, mixed>>
     */
    private function mergeComponentPropertyInstanceSwapOverrides(array $overrides, array $instance, array $component): array
    {
        $assignments = $this->componentPropertyInstanceSwapAssignments($instance);
        if ( empty($assignments) ) {
            return $overrides;
        }

        $targets = array();
        $this->collectComponentPropertyInstanceSwapTargets($component, $assignments, $targets);

        foreach ( $targets as $nodeId => $componentId ) {
            if ( ! isset($overrides[$nodeId]['_figma_instance_swap_component_id']) ) {
                $overrides[$nodeId]['_figma_instance_swap_component_id'] = $componentId;
            }
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $instance
     * @return array<string, string> Map of property definition id => replacement component id.
     */
    private function componentPropertyInstanceSwapAssignments(array $instance): array
    {
        $assignmentsRaw = $instance['componentPropAssignments'] ?? null;
        if ( ! is_array($assignmentsRaw) ) {
            return array();
        }

        $assignments = array();
        foreach ( $assignmentsRaw as $assignment ) {
            if ( ! is_array($assignment) ) {
                continue;
            }

            $defId = $this->readGuidId($assignment['defID'] ?? $assignment['defId'] ?? null);
            if ( null === $defId || '' === $defId ) {
                continue;
            }

            $componentId = $this->readComponentPropertyAssignmentGuid($assignment);
            if ( null !== $componentId && '' !== $componentId ) {
                $assignments[$defId] = $componentId;
            }
        }

        return $assignments;
    }

    /**
     * @param array<string, mixed> $assignment
     */
    private function readComponentPropertyAssignmentGuid(array $assignment): ?string
    {
        $paths = array(
            array('value', 'guidValue'),
            array('value', 'symbolIdValue', 'guid'),
            array('varValue', 'value', 'guidValue'),
            array('varValue', 'value', 'symbolIdValue', 'guid'),
        );

        foreach ( $paths as $path ) {
            $cursor = $assignment;
            foreach ( $path as $key ) {
                if ( ! is_array($cursor) || ! array_key_exists($key, $cursor) ) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$key];
            }

            $componentId = $this->readGuidId($cursor);
            if ( null !== $componentId ) {
                return $componentId;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>                 $node
     * @param array<string, string>                $assignments Map of property definition id => replacement component id.
     * @param array<string, string>                $targets Accumulator keyed by node id.
     */
    private function collectComponentPropertyInstanceSwapTargets(array $node, array $assignments, array &$targets): void
    {
        foreach ( $this->componentPropertyInstanceSwapRefDefIds($node) as $defId ) {
            if ( ! isset($assignments[$defId]) ) {
                continue;
            }

            $nodeId = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
            if ( '' !== $nodeId ) {
                $targets[$nodeId] = $assignments[$defId];
            }
            break;
        }

        if ( is_array($node['children'] ?? null) ) {
            foreach ( $node['children'] as $child ) {
                if ( is_array($child) ) {
                    $this->collectComponentPropertyInstanceSwapTargets($child, $assignments, $targets);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function componentPropertyInstanceSwapRefDefIds(array $node): array
    {
        $refs = $node['componentPropRefs'] ?? $node['componentPropRef'] ?? null;
        if ( ! is_array($refs) ) {
            return array();
        }

        $defIds = array();
        foreach ( $refs as $ref ) {
            if ( ! is_array($ref) ) {
                continue;
            }

            $field = strtoupper((string) ($ref['componentPropNodeField'] ?? ''));
            if ( 'OVERRIDDEN_SYMBOL_ID' !== $field && 'INSTANCE_SWAP' !== $field ) {
                continue;
            }

            $defId = $this->readGuidId($ref['defID'] ?? $ref['defId'] ?? null);
            if ( null !== $defId && '' !== $defId ) {
                $defIds[] = $defId;
            }
        }

        return $defIds;
    }

    /**
     * @param array<string, array<string, mixed>> $overrides
     */
    private function instanceOverridesUseTransforms(array $overrides): bool
    {
        foreach ( $overrides as $override ) {
            if ( is_array($override) && $this->isTransformOverrideGeometry($override) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $override
     */
    private function isTransformOverrideGeometry(array $override): bool
    {
        return is_array($override['transform'] ?? null);
    }

    /**
     * @param array<int, mixed> $children
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, mixed>
     */
    private function resolveClonedInstanceChildren(array $children, array $nodeMap, array $components, array &$diagnostics, array $blobs = array(), array $paintStyles = array(), array $textStyles = array(), array $resolutionTrail = array()): array
    {
        foreach ( $children as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $id = (string) ($child['id'] ?? '');
            if ( 'INSTANCE' === strtoupper((string) ($child['type'] ?? '')) && '' !== $id && isset($nodeMap[$id]) ) {
                $refreshed = $nodeMap[$id];
                $reference = $this->readComponentReference($refreshed);
                if ( empty($refreshed['children']) && null !== $reference && isset($components[$reference['id']]) && ! in_array($id, $resolutionTrail, true) ) {
                    $overrides = $this->normalizeInstanceOverrides($refreshed, $id, $diagnostics);
                    if ( null !== $overrides ) {
                        $refreshed = $this->cloneComponentForInstance($components[$reference['id']], $refreshed, $reference['id'], $overrides, $nodeMap, $components, $diagnostics, $blobs, $paintStyles, $textStyles, array_merge($resolutionTrail, array($id)));
                    }
                }
                $child = $this->mergeRefreshedComponentSource($child, $refreshed, $id);
            }

            if ( is_array($child['children'] ?? null) ) {
                $child['children'] = $this->resolveClonedInstanceChildren($child['children'], $nodeMap, $components, $diagnostics, $blobs, $paintStyles, $textStyles, $resolutionTrail);
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
    private function applyInstanceOverridesToChildren(array $children, array $overrides, array $nodeMap, array $components, array &$diagnostics, array $blobs = array(), array $paintStyles = array(), array $textStyles = array()): array
    {
		foreach ( $children as $index => $child ) {
			if ( ! is_array($child) ) {
				continue;
			}

			$id = (string) ($child['id'] ?? '');
			$sourceNode = '' !== $id && is_array($nodeMap[$id] ?? null) ? $nodeMap[$id] : array();
			$sourceChildBox = is_array($sourceNode['box'] ?? null) ? $sourceNode['box'] : (is_array($child['box'] ?? null) ? $child['box'] : null);
			$hasFieldOverride = false;
            $overrideFields = $this->instanceOverrideFieldsForChild($child, $overrides);
            $swapComponentId = isset($overrideFields['_figma_instance_swap_component_id']) && is_scalar($overrideFields['_figma_instance_swap_component_id']) ? (string) $overrideFields['_figma_instance_swap_component_id'] : null;
            unset($overrideFields['_figma_instance_swap_component_id']);
            $nestedComponentPropertyOverrides = $this->nestedComponentPropertyOverridesForChild($child, $overrideFields, $components);
            unset($overrideFields['componentPropAssignments']);
            if ( null !== $swapComponentId && isset($components[$swapComponentId]) ) {
                $child = $this->mergeRefreshedComponentSource($child, $components[$swapComponentId], $swapComponentId);
                if ( is_array($child['children'] ?? null) ) {
                    $child['children'] = $this->resolveClonedInstanceChildren($child['children'], $nodeMap, $components, $diagnostics, $blobs, $paintStyles, $textStyles);
                }
                $child['_figma_instance_override_applied'] = true;
            }
            foreach ( $overrideFields as $field => $value ) {
                $hasFieldOverride = true;
                if ( is_array($value) ) {
                    $value = $this->normalizeInstanceOverridePaintField($child, $field, $value);
                }
                $child[$field] = $value;
                if ( in_array($field, array('fills', 'fillPaints'), true) ) {
                    unset($child['styleIdForFill']);
                } elseif ( in_array($field, array('strokes', 'strokePaints'), true) ) {
                    unset($child['styleIdForStrokeFill'], $child['styleIdForStroke']);
                }
                if ( in_array($field, array('characters', 'text'), true) && is_array($child['figma_text'] ?? null) ) {
                    $child['figma_text']['characters'] = (string) $value;
                }
			}
			if ( $hasFieldOverride ) {
				$child = $this->normalizeOverriddenInstanceChild($child, $id, $overrideFields, $diagnostics, $blobs, $paintStyles, $textStyles, $sourceChildBox);
			}

            if ( is_array($child['children'] ?? null) ) {
                $childOverrides = $this->descendantInstanceOverrideFieldsForChild($child, $overrides);
                $child['children'] = $this->applyInstanceOverridesToChildren($child['children'], array_merge($overrides, $childOverrides, $nestedComponentPropertyOverrides), $nodeMap, $components, $diagnostics, $blobs, $paintStyles, $textStyles);
            }

            $children[$index] = $child;
        }

        return $children;
    }

    /**
     * @param array<string, mixed> $child
     * @param array<int, mixed>    $value
     * @return array<int, mixed>
     */
    private function normalizeInstanceOverridePaintField(array $child, string $field, array $value): array
    {
        if ( ! in_array($field, array('fills', 'fillPaints', 'strokes', 'strokePaints'), true) ) {
            return $value;
        }

        $sourceFields = in_array($field, array('fills', 'fillPaints'), true)
            ? array('fillPaints', 'fills')
            : array('strokePaints', 'strokes');
        $sourcePaints = array();
        foreach ( $sourceFields as $sourceField ) {
            if ( is_array($child[$sourceField] ?? null) ) {
                $sourcePaints = $child[$sourceField];
                break;
            }
        }

        return $this->paintNormalizer->removeSourceImagePaintsFromOverrideList($value, $sourcePaints);
    }

    /**
     * @param array<string, mixed>                $child
     * @param array<string, mixed>                $overrideFields
     * @param array<string, array<string, mixed>> $components
     * @return array<string, array<string, mixed>>
     */
    private function nestedComponentPropertyOverridesForChild(array $child, array $overrideFields, array $components): array
    {
        if ( ! is_array($overrideFields['componentPropAssignments'] ?? null) || 'INSTANCE' !== strtoupper((string) ($child['type'] ?? '')) ) {
            return array();
        }

        $reference = $this->readComponentReference($child);
        $componentId = null !== $reference ? $reference['id'] : null;
        if ( null === $componentId && isset($child['figma_component']['component_id']) && is_scalar($child['figma_component']['component_id']) ) {
            $componentId = (string) $child['figma_component']['component_id'];
        }
        if ( null === $componentId || ! isset($components[$componentId]) ) {
            return array();
        }

        $instance = $child;
        $instance['componentPropAssignments'] = $overrideFields['componentPropAssignments'];

        return $this->mergeComponentPropertyOverrides(array(), $instance, $components[$componentId]);
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
     * Carry parent-scoped guidPath overrides into resolved nested instances.
     *
     * Figma can encode an override for a nested component child as
     * `nested-instance-guid/child-guid`. Once recursion enters the nested
     * instance, its descendants match the suffix (`child-guid`), not the full
     * parent path.
     *
     * @param array<string, mixed> $child
     * @param array<string, array<string, mixed>> $overrides
     * @return array<string, array<string, mixed>>
     */
    private function descendantInstanceOverrideFieldsForChild(array $child, array $overrides): array
    {
        $scoped = array();
        foreach ( $this->instanceChildOverrideAliases($child) as $alias ) {
            foreach ( $overrides as $target => $overrideFields ) {
                if ( ! is_string($target) || ! is_array($overrideFields) || ! str_starts_with($target, $alias . '/') ) {
                    continue;
                }

                $descendantTarget = substr($target, strlen($alias) + 1);
                if ( '' !== $descendantTarget ) {
                    $scoped[$descendantTarget] = array_merge($scoped[$descendantTarget] ?? array(), $overrideFields);
                }
            }
        }

        return $scoped;
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
                $id = (string) $child[$key];
                $aliases[] = $id;
                if ( str_contains($id, '/') ) {
                    $parts = explode('/', $id);
                    $aliases[] = (string) end($parts);
                }
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
	private function normalizeOverriddenInstanceChild(array $child, string $id, array $overrideFields, array &$diagnostics, array $blobs = array(), array $paintStyles = array(), array $textStyles = array(), ?array $sourceChildBox = null): array
	{
		$hasVectorGeometryOverride = array_key_exists('fillGeometry', $overrideFields) || array_key_exists('strokeGeometry', $overrideFields);
		$hasExplicitSizeOverride = array_key_exists('size', $overrideFields);
		$hasTransformGeometryOverride = is_array($overrideFields['transform'] ?? null) || is_array($overrideFields['absoluteTransform'] ?? null) || is_array($overrideFields['relativeTransform'] ?? null);
        if ( is_array($child['size'] ?? null) ) {
            foreach ( array('x' => 'width', 'y' => 'height') as $source => $target ) {
                if ( isset($child['size'][$source]) && is_numeric($child['size'][$source]) ) {
                    $child[$target] = (float) $child['size'][$source];
                }
            }
        }
        if ( is_array($child['relativeTransform'] ?? null) ) {
            foreach ( array('m02' => 'x', 'm12' => 'y') as $source => $target ) {
                if ( isset($child['relativeTransform'][$source]) && is_numeric($child['relativeTransform'][$source]) ) {
                    $child[$target] = (float) $child['relativeTransform'][$source];
                    $child[GeometryBox::PROVENANCE_KEY] = GeometryBox::SOURCE_OVERRIDE_TRANSFORM;
                }
            }
        }
        if ( is_array($child['absoluteTransform'] ?? null) ) {
            $absoluteBounds = is_array($child['absoluteBoundingBox'] ?? null) ? $child['absoluteBoundingBox'] : array();
            foreach ( array('m02' => 'x', 'm12' => 'y') as $source => $target ) {
                if ( isset($child['absoluteTransform'][$source]) && is_numeric($child['absoluteTransform'][$source]) ) {
                    $child[$target] = (float) $child['absoluteTransform'][$source];
                    $child[GeometryBox::PROVENANCE_KEY] = GeometryBox::SOURCE_ABSOLUTE_TRANSFORM;
                    $absoluteBounds[$target] = (float) $child['absoluteTransform'][$source];
                }
            }
            if ( ! empty($absoluteBounds) ) {
                $child['absoluteBoundingBox'] = $absoluteBounds;
            }
        }
        if ( is_array($child['transform'] ?? null) ) {
            // m02 and m12 carry canvas-global (absolute) coordinates, not parent-local
            // coordinates. Stamp them into absoluteBoundingBox so that normalizeLayoutBox
            // labels the resulting box coordinate_space='absolute'. Without this, the bare
            // x/y values written below are picked up by the local-coordinate fallback path
            // and mislabeled coordinate_space='local', causing positionOffset() to emit the
            // raw canvas value verbatim (e.g. 13842 px) instead of subtracting the
            // containing-block origin.
            $absoluteBounds = is_array($child['absoluteBoundingBox'] ?? null) ? $child['absoluteBoundingBox'] : array();
            foreach ( array('m02' => 'x', 'm12' => 'y') as $source => $target ) {
                if ( isset($child['transform'][$source]) && is_numeric($child['transform'][$source]) ) {
                    $child[$target] = (float) $child['transform'][$source];
                    $child[GeometryBox::PROVENANCE_KEY] = GeometryBox::SOURCE_ABSOLUTE_TRANSFORM;
                    // Only backfill absoluteBoundingBox where the dimension is not already
                    // present — preserve any richer absolute bounds the payload already has.
                    if ( ! isset($absoluteBounds[$target]) ) {
                        $absoluteBounds[$target] = (float) $child['transform'][$source];
                    }
                }
            }
            if ( ! empty($absoluteBounds) ) {
                $child['absoluteBoundingBox'] = $absoluteBounds;
            }
        }

        foreach ( array('figma_text', 'figma_paints', 'figma_vector_paths', 'figma_box', 'box', 'layout', 'figma_effects') as $key ) {
            unset($child[$key]);
        }
        unset($child['figma_vector_scale']);

		$child = $this->normalizeNode($child, $diagnostics, $blobs, $paintStyles, $textStyles);
		if ( $hasTransformGeometryOverride && is_array($sourceChildBox) ) {
			$child = $this->preserveLocalSourceBoxForFarAbsoluteOverride($child, $sourceChildBox);
		}
		$child['_figma_instance_override_applied'] = true;
        unset($child[GeometryBox::PROVENANCE_KEY]);
        if ( $hasVectorGeometryOverride && ! $hasExplicitSizeOverride ) {
            $bounds = $this->vectorGeometryNormalizer->normalizedVectorPathBounds(is_array($child['figma_vector_paths'] ?? null) ? $child['figma_vector_paths'] : array());
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
	 * @param array<string, mixed> $child
	 * @param array<string, mixed> $sourceChildBox
	 * @return array<string, mixed>
	 */
	private function preserveLocalSourceBoxForFarAbsoluteOverride(array $child, array $sourceChildBox): array
	{
		if ( GeometryBox::COORDINATE_SPACE_PARENT_LOCAL !== GeometryBox::coordinateSpace($sourceChildBox) || ! is_array($child['box'] ?? null) ) {
			return $child;
		}

		$box = $child['box'];
		foreach ( array('x', 'y') as $dimension ) {
			if ( ! isset($sourceChildBox[$dimension], $box[$dimension]) || ! is_numeric($sourceChildBox[$dimension]) || ! is_numeric($box[$dimension]) ) {
				continue;
			}

			if ( abs((float) $box[$dimension] - (float) $sourceChildBox[$dimension]) >= 1000.0 ) {
				$child[$dimension] = (float) $sourceChildBox[$dimension];
				$child['box'][$dimension] = (float) $sourceChildBox[$dimension];
				if ( is_array($child['figma_box'] ?? null) && isset($child['figma_box'][$dimension]) && is_numeric($child['figma_box'][$dimension]) ) {
					$child['figma_box'][$dimension] = (float) $sourceChildBox[$dimension];
				}
			}
		}

		$child['box']['coordinate_space'] = GeometryBox::COORDINATE_SPACE_PARENT_LOCAL;
		if ( is_array($child['figma_box'] ?? null) ) {
			$child['figma_box']['coordinate_space'] = GeometryBox::COORDINATE_SPACE_PARENT_LOCAL;
		}

		return $child;
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

            $segmentLink = $this->textNormalizer->normalizeSegmentHyperlink($node);
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
        // The Kiwi `Hyperlink` struct has no `type` field and stores the node
        // target as a `guid` GUID struct, so bridge `guid` onto the REST-shaped
        // `nodeID` resolution (#328).
        $nodeId = $this->readString($hyperlink, array('nodeID', 'nodeId', 'node_id'))
            ?? $this->readGuidId($hyperlink['nodeID'] ?? ($hyperlink['nodeId'] ?? ($hyperlink['guid'] ?? null)));

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
    private function normalizeReactionLink(array $node): ?array
    {
        // `reactions` is the REST name; `prototypeInteractions` is the Kiwi name
        // for the same prototype-interaction list decoded from `.fig` (#328).
        $interactions = is_array($node['reactions'] ?? null) ? $node['reactions'] : array();
        if ( is_array($node['prototypeInteractions'] ?? null) ) {
            $interactions = array_merge($interactions, $node['prototypeInteractions']);
        }

        foreach ( $interactions as $reaction ) {
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
        // REST uses `type`/`url`/`destinationId`/`navigation`; the Kiwi
        // `PrototypeAction` uses `connectionType` (URL/INTERNAL_NODE),
        // `connectionURL`, the `transitionNodeID` GUID, and `navigationType`.
        // Read both so the link path works from `.fig` and REST inputs (#328).
        $type = strtoupper((string) ($action['type'] ?? ''));
        $connectionType = strtoupper((string) ($action['connectionType'] ?? ''));
        $url = $this->readString($action, array('url', 'href', 'connectionURL'));
        if ( ( 'URL' === $type || 'URL' === $connectionType ) && null !== $url ) {
            return array('type' => 'url', 'url' => $url);
        }

        $destination = $this->readString($action, array('destinationId', 'navigationDestinationId', 'transitionNodeID'))
            ?? $this->readGuidId($action['destinationId'] ?? ($action['transitionNodeID'] ?? null));
        $navigation = strtoupper((string) ($action['navigation'] ?? ($action['navigationType'] ?? '')));
        $navigatesToNode = 'NODE' === $type
            || 'INTERNAL_NODE' === $connectionType
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
        return $this->paintNormalizer->normalizePaintCollections($node, $nodeId, $diagnostics, $paintStyles);
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

        if ( isset($node['blendMode']) && is_scalar($node['blendMode']) ) {
            $box['blend_mode'] = strtoupper((string) $node['blendMode']);
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

        $uniformRadius = null;
        if ( isset($node['cornerRadius']) && is_numeric($node['cornerRadius']) ) {
            $uniformRadius = (float) $node['cornerRadius'];
        }

        // Per-corner radii arrive under REST API names (`topLeftRadius`) from
        // remote scenegraphs and under Kiwi names (`rectangleTopLeftCornerRadius`)
        // from decoded `.fig` archives. Read the REST name first and fall back to
        // the Kiwi name so mixed-radius nodes survive both ingestion paths.
        $perCorner = array();
        foreach ( array(
            'top_left_radius'     => array('topLeftRadius', 'rectangleTopLeftCornerRadius'),
            'top_right_radius'    => array('topRightRadius', 'rectangleTopRightCornerRadius'),
            'bottom_right_radius' => array('bottomRightRadius', 'rectangleBottomRightCornerRadius'),
            'bottom_left_radius'  => array('bottomLeftRadius', 'rectangleBottomLeftCornerRadius'),
        ) as $targetKey => $sourceKeys ) {
            foreach ( $sourceKeys as $sourceKey ) {
                if ( isset($node[$sourceKey]) && is_numeric($node[$sourceKey]) ) {
                    $perCorner[$targetKey] = (float) $node[$sourceKey];
                    break;
                }
            }
        }

        if ( ! empty($perCorner) ) {
            // Per-corner values override the uniform radius when present. Fill any
            // corner the source left unset from the uniform radius so partial
            // per-corner data still describes the full shape.
            foreach ( array('top_left_radius', 'top_right_radius', 'bottom_right_radius', 'bottom_left_radius') as $targetKey ) {
                if ( ! isset($perCorner[$targetKey]) && null !== $uniformRadius ) {
                    $perCorner[$targetKey] = $uniformRadius;
                }
                if ( isset($perCorner[$targetKey]) ) {
                    $box[$targetKey] = $perCorner[$targetKey];
                }
            }
        } elseif ( null !== $uniformRadius ) {
            $box['corner_radius'] = $uniformRadius;
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

            // The decoded Kiwi enum names layer blur `FOREGROUND_BLUR`; the REST
            // shape calls the same effect `LAYER_BLUR`. Bridge both onto the
            // emitter's `layer_blur` (→ `filter:blur()`) branch (#328).
            if ( in_array($type, array('LAYER_BLUR', 'FOREGROUND_BLUR', 'BACKGROUND_BLUR'), true) ) {
                $effects[] = array(
                    'type' => 'BACKGROUND_BLUR' === $type ? 'background_blur' : 'layer_blur',
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
        $sourceKind = null;

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
                $sourceKind = GeometryBox::SOURCE_ABSOLUTE_BOUNDS;
            }
        }

        foreach ( array('x', 'y', 'width', 'height') as $dimension ) {
            if ( ! array_key_exists($dimension, $box) && isset($node[$dimension]) && is_numeric($node[$dimension]) ) {
                $box[$dimension] = (float) $node[$dimension];
                if ( 'x' === $dimension || 'y' === $dimension ) {
                    $sourceKind = isset($node[GeometryBox::PROVENANCE_KEY]) && is_scalar($node[GeometryBox::PROVENANCE_KEY])
                        ? (string) $node[GeometryBox::PROVENANCE_KEY]
                        : GeometryBox::SOURCE_EXPLICIT_LOCAL;
                }
            }
        }

        if ( is_array($node['size'] ?? null) ) {
            foreach ( array('x' => 'width', 'y' => 'height') as $source => $target ) {
                if ( ! array_key_exists($target, $box) && isset($node['size'][$source]) && is_numeric($node['size'][$source]) ) {
                    $box[$target] = (float) $node['size'][$source];
                    $sourceKind ??= GeometryBox::SOURCE_SIZE_ONLY;
                }
            }
        }

        $transformBox = $this->layoutBoxFromTransform($node);
        foreach ( array('x', 'y') as $dimension ) {
            if ( ! array_key_exists($dimension, $box) && isset($transformBox[$dimension]) ) {
                $box[$dimension] = $transformBox[$dimension];
                $sourceKind = $transformBox[GeometryBox::PROVENANCE_KEY];
            }
        }

        if ( null !== $sourceKind ) {
            $box = GeometryBox::withProvenance($box, $sourceKind);
        }

        return GeometryBox::withoutProvenance($box);
    }

    /**
     * @param array<string, mixed> $node
     * @return array{x?: float, y?: float, _geometry_provenance?: string}
     */
    private function layoutBoxFromTransform(array $node): array
    {
        foreach ( array(
            'relativeTransform' => GeometryBox::SOURCE_TRANSFORM,
            'transform'         => GeometryBox::SOURCE_TRANSFORM,
            'absoluteTransform' => GeometryBox::SOURCE_ABSOLUTE_TRANSFORM,
        ) as $sourceKey => $sourceKind ) {
            if ( ! is_array($node[$sourceKey] ?? null) ) {
                continue;
            }

            $box = array(GeometryBox::PROVENANCE_KEY => $sourceKind);
            foreach ( array('m02' => 'x', 'm12' => 'y') as $source => $target ) {
                if ( isset($node[$sourceKey][$source]) && is_numeric($node[$sourceKey][$source]) ) {
                    $box[$target] = (float) $node[$sourceKey][$source];
                }
            }

            if ( isset($box['x']) || isset($box['y']) ) {
                return $box;
            }
        }

        return array();
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

        // REST exposes `primaryAxisSizingMode`/`counterAxisSizingMode`; the .fig
        // Kiwi schema carries the same intent as flat `stackPrimarySizing`/
        // `stackCounterSizing` enums. Read REST first, fall back to Kiwi, and
        // normalize both vocabularies to canonical HUG/FIXED tokens.
        foreach ( array(
            'primary_axis_sizing' => array('rest' => 'primaryAxisSizingMode', 'kiwi' => 'stackPrimarySizing'),
            'counter_axis_sizing' => array('rest' => 'counterAxisSizingMode', 'kiwi' => 'stackCounterSizing'),
        ) as $target => $sources ) {
            $raw = null;
            if ( isset($node[$sources['rest']]) && is_scalar($node[$sources['rest']]) ) {
                $raw = (string) $node[$sources['rest']];
            } elseif ( isset($node[$sources['kiwi']]) && is_scalar($node[$sources['kiwi']]) ) {
                $raw = (string) $node[$sources['kiwi']];
            }
            if ( null !== $raw ) {
                $layout[$target] = $this->normalizeAxisSizingValue($raw);
            }
        }

        // Bridge axis sizing onto the horizontal/vertical sizing fields the HTML
        // emitter consumes, mapping primary/counter to physical axes by stack
        // orientation. .fig input never sets `layoutSizingHorizontal`, so without
        // this bridge HUG/FIXED intent from the Kiwi stack enums is pure data loss.
        $flexDirection = $layout['flex_direction'] ?? null;
        if ( 'row' === $flexDirection || 'column' === $flexDirection ) {
            $primaryAxisKey = 'row' === $flexDirection ? 'sizing_horizontal' : 'sizing_vertical';
            $counterAxisKey = 'row' === $flexDirection ? 'sizing_vertical' : 'sizing_horizontal';
            if ( isset($layout['primary_axis_sizing']) && ! isset($layout[$primaryAxisKey]) ) {
                $layout[$primaryAxisKey] = $layout['primary_axis_sizing'];
            }
            if ( isset($layout['counter_axis_sizing']) && ! isset($layout[$counterAxisKey]) ) {
                $layout[$counterAxisKey] = $layout['counter_axis_sizing'];
            }
        }

        // Figma `textAutoResize` (TEXT auto-resize behaviour) governs whether a
        // text box hugs its content. WIDTH_AND_HEIGHT hugs both axes, HEIGHT keeps
        // a fixed width while the height hugs content, NONE is a fixed box, and
        // TRUNCATE is a fixed box that clips overflow. It is decoded by the Kiwi
        // parser but was never read, so the intent was dropped for .fig input.
        // Bridge it onto the HUG/FIXED sizing fields and clip flag the emitter
        // already consumes rather than inventing a parallel sizing channel.
        if ( isset($node['textAutoResize']) && is_scalar($node['textAutoResize']) && '' !== (string) $node['textAutoResize'] ) {
            $autoResize = strtoupper((string) $node['textAutoResize']);
            $layout['text_auto_resize'] = $autoResize;
            [$autoResizeHorizontal, $autoResizeVertical] = match ( $autoResize ) {
                'WIDTH_AND_HEIGHT' => array('HUG', 'HUG'),
                'HEIGHT'           => array(null, 'HUG'),
                default            => array(null, null),
            };
            if ( null !== $autoResizeHorizontal && ! isset($layout['sizing_horizontal']) ) {
                $layout['sizing_horizontal'] = $autoResizeHorizontal;
            }
            if ( null !== $autoResizeVertical && ! isset($layout['sizing_vertical']) ) {
                $layout['sizing_vertical'] = $autoResizeVertical;
            }
            if ( 'TRUNCATE' === $autoResize ) {
                $layout['clips_content'] = true;
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

        // REST `counterAxisSpacing`; Kiwi `stackCounterSpacing`. The cross-axis gap
        // between wrapped rows/columns in a wrapping Auto Layout, distinct from the
        // main-axis `item_spacing`. Decoded by the Kiwi parser but never read, so
        // the wrap gap was dropped for .fig input. The emitter folds it into the
        // two-value CSS `gap` shorthand when it differs from the main spacing.
        if ( isset($node['counterAxisSpacing']) && is_numeric($node['counterAxisSpacing']) ) {
            $layout['counter_axis_spacing'] = (float) $node['counterAxisSpacing'];
        } elseif ( isset($node['stackCounterSpacing']) && is_numeric($node['stackCounterSpacing']) ) {
            $layout['counter_axis_spacing'] = (float) $node['stackCounterSpacing'];
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

        if ( true === ($node['stackReverseZIndex'] ?? false) || 'true' === strtolower((string) ($node['stackReverseZIndex'] ?? '')) || 1 === ($node['stackReverseZIndex'] ?? null) || '1' === (string) ($node['stackReverseZIndex'] ?? '') ) {
            $layout['reverse_z_index'] = true;
        }

        // REST `layoutPositioning`; Kiwi `stackPositioning`. Both encode the
        // absolute-in-auto-layout escape with an `ABSOLUTE` enum token.
        $positioning = null;
        if ( isset($node['layoutPositioning']) && is_scalar($node['layoutPositioning']) ) {
            $positioning = strtoupper((string) $node['layoutPositioning']);
        } elseif ( isset($node['stackPositioning']) && is_scalar($node['stackPositioning']) ) {
            $positioning = strtoupper((string) $node['stackPositioning']);
        }
        if ( 'ABSOLUTE' === $positioning ) {
            $layout['positioning'] = 'absolute';
        }

        // REST `layoutGrow`; Kiwi `stackChildPrimaryGrow`. Flex-grow factor.
        if ( isset($node['layoutGrow']) && is_numeric($node['layoutGrow']) ) {
            $layout['grow'] = (float) $node['layoutGrow'];
        } elseif ( isset($node['stackChildPrimaryGrow']) && is_numeric($node['stackChildPrimaryGrow']) ) {
            $layout['grow'] = (float) $node['stackChildPrimaryGrow'];
        }

        if ( isset($node['layoutAlign']) && is_scalar($node['layoutAlign']) ) {
            $layout['align'] = strtoupper((string) $node['layoutAlign']);
        } elseif ( isset($node['stackChildAlignSelf']) && is_scalar($node['stackChildAlignSelf']) ) {
            $layout['align'] = strtoupper((string) $node['stackChildAlignSelf']);
        }

        if ( true === ($node['clipsContent'] ?? false) || true === ($node['isClip'] ?? false) || false === ($node['frameMaskDisabled'] ?? null) ) {
            $layout['clips_content'] = true;
        }

        // REST exposes a nested `constraints` object with LEFT/RIGHT/LEFT_RIGHT/
        // CENTER/SCALE (and TOP/BOTTOM/TOP_BOTTOM) tokens. The .fig Kiwi schema
        // instead carries flat `horizontalConstraint`/`verticalConstraint` enums
        // whose token vocabulary is MIN/CENTER/MAX/STRETCH/SCALE. Read the REST
        // shape first, then fall back to the Kiwi scalars, translating the Kiwi
        // enum onto the REST vocabulary so the emitter sees a single language.
        $constraints = array();
        if ( is_array($node['constraints'] ?? null) ) {
            foreach ( array('horizontal', 'vertical') as $axis ) {
                if ( isset($node['constraints'][$axis]) && is_scalar($node['constraints'][$axis]) ) {
                    $constraints[$axis] = strtoupper((string) $node['constraints'][$axis]);
                }
            }
        }
        foreach ( array('horizontal' => 'horizontalConstraint', 'vertical' => 'verticalConstraint') as $axis => $kiwiKey ) {
            if ( isset($constraints[$axis]) || ! isset($node[$kiwiKey]) || ! is_scalar($node[$kiwiKey]) ) {
                continue;
            }
            $translated = $this->normalizeKiwiConstraint(strtoupper((string) $node[$kiwiKey]), $axis);
            if ( null !== $translated ) {
                $constraints[$axis] = $translated;
            }
        }
        if ( ! empty($constraints) ) {
            $layout['constraints'] = $constraints;
        }

        // Auto Layout min/max width/height. Kiwi decodes `minSize`/`maxSize` as
        // OptionalVector {x, y} objects (x = width, y = height) but nothing ever
        // referenced them, so they were decoded-and-dropped for .fig input.
        foreach ( array('minSize' => 'min', 'maxSize' => 'max') as $source => $prefix ) {
            if ( ! is_array($node[$source] ?? null) ) {
                continue;
            }
            foreach ( array('x' => 'width', 'y' => 'height') as $axis => $dimension ) {
                if ( ! isset($node[$source][$axis]) || ! is_numeric($node[$source][$axis]) ) {
                    continue;
                }
                $value = (float) $node[$source][$axis];
                if ( is_finite($value) && $value >= 0.0 ) {
                    $layout[$prefix . '_' . $dimension] = $value;
                }
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

    /**
     * Normalize REST `*AxisSizingMode` and Kiwi `stack*Sizing` enum tokens onto a
     * single HUG/FILL/FIXED vocabulary the HTML emitter understands. REST uses
     * FIXED/AUTO, the .fig Kiwi `StackSize` enum uses FIXED/RESIZE_TO_FIT
     * (RESIZE_TO_FIT == HUG / resize-to-fit-content).
     */
    private function normalizeAxisSizingValue(string $value): string
    {
        return match ( strtoupper($value) ) {
            'AUTO', 'HUG', 'RESIZE_TO_FIT', 'RESIZE_TO_FIT_WITH_IMPLICIT_SIZE' => 'HUG',
            'FILL', 'STRETCH' => 'FILL',
            default => 'FIXED',
        };
    }

    /**
     * Translate a Kiwi `horizontalConstraint`/`verticalConstraint` enum token onto
     * the REST `constraints` vocabulary the emitter pins against. The Kiwi
     * `ConstraintType` enum is MIN/CENTER/MAX/STRETCH/SCALE; STRETCH is the
     * both-side pin (REST LEFT_RIGHT/TOP_BOTTOM), MIN is the near edge (LEFT/TOP),
     * MAX is the far edge (RIGHT/BOTTOM).
     */
    private function normalizeKiwiConstraint(string $value, string $axis): ?string
    {
        $isHorizontal = 'horizontal' === $axis;

        return match ( strtoupper($value) ) {
            'MIN' => $isHorizontal ? 'LEFT' : 'TOP',
            'MAX' => $isHorizontal ? 'RIGHT' : 'BOTTOM',
            'STRETCH' => $isHorizontal ? 'LEFT_RIGHT' : 'TOP_BOTTOM',
            'CENTER' => 'CENTER',
            'SCALE' => 'SCALE',
            default => null,
        };
    }

    private function cssAxisAlignment(string $alignment): ?string
    {
        return match ( strtoupper($alignment) ) {
            'MIN' => 'flex-start',
            'CENTER' => 'center',
            'MAX' => 'flex-end',
            'SPACE_BETWEEN' => 'space-between',
            'SPACE_EVENLY' => 'space-between',
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
