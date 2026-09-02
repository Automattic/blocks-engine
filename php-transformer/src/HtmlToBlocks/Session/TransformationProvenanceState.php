<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session;

final class TransformationProvenanceState
{
    /** @var array<string, mixed> */
    private array $fallback = array();

    /** @var array<int, array<string, mixed>> */
    private array $presentationSignals = array();

    /** @var array<int, array<string, mixed>> */
    private array $structureSignals = array();

    /** @var array<int, array<string, mixed>> */
    private array $sources = array();

    /** @var array<int, bool> */
    private array $sourceBaseHiddenStates = array();

    /** @var array<string, string> */
    private array $formControlSlotTokens = array();

    private int $nextSourceId = 1;

    /** @param array<string, mixed> $fallback */
    public function installFallback(array $fallback): void
    {
        $this->fallback = $fallback;
    }

    /** @return array<string, mixed> */
    public function fallback(): array
    {
        return $this->fallback;
    }

    /** @param array<string, mixed> $signal */
    public function recordPresentationSignal(array $signal): void
    {
        $this->presentationSignals[] = $signal;
    }

    /** @return array<int, array<string, mixed>> */
    public function presentationSignals(): array
    {
        return $this->presentationSignals;
    }

    /** @param array<string, mixed> $signal */
    public function recordStructureSignal(array $signal): void
    {
        $this->structureSignals[] = $signal;
    }

    /** @return array<int, array<string, mixed>> */
    public function structureSignals(): array
    {
        return $this->structureSignals;
    }

    /** @param array<string, mixed> $entry */
    public function registerSource(array $entry, bool $startsHidden): int
    {
        $id = $this->nextSourceId++;
        $this->sources[$id] = $entry;
        $this->sourceBaseHiddenStates[$id] = $startsHidden;

        return $id;
    }

    /** @param array<string, mixed> $evidence */
    public function addSourceEvidence(int $id, array $evidence): void
    {
        if ( isset($this->sources[$id]) ) {
            $this->sources[$id] = array_merge($this->sources[$id], $evidence);
        }
    }

    /** @return array<string, mixed> */
    public function source(int $id): array
    {
        return $this->sources[$id] ?? array();
    }

    /** @return array<int, array<string, mixed>> */
    public function sources(): array
    {
        return $this->sources;
    }

    /** @return array<int, bool> */
    public function sourceBaseHiddenStates(): array
    {
        return $this->sourceBaseHiddenStates;
    }

    public function reserveFormControlSlot(string $path): string
    {
        $token = 'form-control-slot-' . $this->nextSourceId;
        $this->formControlSlotTokens[$path] = $token;

        return $token;
    }

    public function releaseFormControlSlot(string $path): void
    {
        unset($this->formControlSlotTokens[$path]);
    }

    public function formControlSlotToken(string $path): ?string
    {
        return $this->formControlSlotTokens[$path] ?? null;
    }

    /**
     * Resolve temporary source IDs into report paths and remove all internal
     * transformation annotations before block serialization.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    public function resolveBlockPaths(array &$blocks): array
    {
        $resolved = array();
        $this->resolveBlockPathsRecursive($blocks, 'blocks', $resolved);

        return $resolved;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $resolved
     */
    private function resolveBlockPathsRecursive(array &$blocks, string $path, array &$resolved): void
    {
        foreach ( $blocks as $index => &$block ) {
            $blockPath = $path . '.' . $index;
            $sourceIds = is_array($block['_source_provenance_ids'] ?? null)
                ? $block['_source_provenance_ids']
                : array($block['_source_provenance_id'] ?? null);
            foreach ( $sourceIds as $sourceId ) {
                if ( is_int($sourceId) && isset($this->sources[$sourceId]) ) {
                    $resolved[] = array_merge(
                        array( 'block_path' => $blockPath ),
                        $this->sources[$sourceId],
                        ! empty($block['_editability_runtime_owned']) ? array( 'editability_runtime_owned' => true ) : array(),
                        ! empty($block['_editability_visual_owned']) ? array( 'editability_visual_owned' => true ) : array()
                    );
                }
            }

            unset($block['_source_provenance_id']);
            unset($block['_source_provenance_ids']);
            unset($block['_layout_shell_wrappers']);
            unset($block['_binding_token']);
            unset($block['_editability_runtime_owned']);
            unset($block['_editability_visual_owned']);

            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $this->resolveBlockPathsRecursive($block['innerBlocks'], $blockPath . '.innerBlocks', $resolved);
            }
        }
        unset($block);
    }
}
