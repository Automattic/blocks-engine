<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionReportProjection;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentIdentityException;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ValidationException;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanInput;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanView;
use InvalidArgumentException;

/** Derives WordPress site-plan production from a canonical transformer envelope. */
final class WordPressSitePlanComposer
{
    /**
     * @param array<string, int|float> $processMetrics
     */
    public function compose(TransformerResult $envelope, array $processMetrics = array(), ?int $startedAt = null): TransformerResult
    {
        $data = $envelope->toArray();
        $sourceReports = $data['source_reports'];
        $diagnostics = $data['diagnostics'];
        $metrics = $data['metrics'];
        $status = $data['status'];
        $editabilityPolicy = is_array($sourceReports['editability_policy'] ?? null) ? $sourceReports['editability_policy'] : array();
        $editabilityReport = is_array($sourceReports['editability_report'] ?? null) ? $sourceReports['editability_report'] : array();
        $compiledSite = is_array($sourceReports['compiled_site'] ?? null) ? $sourceReports['compiled_site'] : array();
        $runtimeIslandPackage = is_array($sourceReports['runtime_island_package'] ?? null) ? $sourceReports['runtime_island_package'] : array();
        $fallbackEvidence = is_array($sourceReports['core_html_fallback_evidence'] ?? null) ? $sourceReports['core_html_fallback_evidence'] : array();
        $identityFailures = WordPressSitePlan::compiledSiteIdentityFailures($compiledSite);
        $wordpressSitePlan = null;
        // Editability failures retain a failed-quality plan as review evidence;
        // all other failures have no materializable source identity or site plan.
        if ( array() === $identityFailures && ( 'failed' !== $status || 'failed' === ($editabilityPolicy['status'] ?? null) ) ) {
            try {
                $wordpressSitePlan = ( new WordPressSitePlan() )->fromResult($envelope);
                $editabilityReport = (new EditabilityReport())->withTemplateSurfaceSelection($editabilityReport, $wordpressSitePlan['templates']);
                $sourceReports['editability_report'] = $editabilityReport;
            } catch (DocumentIdentityException $exception) {
                foreach ( $exception->diagnostics() as $identityDiagnostic ) {
                    $diagnostics[] = array_merge($identityDiagnostic, array('source' => ArtifactCompiler::class));
                }
            } catch (InvalidArgumentException $exception) {
                $diagnostics[] = $exception instanceof ValidationException
                    ? array_merge($exception->diagnostic(), array('severity' => 'error', 'source' => ArtifactCompiler::class))
                    : $this->diagnostic('wordpress_site_plan_not_self_contained', 'error', $exception->getMessage());
            }
        }
        if ( null !== $wordpressSitePlan ) {
            $fontMaterialization = $wordpressSitePlan['theme']['font_materialization'] ?? array();
            if (is_array($fontMaterialization) && array() !== $fontMaterialization) {
                $sourceReports['font_materialization'] = $fontMaterialization;
            }
        } else {
            $fontMaterialization = WordPressSitePlanInput::fromCompiledSite($compiledSite, $editabilityPolicy, $runtimeIslandPackage, $fallbackEvidence)->fontMaterialization;
            if (array() !== $fontMaterialization) {
                $sourceReports['font_materialization'] = $fontMaterialization;
            }
        }

        $metrics['diagnostic_count'] = count($diagnostics);
        if ( null !== $startedAt ) {
            $metrics['transform_duration_ms'] = (hrtime(true) - $startedAt) / 1000000;
        }
        $reportSourceReports = $sourceReports;
        if ( null !== $wordpressSitePlan ) {
            $reportSourceReports['wordpress_site_plan'] = $wordpressSitePlan;
        }
        $sourceReports['conversion_report'] = ConversionReportProjection::fromResultParts('artifact', $envelope->blocks, $envelope->fallbacks, $reportSourceReports, $envelope->assets, $envelope->provenance, $metrics);
        if ( null !== $wordpressSitePlan ) {
            $sourceReports['wordpress_site_plan'] = $wordpressSitePlan;
        }
        $sourceReports['wordpress_site_plan_diagnostics'] = array_values(array_filter($diagnostics, static fn (array $diagnostic): bool => str_starts_with((string) ($diagnostic['code'] ?? ''), 'wordpress_site_plan_')));
        if ( array() === $sourceReports['wordpress_site_plan_diagnostics'] ) {
            unset($sourceReports['wordpress_site_plan_diagnostics']);
        }
        foreach ( $processMetrics as $key => $value ) {
            $metrics[$key] = $value;
        }

        return new TransformerResult(
            status: $this->statusFromDiagnostics($diagnostics),
            components: $envelope->components,
            blockTypes: $envelope->blockTypes,
            sourceReports: $sourceReports,
            blocks: $envelope->blocks,
            serializedBlocks: $envelope->serializedBlocks,
            documents: $envelope->documents,
            assets: $envelope->assets,
            diagnostics: $diagnostics,
            fallbacks: $envelope->fallbacks,
            provenance: $envelope->provenance,
            coverage: $envelope->coverage,
            context: $envelope->context,
            metrics: $metrics,
            blockCompilationOutput: $envelope->blockCompilationOutput
        );
    }

    /** @return array<string,mixed> */
    public function fromResult(TransformerResult|array $result): array
    {
        return ( new WordPressSitePlan() )->fromResult($result);
    }

    /** @return array<string,mixed> */
    public function view(TransformerResult|array $result): array
    {
        return ( new WordPressSitePlanView() )->fromResult($result);
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function statusFromDiagnostics(array $diagnostics): string
    {
        $warningDiagnostics = array();
        foreach ( $diagnostics as $diagnostic ) {
            if ( 'error' === ($diagnostic['severity'] ?? '') ) {
                return 'failed';
            }

            if ( in_array(($diagnostic['code'] ?? ''), array('preserved_runtime_island', 'runtime_dom_contract_preserved', 'runtime_dom_contract_fallback'), true) ) {
                continue;
            }

            $warningDiagnostics[] = $diagnostic;
        }
        return array() === $warningDiagnostics ? 'success' : 'success_with_warnings';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function diagnostic(string $code, string $severity, string $message, array $context = array()): array
    {
        return array_filter(
            array(
                'code'     => $code,
                'severity' => $severity,
                'message'  => $message,
                'source'   => ArtifactCompiler::class,
                'context'  => $context,
            ),
            static fn (mixed $value): bool => array() !== $value
        );
    }
}
