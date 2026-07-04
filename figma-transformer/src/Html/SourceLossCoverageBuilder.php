<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Builds source-loss coverage summaries for artifact-quality diagnostics.
 */
final class SourceLossCoverageBuilder
{
    /**
     * @param array<string, array<string, mixed>> $domains
     * @return array<string, mixed>
     */
    public function aggregate(array $domains): array
    {
        $decoded = 0;
        $emitted = 0;
        $notEmitted = 0;
        foreach ( $domains as $domain ) {
            $decoded += (int) ($domain['decoded_source_nodes'] ?? 0);
            $emitted += (int) ($domain['emitted_source_nodes'] ?? 0);
            $notEmitted += (int) ($domain['not_emitted_source_nodes'] ?? 0);
        }

        return array(
            'schema' => 'blocks-engine/figma-transformer/source-loss-coverage/v1',
            'decoded_source_nodes' => $decoded,
            'emitted_source_nodes' => $emitted,
            'not_emitted_source_nodes' => $notEmitted,
            'coverage_ratio' => $decoded > 0 ? round($emitted / $decoded, 3) : 1.0,
            'domains' => $domains,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function domain(int $decoded, int $emitted, int $notEmitted, int $intentionallySuppressed = 0): array
    {
        $decoded = max(0, $decoded);
        $emitted = max(0, $emitted);
        $notEmitted = max(0, $notEmitted);
        $intentionallySuppressed = max(0, $intentionallySuppressed);

        return array(
            'decoded_source_nodes' => $decoded,
            'emitted_source_nodes' => min($decoded, $emitted + $intentionallySuppressed),
            'intentionally_suppressed_source_nodes' => min($decoded, $intentionallySuppressed),
            'not_emitted_source_nodes' => $notEmitted,
        );
    }

    /**
     * @param array<string, mixed> $images
     * @return array<string, mixed>
     */
    public function imageDomain(array $images): array
    {
        $decoded = (int) ($images['node_refs'] ?? 0);
        $assetNodes = is_array($images['asset_nodes'] ?? null) ? $images['asset_nodes'] : array();
        if ( empty($assetNodes) ) {
            return $this->domain($decoded, (int) ($images['resolved_assets'] ?? 0), count($images['missing_assets'] ?? array()));
        }

        $emitted = 0;
        $suppressed = 0;
        foreach ( $assetNodes as $assetNode ) {
            if ( ! is_array($assetNode) ) {
                continue;
            }
            if ( true === ($assetNode['emitted'] ?? null) ) {
                ++$emitted;
                continue;
            }
            if ( $this->isIntentionallySuppressedAssetNode($assetNode) ) {
                ++$suppressed;
            }
        }

        return $this->domain($decoded, $emitted, $decoded - $emitted - $suppressed, $suppressed);
    }

    /**
     * @param array<string, mixed> $assetNode
     */
    private function isIntentionallySuppressedAssetNode(array $assetNode): bool
    {
        if ( isset($assetNode['source_loss_reason']) && is_scalar($assetNode['source_loss_reason']) && '' !== (string) $assetNode['source_loss_reason'] ) {
            return true;
        }

        $reason = isset($assetNode['reason']) && is_scalar($assetNode['reason']) ? (string) $assetNode['reason'] : '';
        return in_array($reason, array('hidden', 'hidden_parent', 'clipped_masked', 'zero_area'), true);
    }

    /**
     * @param array<string, mixed> $vectors
     * @return array<string, mixed>
     */
    public function vectorDomain(array $vectors): array
    {
        return $this->domain(
            (int) ($vectors['nodes'] ?? 0),
            (int) ($vectors['rendered_paths'] ?? 0) + (int) ($vectors['rendered_asset_fallbacks'] ?? 0),
            (int) ($vectors['placeholders'] ?? 0)
        );
    }
}
