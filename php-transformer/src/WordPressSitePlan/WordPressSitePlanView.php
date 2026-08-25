<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;

/** Bounded importer-facing projection of a canonical WordPress site plan result. */
final class WordPressSitePlanView
{
    public const SCHEMA = 'blocks-engine/wordpress-site-plan-view/v1';
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
        $materializationPlan = is_array($sourceReports['materialization_plan'] ?? null) ? $sourceReports['materialization_plan'] : array();
        $theme = is_array($materializationPlan['theme'] ?? null) ? $materializationPlan['theme'] : array();
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
            'font_materialization' => $this->arrayValue($theme, 'font_materialization'),
            'diagnostics' => $diagnostics,
        );
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
