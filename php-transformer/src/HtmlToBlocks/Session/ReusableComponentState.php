<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RouteStateProjector;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use DOMElement;

/** Per-transform reusable component recognition and mapping state. */
final class ReusableComponentState
{
    /** @var array<string, mixed>|null */
    private ?array $recognition = null;

    /** @var array<string, string> */
    private array $fingerprints = array();

    /** @var array<string, true> */
    private array $generatedCandidates = array();

    /** @var \SplObjectStorage<DOMElement, string>|null Resting navigation evidence keyed by source element. */
    private ?\SplObjectStorage $restingNavigation = null;

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
     * Take the captures' resting navigation evidence off the source before
     * lowering, so no output carries it; only a generated component, which
     * saves its menu as markup for every route, reads it back.
     */
    public function liftRestingNavigation(DOMElement $root): void
    {
        $this->restingNavigation = null;
        $nodes = array();
        foreach ($root->getElementsByTagName('*') as $node) if ($node->hasAttribute(RouteStateProjector::RESTING_STATE_ATTRIBUTE)) $nodes[] = $node;
        foreach ($nodes as $node) {
            $this->restingNavigation ??= new \SplObjectStorage();
            $this->restingNavigation[$node] = $node->getAttribute(RouteStateProjector::RESTING_STATE_ATTRIBUTE);
            $node->removeAttribute(RouteStateProjector::RESTING_STATE_ATTRIBUTE);
        }
    }

    /**
     * Restore the evidence onto a detached copy of a source subtree, node for
     * node, for RouteStateProjector::withRestingNavigation to consume.
     */
    public function withRestingNavigationEvidence(DOMElement $source, DOMElement $copy): void
    {
        if (null === $this->restingNavigation) return;
        $sources = array_merge(array($source), iterator_to_array($source->getElementsByTagName('*'), false));
        $copies = array_merge(array($copy), iterator_to_array($copy->getElementsByTagName('*'), false));
        if (count($sources) !== count($copies)) return;
        foreach ($sources as $index => $node) {
            if ($node instanceof DOMElement && isset($this->restingNavigation[$node]) && $copies[$index] instanceof DOMElement) $copies[$index]->setAttribute(RouteStateProjector::RESTING_STATE_ATTRIBUTE, $this->restingNavigation[$node]);
        }
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
            foreach (ArrayField::array($asset, 'component_occurrence_counts') as $fingerprint => $count) {
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
