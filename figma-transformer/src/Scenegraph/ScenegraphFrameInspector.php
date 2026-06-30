<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Builds a compact frame/page candidate report for decoded scenegraphs.
 */
final class ScenegraphFrameInspector
{
    public function __construct(
        private readonly ScenegraphIndex $index = new ScenegraphIndex(),
        private readonly ScenegraphFrameClassifier $frameClassifier = new ScenegraphFrameClassifier()
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
            $classification = $this->candidateClassification($id, $node, $dimensions, $stats, $nodes, $parentIndex);
            $candidates[$id] = array_filter(
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
                    'device_hint'           => $this->deviceHint((string) ($node['name'] ?? ''), $dimensions),
                    'sibling_group_key'     => $this->siblingGroupKey($id, $node, $nodes, $parentIndex),
                    'dev_status'            => $this->candidateDevStatus($node),
                    'role'                  => $classification['role'],
                    'page_type'             => $classification['page_type'],
                ),
                static fn (mixed $value): bool => null !== $value
            );
        }

        foreach ( $candidates as $id => $candidate ) {
            $candidates[$id]['responsive_siblings'] = $this->responsiveSiblings($id, $candidate, $candidates);
        }

        $candidates = array_values($candidates);

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
     * Resolve a candidate frame's own normalized dev status (ready_for_dev|
     * completed), so the matrix frame-selector can prefer dev-marked frames.
     * Returns null when the node carries no dev status.
     *
     * @param array<string, mixed> $node
     */
    private function candidateDevStatus(array $node): ?string
    {
        $resolved = ScenegraphDevStatus::resolve($node);

        return is_array($resolved) ? ($resolved['normalized'] ?? null) : null;
    }

    /**
     * Classify a candidate frame's role (design_system|page) and page_type
     * (front_page|single|archive|page|unknown) so the matrix frame-selector can
     * exclude design-system frames and prefer real pages by WP template type.
     *
     * Uses name signals and basic content metrics (text/asset counts). Content-
     * shape signals (swatch-grid density, card-list repetition) are not computed
     * at inspection time because the full subtree traversal is not warranted
     * here; name signals are authoritative for well-named frames.
     *
     * @param array<string, mixed>                $node
     * @param array{width:float|null,height:float|null} $dimensions
     * @param array{nodes:int,texts:int,assets:int} $stats
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string>               $parentIndex
     * @return array{role:string,page_type:string|null,signals:array<int,string>,is_page:bool}
     */
    private function candidateClassification(
        string $id,
        array $node,
        array $dimensions,
        array $stats,
        array $nodes,
        array $parentIndex
    ): array {
        $section = $this->nearestAncestor($id, array('SECTION'), $nodes, $parentIndex);
        $page    = $this->nearestAncestor($id, array('CANVAS'), $nodes, $parentIndex);

        return $this->frameClassifier->classify(array(
            'name'                 => (string) ($node['name'] ?? ''),
            'width'                => $dimensions['width'] ?? null,
            'height'               => $dimensions['height'] ?? null,
            'text_count'           => (int) ($stats['texts'] ?? 0),
            'asset_count'          => (int) ($stats['assets'] ?? 0),
            'uniform_tile_count'   => 0,
            'repeating_card_count' => 0,
            'section_name'         => $section['name'] ?? null,
            'page_name'            => $page['name'] ?? null,
        ));
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
     * @param array{width: float|null, height: float|null} $dimensions
     */
    private function deviceHint(string $name, array $dimensions): string
    {
        $normalized = strtolower($name);
        $width = isset($dimensions['width']) && is_numeric($dimensions['width']) ? (float) $dimensions['width'] : 0.0;

        if ( 1 === preg_match('/\b(mobile|phone|iphone|android)\b/', $normalized) || ($width > 0 && $width < 700) ) {
            return 'mobile';
        }
        if ( 1 === preg_match('/\b(tablet|ipad)\b/', $normalized) || ($width >= 700 && $width < 1100) ) {
            return 'tablet';
        }
        if ( 1 === preg_match('/\b(desktop|web|1440|1512|1728|1920)\b/', $normalized) || $width >= 1100 ) {
            return 'desktop';
        }

        return 'unknown';
    }

    /**
     * Memory-efficient responsive detection over a pre-extracted set of
     * frame-level candidates, with NO {@see ScenegraphIndex} build.
     *
     * Responsive grouping is a frame-level question: it only needs the set of
     * page-candidate FRAMEs and a few light attributes (name, width, height,
     * device hint, and the page/section/parent ids that scope name-similarity
     * relationships). It does NOT need an index of every descendant node. The
     * planner already holds those frame attributes in memory after its single
     * index build, so it hands them here instead of forcing this inspector to
     * rebuild a second whole-scenegraph index — the build that OOMed on the
     * 293MB "WP.Cloud 2.0" .fig and made #265 skip detection above a node
     * ceiling. The returned detection keeps the planner's contract intact
     * (`device_hint`, `sibling_group_key`, `responsive_siblings`) keyed by
     * frame id, so grouping behaves identically — just without the memory cost
     * of a full-node index.
     *
     * @param array<int, array{id?: string, name?: string, width?: float|null, height?: float|null, page_id?: string|null, section_id?: string|null, parent_id?: string|null}> $frames
     * @return array<string, array<string, mixed>>
     */
    public function detectResponsiveFrames(array $frames): array
    {
        $candidates = array();
        foreach ( $frames as $frame ) {
            if ( ! is_array($frame) ) {
                continue;
            }

            $id = isset($frame['id']) && is_scalar($frame['id']) ? (string) $frame['id'] : '';
            if ( '' === $id ) {
                continue;
            }

            $name = (string) ($frame['name'] ?? '');
            $dimensions = array(
                'width'  => is_numeric($frame['width'] ?? null) ? (float) $frame['width'] : null,
                'height' => is_numeric($frame['height'] ?? null) ? (float) $frame['height'] : null,
            );
            $pageId = isset($frame['page_id']) && is_scalar($frame['page_id']) && '' !== (string) $frame['page_id'] ? (string) $frame['page_id'] : null;
            $sectionId = isset($frame['section_id']) && is_scalar($frame['section_id']) && '' !== (string) $frame['section_id'] ? (string) $frame['section_id'] : null;
            $parentId = isset($frame['parent_id']) && is_scalar($frame['parent_id']) && '' !== (string) $frame['parent_id'] ? (string) $frame['parent_id'] : null;

            $candidates[$id] = array_filter(
                array(
                    'id'                => $id,
                    'name'              => $name,
                    'width'             => $dimensions['width'],
                    'height'            => $dimensions['height'],
                    'page'              => null !== $pageId ? array('id' => $pageId) : null,
                    'section'           => null !== $sectionId ? array('id' => $sectionId) : null,
                    'parent'            => null !== $parentId ? array('id' => $parentId) : null,
                    'device_hint'       => $this->deviceHint($name, $dimensions),
                    'sibling_group_key' => $this->siblingGroupKeyFor($name, $pageId, $sectionId),
                ),
                static fn (mixed $value): bool => null !== $value
            );
        }

        $detection = array();
        foreach ( $candidates as $id => $candidate ) {
            $candidate['responsive_siblings'] = $this->responsiveSiblings((string) $id, $candidate, $candidates);
            $detection[(string) $id] = $candidate;
        }

        return $detection;
    }

    /**
     * @param array<string, mixed>                 $node
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string>               $parentIndex
     */
    private function siblingGroupKey(string $id, array $node, array $nodes, array $parentIndex): string
    {
        $page = $this->nearestAncestor($id, array('CANVAS'), $nodes, $parentIndex);
        $section = $this->nearestAncestor($id, array('SECTION'), $nodes, $parentIndex);

        return $this->siblingGroupKeyFor((string) ($node['name'] ?? ''), $page['id'] ?? null, $section['id'] ?? null);
    }

    /**
     * Derive a sibling-group key from frame-level data alone (name + scope
     * ids), so both the full inspection path and the lightweight
     * {@see ScenegraphFrameInspector::detectResponsiveFrames()} path share one
     * normalization. Scope prefers the nearest SECTION, then the page (CANVAS),
     * then a synthetic root.
     */
    private function siblingGroupKeyFor(string $name, ?string $pageId, ?string $sectionId): string
    {
        $scope = (string) (($sectionId ?? '') !== '' ? $sectionId : (($pageId ?? '') !== '' ? $pageId : 'root'));

        return $scope . ':' . $this->normalizedPageName($name);
    }

    /**
     * @param array<string, mixed>                $candidate
     * @param array<string, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function responsiveSiblings(string $id, array $candidate, array $candidates): array
    {
        $siblings = array();
        $groupKey = (string) ($candidate['sibling_group_key'] ?? '');
        $deviceHint = (string) ($candidate['device_hint'] ?? 'unknown');
        $pageId = (string) ($candidate['page']['id'] ?? '');
        $sectionId = (string) ($candidate['section']['id'] ?? '');
        $parentId = (string) ($candidate['parent']['id'] ?? '');

        foreach ( $candidates as $siblingId => $sibling ) {
            if ( $siblingId === $id || ! is_array($sibling) ) {
                continue;
            }

            $sameScope = '' !== $groupKey && $groupKey === (string) ($sibling['sibling_group_key'] ?? '');
            $sameContainer = ('' !== $sectionId && $sectionId === (string) ($sibling['section']['id'] ?? ''))
                || ('' !== $parentId && $parentId === (string) ($sibling['parent']['id'] ?? ''))
                || ('' !== $pageId && $pageId === (string) ($sibling['page']['id'] ?? ''));
            $nameSimilarity = $this->nameSimilarity((string) ($candidate['name'] ?? ''), (string) ($sibling['name'] ?? ''));
            $siblingDeviceHint = (string) ($sibling['device_hint'] ?? 'unknown');

            if ( ! $sameScope && ( ! $sameContainer || $nameSimilarity < 70 ) ) {
                continue;
            }

            $confidence = ($sameScope ? 55 : 0)
                + ($sameContainer ? 20 : 0)
                + min(25, max(0, $nameSimilarity - 60))
                + ('unknown' !== $deviceHint && 'unknown' !== $siblingDeviceHint && $deviceHint !== $siblingDeviceHint ? 15 : 0);

            $siblings[] = array_filter(
                array(
                    'id' => (string) ($sibling['id'] ?? $siblingId),
                    'name' => (string) ($sibling['name'] ?? ''),
                    'device_hint' => $siblingDeviceHint,
                    'width' => $sibling['width'] ?? null,
                    'height' => $sibling['height'] ?? null,
                    'name_similarity' => $nameSimilarity,
                    'confidence' => min(100, $confidence),
                ),
                static fn (mixed $value): bool => null !== $value
            );
        }

        usort(
            $siblings,
            static fn (array $left, array $right): int => ((int) ($right['confidence'] ?? 0) <=> (int) ($left['confidence'] ?? 0))
                ?: strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''))
        );

        return array_slice($siblings, 0, 5);
    }

    /**
     * Normalize a frame name to its page identity by stripping device/breakpoint
     * and width tokens ("Home Page – Desktop" and "Home Page – Mobile" both
     * become "home-page"). This is the shared normalization behind responsive
     * sibling grouping; the page planner also uses it to derive a responsive
     * page's slug so the slug reflects the PAGE, not its widest variant.
     */
    public function normalizedPageName(string $name): string
    {
        $normalized = strtolower($name);
        $normalized = (string) preg_replace('/\b(desktop|mobile|tablet|phone|web|copy|variant|version|v\d+|i\d+)\b/', ' ', $normalized);
        $normalized = (string) preg_replace('/\b\d{3,4}\s*x\s*\d{3,5}\b/', ' ', $normalized);
        $normalized = (string) preg_replace('/\b(320|375|390|393|414|428|768|834|1024|1280|1366|1440|1512|1728|1920)\b/', ' ', $normalized);
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', '-', $normalized), '-');

        return '' === $normalized ? 'frame' : $normalized;
    }

    private function nameSimilarity(string $left, string $right): int
    {
        $leftName = $this->normalizedPageName($left);
        $rightName = $this->normalizedPageName($right);
        if ( $leftName === $rightName ) {
            return 100;
        }

        $max = max(strlen($leftName), strlen($rightName));
        if ( 0 === $max ) {
            return 0;
        }

        return (int) round((1 - (levenshtein($leftName, $rightName) / $max)) * 100);
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
