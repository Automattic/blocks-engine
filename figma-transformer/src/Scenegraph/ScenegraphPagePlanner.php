<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Builds deterministic page extraction plans from decoded Figma scenegraphs.
 */
final class ScenegraphPagePlanner
{
    public function __construct(
        private readonly ScenegraphIndex $index = new ScenegraphIndex(),
        private readonly ScenegraphFrameInspector $frameInspector = new ScenegraphFrameInspector()
    ) {
    }

    /**
     * Build deterministic page plans, collapsing responsive sibling-groups.
     *
     * When {@see ScenegraphFrameInspector} reports that several FRAME nodes
     * represent the same page at different widths (a responsive sibling-group),
     * those frames collapse into a SINGLE page plan that carries an ordered list
     * of breakpoint variants instead of emitting one page per frame. Frames with
     * no detected siblings still produce one single-variant page each, so the
     * top-level page fields stay backward compatible. If the inspector cannot
     * determine a group, the planner falls back to one-page-per-frame.
     *
     * Each entry in the returned `pages` list has this shape (the contract the
     * downstream `@media`-aware emitter consumes):
     *
     *     array(
     *         // Primary (widest/desktop) variant drives these page-level fields.
     *         'frame_id'              => string,   // primary variant frame id
     *         'name'                  => string,
     *         'slug'                  => string,   // deduped, derived from primary
     *         'path'                  => string,   // index.html for the entrypoint
     *         'entrypoint'            => bool,
     *         'figma_page_id'         => string|null,
     *         'figma_page_name'       => string|null,
     *         'section_id'            => string|null,
     *         'section_name'          => string|null,
     *         'width'                 => float|null, // primary variant width
     *         'height'                => float|null, // primary variant height
     *         'node_count'            => int,
     *         'text_count'            => int,
     *         'asset_reference_count' => int,
     *         // Responsive grouping contract.
     *         'responsive'            => bool, // true when more than one variant
     *         'breakpoint_count'      => int,  // count($variants)
     *         'variants'              => array<int, array{
     *             frame_id: string,
     *             name: string,
     *             slug: string,           // identity for the variant frame
     *             device_hint: string,    // desktop|tablet|mobile|unknown
     *             viewport_width: float|null,
     *             viewport_height: float|null,
     *             primary: bool,          // true for the widest/desktop variant
     *             order: int,             // 0-based, widest first
     *         }>,
     *         'diagnostics'           => array<int, array<string, mixed>>,
     *     )
     *
     * Variants are ordered widest-first (desktop, tablet, mobile, unknown), so
     * `variants[0]` is always the primary that drives the page slug/identity.
     *
     * @param array<string, mixed> $source Decoded Figma scenegraph source array.
     * @param array<string, mixed> $options Page planning options.
     * @return array<string, mixed>
     */
    public function plan(array $source, array $options = array()): array
    {
        $index = $this->index->build($source);
        $nodes = is_array($index['nodes'] ?? null) ? $index['nodes'] : array();
        $childrenIndex = is_array($index['children_index'] ?? null) ? $index['children_index'] : array();
        $parentIndex = is_array($index['parent_index'] ?? null) ? $index['parent_index'] : array();
        $diagnostics = is_array($index['diagnostics'] ?? null) ? $index['diagnostics'] : array();
        $statsMemo = array();
        $explicitFrameIds = $this->explicitFrameIds($options);
        $includeAllPages = true === ($options['include_all_pages'] ?? false) || ! empty($options['frame_ids']);
        $maxPages = isset($options['max_pages']) && is_numeric($options['max_pages']) ? max(1, (int) $options['max_pages']) : null;
        $entryFrameId = isset($options['entry_frame_id']) && is_scalar($options['entry_frame_id']) ? (string) $options['entry_frame_id'] : null;
        $slugMap = is_array($options['frame_slug_map'] ?? null) ? $options['frame_slug_map'] : array();
        $candidates = array();

        foreach ( $nodes as $id => $node ) {
            if ( ! is_string($id) || ! is_array($node) || 'FRAME' !== strtoupper((string) ($node['type'] ?? '')) ) {
                continue;
            }

            $stats = $this->subtreeStats($id, $nodes, $childrenIndex, $statsMemo);
            $dimensions = $this->dimensions($node);
            $candidates[$id] = array(
                'id'         => $id,
                'node'       => $node,
                'stats'      => $stats,
                'dimensions' => $dimensions,
                'score'      => $this->scoreCandidate($id, $node, $dimensions, $stats, $nodes, $parentIndex),
            );
        }

        $selectedIds = array();
        $explicitSelected = false;
        if ( ! empty($explicitFrameIds) ) {
            $explicitSelected = true;
            foreach ( $explicitFrameIds as $id ) {
                if ( isset($candidates[$id]) ) {
                    $selectedIds[] = $id;
                    continue;
                }

                $diagnostics[] = array(
                    'severity' => 'warning',
                    'code'     => 'figma_page_plan_frame_missing',
                    'message'  => 'Skipped a requested frame because it was not found as a FRAME node.',
                    'frame_id' => $id,
                );
            }
        } else {
            $selectedIds = $this->rankedCandidateIds($candidates);
            if ( ! $includeAllPages ) {
                $selectedIds = array_slice($selectedIds, 0, 1);
            }
        }

        if ( null !== $maxPages ) {
            $selectedIds = array_slice($selectedIds, 0, $maxPages);
        }

        if ( null !== $entryFrameId && ! in_array($entryFrameId, $selectedIds, true) && isset($candidates[$entryFrameId]) ) {
            array_unshift($selectedIds, $entryFrameId);
            $selectedIds = array_values(array_unique($selectedIds));
            if ( null !== $maxPages ) {
                $selectedIds = array_slice($selectedIds, 0, $maxPages);
            }
        }

        $detectionById = $this->detectionById($source, count($nodes));
        $responsiveGroups = $this->responsiveGroups($candidates, $detectionById);

        $slugs = array();
        $pages = array();
        $consumed = array();
        $emittedPosition = 0;
        foreach ( $selectedIds as $id ) {
            if ( isset($consumed[$id]) ) {
                continue;
            }

            $members = $responsiveGroups[$id] ?? array($id);
            foreach ( $members as $memberId ) {
                $consumed[$memberId] = true;
            }

            $primaryId = $members[0];
            $candidate = $candidates[$primaryId];
            $node = $candidate['node'];
            $page = $this->nearestAncestor($primaryId, array('CANVAS'), $nodes, $parentIndex);
            $section = $this->nearestAncestor($primaryId, array('SECTION'), $nodes, $parentIndex);
            $name = (string) ($node['name'] ?? $primaryId);
            $slug = $this->dedupeSlug($this->configuredSlug($primaryId, $slugMap) ?? $this->slugify($name), $slugs);
            $entrypoint = null !== $entryFrameId ? in_array($entryFrameId, $members, true) : 0 === $emittedPosition;
            $variants = $this->breakpointVariants($members, $primaryId, $candidates, $detectionById);

            $pages[] = array(
                'frame_id'              => $primaryId,
                'name'                  => $name,
                'slug'                  => $slug,
                'path'                  => $entrypoint ? 'index.html' : $slug . '.html',
                'entrypoint'            => $entrypoint,
                'figma_page_id'         => $page['id'] ?? null,
                'figma_page_name'       => $page['name'] ?? null,
                'section_id'            => $section['id'] ?? null,
                'section_name'          => $section['name'] ?? null,
                'width'                 => $candidate['dimensions']['width'],
                'height'                => $candidate['dimensions']['height'],
                'node_count'            => $candidate['stats']['nodes'],
                'text_count'            => $candidate['stats']['texts'],
                'asset_reference_count' => $candidate['stats']['assets'],
                'responsive'            => count($members) > 1,
                'breakpoint_count'      => count($members),
                'variants'              => $variants,
                'diagnostics'           => $this->pageDiagnostics($primaryId, $node, $candidate['dimensions'], $explicitSelected),
            );
            ++$emittedPosition;
        }

        return array(
            'schema'          => 'blocks-engine/figma-transformer/page-plan/v1',
            'page_count'      => count($pages),
            'candidate_count' => count($candidates),
            'pages'           => $pages,
            'diagnostics'     => $diagnostics,
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    private function explicitFrameIds(array $options): array
    {
        $ids = array();
        if ( isset($options['frame_id']) && is_scalar($options['frame_id']) ) {
            $ids[] = (string) $options['frame_id'];
        }

        foreach ( is_array($options['frame_ids'] ?? null) ? $options['frame_ids'] : array() as $id ) {
            if ( is_scalar($id) ) {
                $ids[] = (string) $id;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => '' !== $id)));
    }

    /**
     * Consume the frame inspector's detection report keyed by frame id.
     *
     * Detection (device_hint / sibling_group_key / responsive_siblings) lives in
     * {@see ScenegraphFrameInspector}; the planner reuses it rather than
     * re-deriving responsive relationships. The inspection limit is widened so
     * every candidate's detection survives slicing.
     *
     * @return array<string, array<string, mixed>>
     */
    private function detectionById(array $source, int $nodeCount): array
    {
        $inspection = $this->frameInspector->inspect($source, array('frame_inspection_limit' => max(1, $nodeCount)));
        $candidates = is_array($inspection['candidates'] ?? null) ? $inspection['candidates'] : array();
        $detection = array();
        foreach ( $candidates as $candidate ) {
            if ( ! is_array($candidate) ) {
                continue;
            }
            $id = isset($candidate['id']) && is_scalar($candidate['id']) ? (string) $candidate['id'] : '';
            if ( '' !== $id ) {
                $detection[$id] = $candidate;
            }
        }

        return $detection;
    }

    /**
     * Cluster FRAME candidates into responsive sibling-groups.
     *
     * Builds connected components over the inspector's `responsive_siblings`
     * edges (restricted to FRAME ids the planner tracks). Returns a map from
     * every member frame id to its full, ordered variant list so the page loop
     * can look up the group from whichever member was selected first.
     *
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, array<string, mixed>> $detectionById
     * @return array<string, array<int, string>>
     */
    private function responsiveGroups(array $candidates, array $detectionById): array
    {
        $parent = array();
        foreach ( array_keys($candidates) as $id ) {
            $parent[(string) $id] = (string) $id;
        }

        $find = static function (string $node) use (&$parent): string {
            while ( $parent[$node] !== $node ) {
                $parent[$node] = $parent[$parent[$node]];
                $node = $parent[$node];
            }

            return $node;
        };

        foreach ( array_keys($candidates) as $id ) {
            $id = (string) $id;
            $siblings = is_array($detectionById[$id]['responsive_siblings'] ?? null) ? $detectionById[$id]['responsive_siblings'] : array();
            foreach ( $siblings as $sibling ) {
                $siblingId = is_array($sibling) && isset($sibling['id']) && is_scalar($sibling['id']) ? (string) $sibling['id'] : '';
                if ( '' !== $siblingId && isset($parent[$siblingId]) ) {
                    $parent[$find($id)] = $find($siblingId);
                }
            }
        }

        $components = array();
        foreach ( array_keys($candidates) as $id ) {
            $components[$find((string) $id)][] = (string) $id;
        }

        $groups = array();
        foreach ( $components as $members ) {
            $ordered = $this->orderVariantIds($members, $candidates, $detectionById);
            foreach ( $ordered as $memberId ) {
                $groups[$memberId] = $ordered;
            }
        }

        return $groups;
    }

    /**
     * Order group members widest-first so the primary variant sorts first.
     *
     * @param array<int, string>                  $ids
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, array<string, mixed>> $detectionById
     * @return array<int, string>
     */
    private function orderVariantIds(array $ids, array $candidates, array $detectionById): array
    {
        usort(
            $ids,
            function (string $left, string $right) use ($candidates, $detectionById): int {
                $leftWidth = (float) ($candidates[$left]['dimensions']['width'] ?? 0);
                $rightWidth = (float) ($candidates[$right]['dimensions']['width'] ?? 0);
                if ( $leftWidth !== $rightWidth ) {
                    return $rightWidth <=> $leftWidth;
                }

                $leftRank = $this->deviceRank((string) ($detectionById[$left]['device_hint'] ?? 'unknown'));
                $rightRank = $this->deviceRank((string) ($detectionById[$right]['device_hint'] ?? 'unknown'));

                return $leftRank <=> $rightRank ?: strcmp($left, $right);
            }
        );

        return array_values($ids);
    }

    private function deviceRank(string $deviceHint): int
    {
        return match ( $deviceHint ) {
            'desktop' => 0,
            'tablet'  => 1,
            'mobile'  => 2,
            default   => 3,
        };
    }

    /**
     * Build the ordered breakpoint-variant list for one page plan.
     *
     * @param array<int, string>                  $members Ordered frame ids (widest first).
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, array<string, mixed>> $detectionById
     * @return array<int, array<string, mixed>>
     */
    private function breakpointVariants(array $members, string $primaryId, array $candidates, array $detectionById): array
    {
        $variants = array();
        foreach ( $members as $order => $memberId ) {
            $candidate = $candidates[$memberId];
            $name = (string) ($candidate['node']['name'] ?? $memberId);
            $variants[] = array(
                'frame_id'        => $memberId,
                'name'            => $name,
                'slug'            => $this->slugify($name),
                'device_hint'     => (string) ($detectionById[$memberId]['device_hint'] ?? 'unknown'),
                'viewport_width'  => $candidate['dimensions']['width'],
                'viewport_height' => $candidate['dimensions']['height'],
                'primary'         => $memberId === $primaryId,
                'order'           => $order,
            );
        }

        return $variants;
    }

    /**
     * @param array<string, array{id:string,node:array<string, mixed>,stats:array{nodes:int,texts:int,assets:int},dimensions:array{width:float|null,height:float|null},score:int}> $candidates
     * @return array<int, string>
     */
    private function rankedCandidateIds(array $candidates): array
    {
        uasort(
            $candidates,
            static fn (array $left, array $right): int => $right['score'] <=> $left['score']
                ?: strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''))
        );

        $ids = array_keys($candidates);
        $nonWrapperIds = array_values(array_filter(
            $ids,
            fn (string $id): bool => ! $this->isWrapperName((string) ($candidates[$id]['node']['name'] ?? ''))
        ));

        return empty($nonWrapperIds) ? $ids : $nonWrapperIds;
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
            if ( is_string($childId) ) {
                $childStats = $this->subtreeStats($childId, $nodes, $childrenIndex, $memo);
                $stats['nodes'] += $childStats['nodes'];
                $stats['texts'] += $childStats['texts'];
                $stats['assets'] += $childStats['assets'];
            }
        }

        $memo[$id] = $stats;
        return $stats;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{width:float|null,height:float|null}
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
     * @param array<string, mixed>                $node
     * @param array{width:float|null,height:float|null} $dimensions
     * @param array{nodes:int,texts:int,assets:int} $stats
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string|null>          $parentIndex
     */
    private function scoreCandidate(string $id, array $node, array $dimensions, array $stats, array $nodes, array $parentIndex): int
    {
        $name = (string) ($node['name'] ?? '');
        $width = (float) ($dimensions['width'] ?? 0);
        $height = (float) ($dimensions['height'] ?? 0);
        $area = $width * $height;
        $canvasDistance = $this->ancestorDistance($id, array('CANVAS'), $nodes, $parentIndex);

        return 100
            + min(300, $stats['texts'] * 5)
            + min(140, $stats['assets'] * 10)
            + min(180, intdiv($stats['nodes'], 8))
            + (null === $canvasDistance ? 0 : max(0, 180 - ($canvasDistance * 45)))
            + ($this->isSemanticName($name) ? 140 : 0)
            + ($this->isWebLikeDimensions($width, $height) ? 160 : 0)
            + ($area > 300000 ? 70 : 0)
            - ($this->isWrapperName($name) ? 260 : 0)
            - ($height > 0 && $height < 700 ? 100 : 0)
            - ($area > 0 && $area < 10000 ? 100 : 0);
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
     * @param array<int, string>                 $types
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string|null>         $parentIndex
     * @return array{id:string,name:string,type:string}|null
     */
    private function nearestAncestor(string $id, array $types, array $nodes, array $parentIndex): ?array
    {
        $parent = $parentIndex[$id] ?? null;
        while ( is_string($parent) && isset($nodes[$parent]) && is_array($nodes[$parent]) ) {
            $type = strtoupper((string) ($nodes[$parent]['type'] ?? ''));
            if ( in_array($type, $types, true) ) {
                return array(
                    'id'   => $parent,
                    'name' => (string) ($nodes[$parent]['name'] ?? ''),
                    'type' => $type,
                );
            }
            $parent = $parentIndex[$parent] ?? null;
        }

        return null;
    }

    /**
     * @param array<int, string>                 $types
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string|null>         $parentIndex
     */
    private function ancestorDistance(string $id, array $types, array $nodes, array $parentIndex): ?int
    {
        $distance = 0;
        $parent = $parentIndex[$id] ?? null;
        while ( is_string($parent) && isset($nodes[$parent]) && is_array($nodes[$parent]) ) {
            ++$distance;
            if ( in_array(strtoupper((string) ($nodes[$parent]['type'] ?? '')), $types, true) ) {
                return $distance;
            }
            $parent = $parentIndex[$parent] ?? null;
        }

        return null;
    }

    private function isSemanticName(string $name): bool
    {
        return 1 === preg_match('/\b(home|homepage|landing|pricing|about|contact|blog|article|shop|product|checkout|cart|account|login|page)\b/i', $name);
    }

    private function isWrapperName(string $name): bool
    {
        return 1 === preg_match('/^(outer|mockup|device|browser|screen|content)?\s*wrapper\b|^frame\s+\d+$/i', trim($name));
    }

    private function isWebLikeDimensions(float $width, float $height): bool
    {
        return $width >= 320 && $width <= 2400 && $height >= 700 && $height <= 20000;
    }

    /**
     * @param array<string, mixed> $slugMap
     */
    private function configuredSlug(string $id, array $slugMap): ?string
    {
        if ( isset($slugMap[$id]) && is_scalar($slugMap[$id]) ) {
            $slug = $this->slugify((string) $slugMap[$id]);
            return '' === $slug ? null : $slug;
        }

        return null;
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return '' === $slug ? 'page' : $slug;
    }

    /**
     * @param array<string, int> $seen
     */
    private function dedupeSlug(string $slug, array &$seen): string
    {
        $base = '' === $slug ? 'page' : $slug;
        $seen[$base] = ($seen[$base] ?? 0) + 1;

        return 1 === $seen[$base] ? $base : $base . '-' . $seen[$base];
    }

    /**
     * @param array<string, mixed> $node
     * @param array{width:float|null,height:float|null} $dimensions
     * @return array<int, array<string, mixed>>
     */
    private function pageDiagnostics(string $id, array $node, array $dimensions, bool $explicitSelected): array
    {
        $diagnostics = array();
        $name = (string) ($node['name'] ?? '');
        if ( $this->isWrapperName($name) ) {
            $diagnostics[] = array(
                'severity' => $explicitSelected ? 'info' : 'warning',
                'code'     => 'figma_page_plan_wrapper_name',
                'message'  => 'Selected frame has a wrapper-like name.',
                'frame_id' => $id,
            );
        }

        if ( ! $this->isWebLikeDimensions((float) ($dimensions['width'] ?? 0), (float) ($dimensions['height'] ?? 0)) ) {
            $diagnostics[] = array(
                'severity' => 'info',
                'code'     => 'figma_page_plan_unusual_dimensions',
                'message'  => 'Selected frame dimensions are outside the common web-page range.',
                'frame_id' => $id,
            );
        }

        return $diagnostics;
    }
}
