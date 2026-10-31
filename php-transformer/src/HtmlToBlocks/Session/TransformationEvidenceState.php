<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session;

final class TransformationEvidenceState
{
    /** @var list<array<string, mixed>> */
    private array $conversionDiagnostics = array();

    public function recordConversionDiagnostic(array $finding): void
    {
        $this->conversionDiagnostics[] = $finding;
    }

    public function conversionDiagnostics(): array
    {
        return $this->conversionDiagnostics;
    }

    /** @var list<string> */
    private array $formControlEchoTexts = array();

    /** @var list<array<string, mixed>> */
    private array $frozenHiddenStateFindings = array();

    /** @var list<array<string, mixed>> */
    private array $droppedLinkWrapperFindings = array();

    /** @var list<array<string, mixed>> */
    private array $gutenbergIncompatibilities = array();

    /** @var array<string, array{selector: string, min_width: string}> */
    private array $responsiveGeometryAmbiguities = array();

    /** @var array<string, array{selector: string, height: string}> */
    private array $responsiveHeightAmbiguities = array();

    /** @var array<string, array{provenance_id: ?int, selector: string, direct_child_count: int, block_child_count: int, source_tags: list<string>, block_tags: list<string>}> */
    private array $authorLayoutTopologies = array();

    /** @var list<array<string, mixed>> */
    private array $responsiveImageFallbacks = array();

    /** @var array<string, true> */
    private array $responsiveImageFallbackSelectors = array();

    /** @var array<string, array{selector: string, reason: string}> */
    private array $gridPlacementCarrierFindings = array();

    /** @var list<array{selector: string, reason: string}> */
    private array $unscopedCoreSelectorDrops = array();

    /** @var array<string, true> */
    private array $unscopedCoreSelectorDropKeys = array();

    public function recordFormControlEcho(string $text): void
    {
        $text = trim($text);
        if ( '' !== $text ) {
            $this->formControlEchoTexts[] = $text;
        }
    }

    /** @return list<string> */
    public function formControlEchoTexts(): array
    {
        return $this->formControlEchoTexts;
    }

    /** @param array<string, mixed> $finding */
    public function recordFrozenHiddenState(array $finding): void
    {
        $this->frozenHiddenStateFindings[] = $finding;
    }

    /**
     * Records an author-selector projection arm dropped because it targeted a
     * core block class with no importer-emitted class anywhere in its chain
     * and no owner class was available to scope it with.
     *
     * @param string $selector The authored selector whose arm was dropped.
     * @param string $reason   Stable machine reason for the drop.
     */
    public function recordUnscopedCoreSelectorDrop(string $selector, string $reason): void
    {
        $key = $reason . "\0" . $selector;
        if ( isset($this->unscopedCoreSelectorDropKeys[$key]) ) {
            return;
        }
        $this->unscopedCoreSelectorDropKeys[$key] = true;
        $this->unscopedCoreSelectorDrops[] = array('selector' => $selector, 'reason' => $reason);
    }

    /** @return list<array{selector: string, reason: string}> */
    public function unscopedCoreSelectorDrops(): array
    {
        return $this->unscopedCoreSelectorDrops;
    }

    /** @return list<array<string, mixed>> */
    public function frozenHiddenStateFindings(): array
    {
        return $this->frozenHiddenStateFindings;
    }

    /** @param array<string, mixed> $finding */
    public function recordDroppedLinkWrapper(array $finding): void
    {
        $this->droppedLinkWrapperFindings[] = $finding;
    }

    /** @return list<array<string, mixed>> */
    public function droppedLinkWrapperFindings(): array
    {
        return $this->droppedLinkWrapperFindings;
    }

    /** @param array<string, mixed> $finding */
    public function recordGutenbergIncompatibility(array $finding): void
    {
        $this->gutenbergIncompatibilities[] = $finding;
    }

    /** @return list<array<string, mixed>> */
    public function gutenbergIncompatibilities(): array
    {
        return $this->gutenbergIncompatibilities;
    }

    public function recordResponsiveGeometryAmbiguity(string $selector, string $minimumWidth): void
    {
        $this->responsiveGeometryAmbiguities[$selector . "\0" . $minimumWidth] = array(
            'selector' => $selector,
            'min_width' => $minimumWidth,
        );
    }

    /** @return list<array{selector: string, min_width: string}> */
    public function responsiveGeometryAmbiguities(): array
    {
        return array_values($this->responsiveGeometryAmbiguities);
    }

    public function recordResponsiveHeightAmbiguity(string $selector, string $height): void
    {
        $this->responsiveHeightAmbiguities[$selector . "\0" . $height] = array(
            'selector' => $selector,
            'height' => $height,
        );
    }

    /** @return list<array{selector: string, height: string}> */
    public function responsiveHeightAmbiguities(): array
    {
        return array_values($this->responsiveHeightAmbiguities);
    }

    /**
     * @param list<string> $sourceTags
     * @param list<string> $blockTags
     */
    public function recordAuthorLayoutTopology(string $selector, int $sourceChildCount, int $blockChildCount, array $sourceTags, array $blockTags, ?int $provenanceId = null): void
    {
        // Shell artifact conversion can visit the same source container before
        // page conversion. Keep the latest provenance, not a speculative finding.
        $this->authorLayoutTopologies[$selector] = array(
            'provenance_id' => $provenanceId,
            'selector' => $selector,
            'direct_child_count' => $sourceChildCount,
            'block_child_count' => $blockChildCount,
            'source_tags' => $sourceTags,
            'block_tags' => $blockTags,
        );
    }

    /** @return list<array{block_class_names: list<string>, selector: string, source_child_count: int, block_child_count: int, source_tags: list<string>, block_tags: list<string>}> */
    public function authorLayoutTopologyFindings(array $blocks = array()): array
    {
        $classesBySource = array();
        $collect = static function (array $blocks) use (&$collect, &$classesBySource): void {
            foreach ( $blocks as $block ) {
                $ids = $block['_source_provenance_ids'] ?? array($block['_source_provenance_id'] ?? null);
                $tokens = preg_split('/\s+/', trim((string) ($block['attrs']['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: array();
                // Only engine-generated output tokens, never authored classes or
                // generic WordPress save classes shared by unrelated blocks.
                $tokens = array_values(array_filter($tokens, static fn (string $token): bool => null !== \Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker::markerKind($token)
                    || str_starts_with($token, 'blocks-engine-editor-anchor-')
                    || 1 === preg_match('/^be-[a-z-]+-[a-f0-9]{12,}(?:-[0-9]+)?$/', $token)));
                foreach ( $ids as $id ) {
                    if ( is_int($id) ) $classesBySource[$id] = $tokens;
                }
                $collect($block['innerBlocks'] ?? array());
            }
        };
        $collect($blocks);
        $findings = array();
        foreach ( $this->authorLayoutTopologies as $layout ) {
            if ( 0 === $layout['direct_child_count'] ) {
                continue;
            }
            if ( $layout['direct_child_count'] === $layout['block_child_count'] && $layout['source_tags'] === $layout['block_tags'] ) {
                continue;
            }
            $findings[] = array(
                'block_class_names' => $classesBySource[$layout['provenance_id'] ?? -1] ?? array(),
                'selector' => $layout['selector'],
                'source_child_count' => $layout['direct_child_count'],
                'block_child_count' => $layout['block_child_count'],
                'source_tags' => $layout['source_tags'],
                'block_tags' => $layout['block_tags'],
            );
        }

        return array_slice($findings, 0, 20);
    }

    /** @param array<string, mixed> $fallback */
    public function recordResponsiveImageFallback(string $selector, array $fallback): void
    {
        if ( isset($this->responsiveImageFallbackSelectors[$selector]) ) {
            return;
        }

        $this->responsiveImageFallbackSelectors[$selector] = true;
        $this->responsiveImageFallbacks[] = $fallback;
    }

    public function hasResponsiveImageFallback(string $selector): bool
    {
        return isset($this->responsiveImageFallbackSelectors[$selector]);
    }

    /** @return list<array<string, mixed>> */
    public function responsiveImageFallbacks(): array
    {
        return $this->responsiveImageFallbacks;
    }

    /**
     * Record grid-item placement that stayed on carrier CSS instead of
     * becoming native block layout data, with the typed reason it was kept.
     * Deduplicated per selector and reason: the same element's presentation
     * attributes can be resolved under several cache keys.
     */
    public function recordGridPlacementCarrierFinding(string $selector, string $reason): void
    {
        $this->gridPlacementCarrierFindings[$selector . "\0" . $reason] = array(
            'selector' => $selector,
            'reason' => $reason,
        );
    }

    /** @return list<array{selector: string, reason: string}> */
    public function gridPlacementCarrierFindings(): array
    {
        return array_values($this->gridPlacementCarrierFindings);
    }
}
