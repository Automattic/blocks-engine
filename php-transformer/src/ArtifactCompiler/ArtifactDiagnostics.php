<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

trait ArtifactDiagnostics
{
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
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function dedupeDiagnostics(array $diagnostics): array
    {
        $seen = array();
        $deduped = array();
        foreach ( $diagnostics as $diagnostic ) {
            if ( 'dead_projected_author_selector' === ($diagnostic['code'] ?? '') ) {
                $key = ($diagnostic['path'] ?? '') . "\0" . ($diagnostic['source_selector'] ?? '');
                if ( isset($seen[$key]) ) {
                    $index = $seen[$key];
                    foreach ( array('scopes', 'projected_selectors') as $field ) {
                        $deduped[$index]['context'][$field] = array_values(array_unique(array_merge(
                            $deduped[$index]['context'][$field] ?? array(),
                            $diagnostic['context'][$field] ?? array()
                        )));
                    }
                    continue;
                }
                $seen[$key] = count($deduped);
                $deduped[] = $diagnostic;
                continue;
            }
            $key = json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: serialize($diagnostic);
            if ( isset($seen[$key]) ) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $diagnostic;
        }

        return $deduped;
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
