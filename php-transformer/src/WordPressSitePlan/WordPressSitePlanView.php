<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;

/** Bounded importer-facing projection of a canonical WordPress site plan result. */
final class WordPressSitePlanView
{
    public const SCHEMA = 'blocks-engine/wordpress-site-plan-view/v1';

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

        return array(
            'schema' => self::SCHEMA,
            'result_schema' => $data['schema'],
            'status' => $data['status'],
            'wordpress_site_plan' => $this->arrayValue($sourceReports, 'wordpress_site_plan'),
            'gutenberg_gaps' => $this->arrayValue($sourceReports, 'gutenberg_gaps'),
            'companion_plugin_payload' => $this->arrayValue($sourceReports, 'companion_plugin_payload'),
            'font_materialization' => $this->arrayValue($theme, 'font_materialization'),
            'diagnostics' => $this->arrayValue($sourceReports, 'wordpress_site_plan_diagnostics'),
        );
    }

    /** @param array<string,mixed> $data @return array<mixed> */
    private function arrayValue(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : array();
    }
}
