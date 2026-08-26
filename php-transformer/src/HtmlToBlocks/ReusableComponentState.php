<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/** Per-transform reusable component recognition and mapping state. */
final class ReusableComponentState
{
    /** @var array<string, mixed>|null */
    private ?array $recognition = null;

    /** @var array<string, string> */
    private array $fingerprints = array();

    /** @var array<string, true> */
    private array $generatedCandidates = array();

    /** @param array<string, mixed> $recognition */
    public function installRecognition(array $recognition): void
    {
        $this->recognition = $recognition;
        foreach ($recognition['candidates'] as $candidate) {
            if (is_array($candidate) && is_string($candidate['path'] ?? null) && is_string($candidate['fingerprint'] ?? null)) {
                $this->fingerprints[$candidate['path']] = $candidate['fingerprint'];
            }
        }
    }

    public function fingerprintForPath(string $path): ?string
    {
        return $this->fingerprints[$path] ?? null;
    }

    public function markGeneratedCandidate(string $path): void
    {
        $this->generatedCandidates[$path] = true;
    }

    public function isGeneratedCandidate(string $path): bool
    {
        return isset($this->generatedCandidates[$path]);
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<string, mixed>
     */
    public function report(array $assets): array
    {
        $recognition = $this->recognition
            ?? throw new \LogicException('Reusable component recognition has not been installed for this transform.');
        $assetOccurrences = array();
        foreach ($assets as $asset) {
            if (!is_array($asset) || 'inline-svg' !== ($asset['source'] ?? null)) {
                continue;
            }
            foreach (is_array($asset['component_occurrence_counts'] ?? null) ? $asset['component_occurrence_counts'] : array() as $fingerprint => $count) {
                if (is_string($fingerprint) && is_int($count)) {
                    $assetOccurrences[$fingerprint] = (int) ($assetOccurrences[$fingerprint] ?? 0) + $count;
                }
            }
        }
        foreach ($recognition['components'] as &$component) {
            if (!is_array($component) || 'svg' !== ($component['tag'] ?? null)) {
                continue;
            }
            $mapped = (int) ($assetOccurrences[$component['fingerprint']] ?? 0);
            $component['mapping'] = $mapped === ($component['occurrence_count'] ?? 0) && 0 < $mapped
                ? 'shared_core_image_asset'
                : 'capability_gap:svg_instances_not_all_core_image_assets';
            $component['mapped_asset_occurrence_count'] = $mapped;
        }
        unset($component);

        return $recognition;
    }
}
