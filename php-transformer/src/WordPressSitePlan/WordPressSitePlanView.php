<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use InvalidArgumentException;

/** Bounded importer-facing projection of a canonical WordPress site plan result. */
final class WordPressSitePlanView
{
    public const SCHEMA = 'blocks-engine/wordpress-site-plan-view/v1';
    public const COMPACT_SCHEMA = 'blocks-engine/wordpress-site-plan-view/v2';
    public const MAX_FAILURE_DIAGNOSTICS = 100;

    /** @return array<string,mixed> */
    public function fromResult(TransformerResult|array $result): array
    {
        $data = $result instanceof TransformerResult ? array(
            'schema' => TransformerResult::SCHEMA,
            'status' => $result->status,
            'components' => $result->components,
            'block_types' => $result->blockTypes,
            'source_reports' => $result->sourceReports,
            'blocks' => $result->blocks,
            'serialized_blocks' => $result->serializedBlocks,
            'documents' => $result->documents,
            'assets' => $result->assets,
            'diagnostics' => $result->diagnostics,
            'fallbacks' => $result->fallbacks,
            'provenance' => $result->provenance,
            'coverage' => $result->coverage,
            'context' => $result->context,
            'metrics' => $result->metrics,
        ) : $result;
        TransformerResult::assertCanonicalEnvelope($data);
        $sourceReports = $data['source_reports'];
        $wordpressSitePlan = $this->arrayValue($sourceReports, 'wordpress_site_plan');
        $diagnostics = $this->arrayValue($sourceReports, 'wordpress_site_plan_diagnostics');
        if ('failed' === $data['status'] && array() === $wordpressSitePlan && array() === $diagnostics) {
            $diagnostics = $this->boundedFailureDiagnostics($data['diagnostics']);
        }

        return array(
            'schema' => self::SCHEMA,
            'result_schema' => $data['schema'],
            'status' => $data['status'],
            'wordpress_site_plan' => $wordpressSitePlan,
            'gutenberg_gaps' => $this->arrayValue($sourceReports, 'gutenberg_gaps'),
            'companion_plugin_payload' => $this->arrayValue($sourceReports, 'companion_plugin_payload'),
            'font_materialization' => $this->arrayValue($sourceReports, 'font_materialization'),
            'editability_report' => $this->arrayValue($sourceReports, 'editability_report'),
            'diagnostics' => $diagnostics,
        );
    }

    /**
     * Compact only the view-owned duplicate asset transports. The canonical plan
     * remains unchanged at every external boundary; materialize() restores it.
     *
     * @param array<string,mixed> $view
     * @return array<string,mixed>
     */
    public function compact(array $view): array
    {
        if (self::SCHEMA !== ($view['schema'] ?? null) || !is_array($view['wordpress_site_plan'] ?? null)) {
            throw new InvalidArgumentException('WordPress site plan view compaction requires the canonical v1 view.');
        }
        $plan = $view['wordpress_site_plan'];
        if (!is_array($plan['assets'] ?? null) || !is_array($plan['writes'] ?? null)) {
            throw new InvalidArgumentException('WordPress site plan view has an invalid canonical plan.');
        }
        $payloads = array();
        foreach ($plan['assets'] as &$asset) {
            $field = is_string($asset['content_base64'] ?? null) ? 'content_base64' : 'content';
            $data = $asset[$field] ?? null;
            if (!is_string($data)) continue;
            $key = hash('sha256', $data);
            $payloads[$key] = array('sha256' => $key, 'data' => $data);
            $compactAsset = array();
            foreach ($asset as $assetKey => $assetValue) $compactAsset[$field === $assetKey ? 'view_payload' : $assetKey] = $field === $assetKey ? $key : $assetValue;
            $asset = $compactAsset;
            $asset['view_payload_field'] = $field;
        }
        unset($asset);
        foreach ($plan['writes'] as &$write) {
            $payload = $write['payload'] ?? null;
            if (!is_array($payload) || !is_string($payload['data'] ?? null) || !in_array($payload['encoding'] ?? null, array('utf8', 'base64'), true)) continue;
            $key = hash('sha256', $payload['data']);
            $payloads[$key] = array('sha256' => $key, 'data' => $payload['data']);
            $compactPayload = array();
            foreach ($payload as $payloadKey => $payloadValue) $compactPayload['data' === $payloadKey ? 'view_payload' : $payloadKey] = 'data' === $payloadKey ? $key : $payloadValue;
            $write['payload'] = $compactPayload;
        }
        unset($write);
        $view['schema'] = self::COMPACT_SCHEMA;
        $view['wordpress_site_plan'] = $plan;
        $view['view_payloads'] = $payloads;
        return $view;
    }

    /** @param array<string,mixed> $view @return array<string,mixed> */
    public static function materialize(array $view): array
    {
        if (self::COMPACT_SCHEMA !== ($view['schema'] ?? null) || !is_array($view['wordpress_site_plan'] ?? null) || !is_array($view['view_payloads'] ?? null)) throw new InvalidArgumentException('WordPress site plan view materialization requires the compact v2 view.');
        $payloads = $view['view_payloads'];
        $payload = static function (mixed $key) use ($payloads): string {
            $row = is_string($key) ? ($payloads[$key] ?? null) : null;
            if (!is_array($row) || !is_string($row['data'] ?? null) || !is_string($row['sha256'] ?? null) || $key !== $row['sha256'] || !hash_equals($key, hash('sha256', $row['data']))) throw new InvalidArgumentException('WordPress site plan view payload is invalid.');
            return $row['data'];
        };
        $plan = $view['wordpress_site_plan'];
        foreach ($plan['assets'] as &$asset) {
            if (!isset($asset['view_payload'])) continue;
            $data = $payload($asset['view_payload']);
            $field = $asset['view_payload_field'] ?? null;
            if (!in_array($field, array('content', 'content_base64'), true)) throw new InvalidArgumentException('WordPress site plan view payload transport is invalid.');
            $restoredAsset = array();
            foreach ($asset as $assetKey => $assetValue) if ('view_payload_field' !== $assetKey) $restoredAsset['view_payload' === $assetKey ? $field : $assetKey] = 'view_payload' === $assetKey ? $data : $assetValue;
            $asset = $restoredAsset;
        }
        unset($asset);
        foreach ($plan['writes'] as &$write) {
            if (!isset($write['payload']['view_payload'])) continue;
            $data = $payload($write['payload']['view_payload']);
            $restoredPayload = array();
            foreach ($write['payload'] as $payloadKey => $payloadValue) $restoredPayload['view_payload' === $payloadKey ? 'data' : $payloadKey] = 'view_payload' === $payloadKey ? $data : $payloadValue;
            $write['payload'] = $restoredPayload;
        }
        unset($write);
        unset($view['view_payloads']);
        $view['schema'] = self::SCHEMA;
        $view['wordpress_site_plan'] = $plan;
        return $view;
    }

    /** @param array<int,array<string,mixed>> $diagnostics @return array<int,array<string,mixed>> */
    private function boundedFailureDiagnostics(array $diagnostics): array
    {
        $errors = array_values(array_filter($diagnostics, static fn (array $diagnostic): bool => 'error' === ($diagnostic['severity'] ?? null)));
        if (count($errors) <= self::MAX_FAILURE_DIAGNOSTICS) {
            return $errors;
        }

        $retainedCount = self::MAX_FAILURE_DIAGNOSTICS - 1;
        $retained = array_slice($errors, 0, $retainedCount);
        $retained[] = array(
            'code' => 'wordpress_site_plan_view_diagnostics_truncated',
            'severity' => 'warning',
            'message' => 'Additional canonical compiler errors were omitted from the bounded WordPress site plan view.',
            'retained_count' => $retainedCount,
            'omitted_count' => count($errors) - $retainedCount,
        );
        return $retained;
    }

    /** @param array<string,mixed> $data @return array<mixed> */
    private function arrayValue(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : array();
    }
}
