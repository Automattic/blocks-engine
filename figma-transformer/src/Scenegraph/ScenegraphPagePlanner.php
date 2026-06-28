<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Builds deterministic page extraction plans from decoded Figma scenegraphs.
 */
final class ScenegraphPagePlanner
{
    /**
     * Frame-candidate ceiling for planning-time responsive detection.
     *
     * Responsive grouping is a frame-level question: it only needs the small
     * set of page-candidate FRAMEs the planner already holds in memory (name,
     * dimensions, device hint, ancestor ids) — NOT an index of every
     * descendant node. Detection therefore runs over that frame set without
     * rebuilding a second whole-scenegraph {@see ScenegraphIndex}, so TOTAL
     * node count no longer drives detection memory and grouping stays ON for
     * large designs (e.g. the 293MB "WP.Cloud 2.0" .fig) instead of degrading
     * to one-page-per-frame. The only remaining bound guards the genuinely
     * pathological case of an absurd number of frame candidates, where the
     * O(frames^2) sibling scan would dominate; above it the planner emits a
     * {@see ScenegraphPagePlanner::plan()} `responsive_detection_bounded`
     * diagnostic and degrades to one-page-per-frame. Overridable via the
     * `responsive_detection_frame_limit` plan option.
     */
    private const RESPONSIVE_DETECTION_FRAME_LIMIT = 5000;

    /**
     * Minimum absolute width delta (px) for a sibling-group to count as a real
     * responsive breakpoint spread rather than same-width duplicate drafts.
     */
    private const RESPONSIVE_WIDTH_MATERIAL_PX = 200.0;

    /**
     * Minimum relative width spread (max-min / max) for a responsive group when
     * device hints alone do not distinguish the members.
     */
    private const RESPONSIVE_WIDTH_MATERIAL_RATIO = 0.2;

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

        $detectionResult = $this->detectResponsive($candidates, $nodes, $parentIndex, $options);
        $detectionById = $detectionResult['detection'];
        if ( $detectionResult['bounded'] ) {
            $diagnostics[] = array(
                'severity'              => 'info',
                'code'                  => 'responsive_detection_bounded',
                'message'               => 'Responsive sibling detection was skipped because the design has an unusually large number of frame candidates; emitting one page per frame.',
                'frame_candidate_count' => $detectionResult['frame_candidate_count'],
                'frame_candidate_limit' => $detectionResult['frame_candidate_limit'],
            );
        }

        $grouping = $this->responsiveGroups($candidates, $detectionById);
        $responsiveGroups = $grouping['groups'];
        foreach ( $grouping['diagnostics'] as $groupDiagnostic ) {
            $diagnostics[] = $groupDiagnostic;
        }

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
     * Resolve responsive detection, bounding it against the NUMBER OF FRAME
     * CANDIDATES — not total node count.
     *
     * The prior implementation re-inspected the source, forcing a SECOND full
     * {@see ScenegraphIndex} build over every node; on the 293MB "WP.Cloud 2.0"
     * .fig that second index OOMed, so #265 skipped detection above a
     * 25k-node ceiling and responsive grouping silently switched off at scale.
     * Detection only needs frame-level data (name, width, height, device hint,
     * ancestor ids), all of which the planner already holds in memory after its
     * single index build, so it now runs regardless of total node count. The
     * only remaining bound guards the genuinely pathological case of an absurd
     * number of frame candidates; the `bounded` flag lets
     * {@see ScenegraphPagePlanner::plan()} surface a
     * `responsive_detection_bounded` diagnostic.
     *
     * @param array<string, array<string, mixed>> $candidates   FRAME candidates the planner tracks.
     * @param array<string, array<string, mixed>> $nodes        Already-built node map (for ancestor lookup).
     * @param array<string, string|null>          $parentIndex  Already-built parent index.
     * @param array<string, mixed>                $options
     * @return array{detection: array<string, array<string, mixed>>, bounded: bool, frame_candidate_count: int, frame_candidate_limit: int}
     */
    private function detectResponsive(array $candidates, array $nodes, array $parentIndex, array $options): array
    {
        $limit = isset($options['responsive_detection_frame_limit']) && is_numeric($options['responsive_detection_frame_limit'])
            ? max(1, (int) $options['responsive_detection_frame_limit'])
            : self::RESPONSIVE_DETECTION_FRAME_LIMIT;
        $frameCount = count($candidates);

        if ( $frameCount > $limit ) {
            return array(
                'detection'             => array(),
                'bounded'               => true,
                'frame_candidate_count' => $frameCount,
                'frame_candidate_limit' => $limit,
            );
        }

        return array(
            'detection'             => $this->detectionById($candidates, $nodes, $parentIndex),
            'bounded'               => false,
            'frame_candidate_count' => $frameCount,
            'frame_candidate_limit' => $limit,
        );
    }

    /**
     * Build the frame-level detection report (device_hint / sibling_group_key /
     * responsive_siblings) WITHOUT building a second scenegraph index.
     *
     * The planner already holds every FRAME candidate's dimensions plus the
     * node/parent indexes in memory, so it extracts only the minimal
     * frame-level records the detection heuristics need (name, dimensions,
     * page/section/parent ids) and hands them to
     * {@see ScenegraphFrameInspector::detectResponsiveFrames()}. No descendant
     * node index is materialized for detection — the question is answered from
     * frame-level data alone, which is what keeps grouping memory-safe at
     * scale.
     *
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string|null>          $parentIndex
     * @return array<string, array<string, mixed>>
     */
    private function detectionById(array $candidates, array $nodes, array $parentIndex): array
    {
        $frames = array();
        foreach ( $candidates as $id => $candidate ) {
            $id = (string) $id;
            $node = is_array($candidate['node'] ?? null) ? $candidate['node'] : array();
            $page = $this->nearestAncestor($id, array('CANVAS'), $nodes, $parentIndex);
            $section = $this->nearestAncestor($id, array('SECTION'), $nodes, $parentIndex);
            $parentId = $parentIndex[$id] ?? null;
            $frames[] = array(
                'id'         => $id,
                'name'       => (string) ($node['name'] ?? ''),
                'width'      => $candidate['dimensions']['width'] ?? null,
                'height'     => $candidate['dimensions']['height'] ?? null,
                'page_id'    => $page['id'] ?? null,
                'section_id' => $section['id'] ?? null,
                'parent_id'  => is_string($parentId) ? $parentId : null,
            );
        }

        return $this->frameInspector->detectResponsiveFrames($frames);
    }

    /**
     * Cluster FRAME candidates into responsive sibling-groups.
     *
     * Builds connected components over the inspector's `responsive_siblings`
     * edges (restricted to FRAME ids the planner tracks), then GUARDS each
     * multi-member component: a real responsive group must have distinct device
     * hints OR a material width spread. Same-name + same-device-hint + ~same
     * width siblings are duplicate/iteration drafts (e.g. the "For Hosts" frame
     * that grouped 4 desktop-1440 drafts differing only in height) — they stay
     * as separate pages and surface a `duplicate_draft_frames` diagnostic rather
     * than collapsing into bogus breakpoint variants.
     *
     * Returns a map from every grouped member frame id to its full, ordered
     * variant list (so the page loop can look up the group from whichever member
     * was selected first) plus the grouping-rationale / rejection diagnostics.
     *
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, array<string, mixed>> $detectionById
     * @return array{groups: array<string, array<int, string>>, diagnostics: array<int, array<string, mixed>>}
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
        $diagnostics = array();
        foreach ( $components as $members ) {
            if ( count($members) < 2 ) {
                continue;
            }

            $ordered = $this->orderVariantIds($members, $candidates, $detectionById);
            $assessment = $this->assessResponsiveGroup($ordered, $candidates, $detectionById);

            if ( $assessment['is_responsive'] ) {
                foreach ( $ordered as $memberId ) {
                    $groups[$memberId] = $ordered;
                }
                $diagnostics[] = array(
                    'severity'              => 'info',
                    'code'                  => 'responsive_group_formed',
                    'message'               => 'Collapsed frames into one responsive page.',
                    'primary_frame_id'      => $ordered[0],
                    'frame_ids'             => $ordered,
                    'reasons'               => $assessment['reasons'],
                    'device_hints'          => $assessment['device_hints'],
                    'distinct_device_hints' => $assessment['distinct_hint_count'],
                    'width_spread_px'       => $assessment['width_spread_px'],
                );
                continue;
            }

            // Duplicate/iteration drafts: keep them as separate pages (omitted
            // from the group map so the page loop falls back to one-per-frame).
            $canonicalId = $this->canonicalDraftId($ordered, $candidates);
            $diagnostics[] = array(
                'severity'           => 'warning',
                'code'               => 'duplicate_draft_frames',
                'message'            => 'Frames share a name, device hint, and width; treated as duplicate drafts rather than responsive breakpoints.',
                'canonical_frame_id' => $canonicalId,
                'draft_frame_ids'    => array_values(array_filter($ordered, static fn (string $id): bool => $id !== $canonicalId)),
                'frame_ids'          => $ordered,
                'device_hint'        => $assessment['device_hints'][0] ?? 'unknown',
                'width'              => (float) ($candidates[$canonicalId]['dimensions']['width'] ?? 0),
            );
        }

        return array('groups' => $groups, 'diagnostics' => $diagnostics);
    }

    /**
     * Decide whether an ordered sibling cluster is a genuine responsive group.
     *
     * A cluster qualifies when it has at least two distinct (non-unknown) device
     * hints OR a material width spread (both an absolute and a relative
     * threshold), which separates real breakpoints from same-width duplicate
     * drafts. The rationale is returned so the caller can emit it as a
     * diagnostic.
     *
     * @param array<int, string>                  $members Ordered frame ids (widest first).
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, array<string, mixed>> $detectionById
     * @return array{is_responsive: bool, reasons: array<int, string>, device_hints: array<int, string>, distinct_hint_count: int, width_spread_px: float, width_spread_ratio: float}
     */
    private function assessResponsiveGroup(array $members, array $candidates, array $detectionById): array
    {
        $hints = array();
        $widths = array();
        foreach ( $members as $id ) {
            $hints[] = (string) ($detectionById[$id]['device_hint'] ?? 'unknown');
            $width = $candidates[$id]['dimensions']['width'] ?? null;
            if ( is_numeric($width) ) {
                $widths[] = (float) $width;
            }
        }

        $distinctKnownHints = array_values(array_unique(array_filter(
            $hints,
            static fn (string $hint): bool => 'unknown' !== $hint
        )));
        $distinctHintCount = count($distinctKnownHints);

        $minWidth = array() === $widths ? 0.0 : min($widths);
        $maxWidth = array() === $widths ? 0.0 : max($widths);
        $spread = $maxWidth - $minWidth;
        $ratio = $maxWidth > 0.0 ? $spread / $maxWidth : 0.0;

        $reasons = array();
        if ( $distinctHintCount >= 2 ) {
            $reasons[] = 'device_hint_diversity';
        }
        if ( $spread >= self::RESPONSIVE_WIDTH_MATERIAL_PX && $ratio >= self::RESPONSIVE_WIDTH_MATERIAL_RATIO ) {
            $reasons[] = 'width_spread';
        }

        return array(
            'is_responsive'       => array() !== $reasons,
            'reasons'             => $reasons,
            'device_hints'        => $hints,
            'distinct_hint_count' => $distinctHintCount,
            'width_spread_px'     => $spread,
            'width_spread_ratio'  => $ratio,
        );
    }

    /**
     * Pick the canonical frame from a duplicate-draft cluster (highest score,
     * then deterministic id tiebreak) for the diagnostic record.
     *
     * @param array<int, string>                  $members
     * @param array<string, array<string, mixed>> $candidates
     */
    private function canonicalDraftId(array $members, array $candidates): string
    {
        $canonical = $members[0];
        foreach ( $members as $id ) {
            $score = (int) ($candidates[$id]['score'] ?? 0);
            $bestScore = (int) ($candidates[$canonical]['score'] ?? 0);
            if ( $score > $bestScore || ( $score === $bestScore && strcmp($id, $canonical) < 0 ) ) {
                $canonical = $id;
            }
        }

        return $canonical;
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
