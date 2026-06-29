<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\CorpusDiagnostics;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;

/**
 * Pure, read-only detectors that turn a transformer result envelope into a flat
 * list of cluster-ready findings plus per-document metrics.
 *
 * Every detector keys off structure and syntax only — never fixture names — so
 * the same signals surface across the entire website-fixture corpus. None of
 * these methods mutate the transformer or its output; they exclusively read the
 * canonical result array produced by HtmlTransformer::transform()->toArray().
 */
final class CorpusDetectors
{
    /**
     * WordPress preset custom properties (var(--wp--...)) are materialized by the
     * theme/global-styles layer and are not part of the custom-property gap, so
     * they are tracked for visibility but excluded from the actionable worklist.
     */
    private const PRESET_VAR_PREFIX = '--wp--';

    /**
     * Run every detector over one transformer result envelope.
     *
     * @param array<string, mixed> $result Canonical transformer result array.
     * @return array{
     *     metrics: array<string, int|float>,
     *     findings: array<int, array<string, mixed>>,
     *     var_names: array<int, string>
     * }
     */
    public static function collect(array $result): array
    {
        $blocks = is_array($result['blocks'] ?? null) ? $result['blocks'] : array();
        $flat = self::flatten($blocks);

        $native = self::nativeRate($flat);
        $varReport = self::varDependentStyling($flat);
        $validityReport = self::blockValidity($result);

        $findings = array();
        foreach ( self::transformerFindings($result) as $finding ) {
            $findings[] = $finding;
        }
        foreach ( $validityReport['findings'] as $finding ) {
            $findings[] = $finding;
        }
        foreach ( $varReport['findings'] as $finding ) {
            $findings[] = $finding;
        }
        foreach ( self::classedSpanInContent($flat) as $finding ) {
            $findings[] = $finding;
        }
        foreach ( self::emptyCoreHtml($flat) as $finding ) {
            $findings[] = $finding;
        }
        foreach ( self::coreHtmlFallback($flat) as $finding ) {
            $findings[] = $finding;
        }

        $metrics = array(
            'block_count'         => $native['total'],
            'native_count'        => $native['native'],
            'core_html_count'     => $native['html'],
            'freeform_count'      => $native['freeform'],
            'native_rate'         => $native['rate'],
            'var_ref_count'       => $varReport['count'],
            'var_custom_ref_count' => $varReport['custom_count'],
            'invalid_block_count' => $validityReport['invalid_block_count'],
        );

        return array(
            'metrics'   => $metrics,
            'findings'  => $findings,
            'var_names' => $varReport['names'],
        );
    }

    /**
     * Flatten the recursive block tree into a depth-first list of block arrays.
     *
     * @param array<int, mixed> $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function flatten(array $blocks): array
    {
        $flat = array();
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }
            $flat[] = $block;
            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                foreach ( self::flatten($block['innerBlocks']) as $child ) {
                    $flat[] = $child;
                }
            }
        }

        return $flat;
    }

    /**
     * Native-rate metric: structured core/native blocks over total blocks.
     * core/html and core/freeform (raw HTML escape hatches) count against the
     * native rate, as do name-less blocks.
     *
     * @param array<int, array<string, mixed>> $flat Flattened block list.
     * @return array{total: int, native: int, html: int, freeform: int, rate: float}
     */
    public static function nativeRate(array $flat): array
    {
        $total = 0;
        $html = 0;
        $freeform = 0;
        $native = 0;

        foreach ( $flat as $block ) {
            ++$total;
            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            if ( 'core/html' === $name ) {
                ++$html;
                continue;
            }
            if ( 'core/freeform' === $name ) {
                ++$freeform;
                continue;
            }
            if ( '' === $name ) {
                continue;
            }
            ++$native;
        }

        return array(
            'total'    => $total,
            'native'   => $native,
            'html'     => $html,
            'freeform' => $freeform,
            'rate'     => $total > 0 ? round($native / $total, 4) : 0.0,
        );
    }

    /**
     * var(--x) references in the emitted block markup. A high density of custom
     * (non-preset) custom-property references signals the CSS custom-property
     * materialization gap, because nothing defines those properties in the
     * generated WordPress output.
     *
     * @param array<int, array<string, mixed>> $flat Flattened block list.
     * @return array{
     *     count: int,
     *     custom_count: int,
     *     names: array<int, string>,
     *     findings: array<int, array<string, mixed>>
     * }
     */
    public static function varDependentStyling(array $flat): array
    {
        $occurrences = array();
        $total = 0;

        foreach ( $flat as $block ) {
            $haystack = self::blockMarkup($block);
            if ( '' === $haystack ) {
                continue;
            }
            if ( ! preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $haystack, $matches) ) {
                continue;
            }
            foreach ( $matches[1] as $name ) {
                ++$total;
                $occurrences[$name] = ($occurrences[$name] ?? 0) + 1;
            }
        }

        $findings = array();
        $customCount = 0;
        foreach ( $occurrences as $name => $count ) {
            if ( self::isPresetVar($name) ) {
                continue;
            }
            $customCount += $count;
            $findings[] = array(
                'source'        => 'detector',
                'detector'      => 'var_dependent_styling',
                'repair_bucket' => 'css_custom_property_materialization',
                'pattern'       => $name,
                'count'         => $count,
            );
        }

        $names = array_keys($occurrences);
        sort($names);

        return array(
            'count'        => $total,
            'custom_count' => $customCount,
            'names'        => $names,
            'findings'     => $findings,
        );
    }

    /**
     * core/paragraph and core/heading whose content carries an inline
     * <span class=...> or <span style=...>. These classed/styled spans inside
     * RichText content are a common block-invalidity risk.
     *
     * @param array<int, array<string, mixed>> $flat Flattened block list.
     * @return array<int, array<string, mixed>>
     */
    public static function classedSpanInContent(array $flat): array
    {
        $findings = array();
        foreach ( $flat as $block ) {
            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            if ( 'core/paragraph' !== $name && 'core/heading' !== $name ) {
                continue;
            }
            $content = self::richTextContent($block);
            if ( '' === $content ) {
                continue;
            }
            if ( preg_match('/<span\b[^>]*\s(?:class|style)\s*=/i', $content) ) {
                $findings[] = array(
                    'source'        => 'detector',
                    'detector'      => 'classed_span_in_content',
                    'repair_bucket' => 'richtext_inline_span_normalization',
                    'pattern'       => $name,
                    'count'         => 1,
                );
            }
        }

        return $findings;
    }

    /**
     * core/html blocks whose content is only whitespace and/or HTML comments —
     * dead blocks, typically left behind when SVG or script content is stripped.
     *
     * @param array<int, array<string, mixed>> $flat Flattened block list.
     * @return array<int, array<string, mixed>>
     */
    public static function emptyCoreHtml(array $flat): array
    {
        $findings = array();
        foreach ( $flat as $block ) {
            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            if ( 'core/html' !== $name ) {
                continue;
            }
            $content = self::rawContent($block);
            $hadComment = (bool) preg_match('/<!--.*?-->/s', $content);
            $stripped = trim(preg_replace('/<!--.*?-->/s', '', $content) ?? '');
            if ( '' !== $stripped ) {
                continue;
            }
            $findings[] = array(
                'source'        => 'detector',
                'detector'      => 'empty_core_html',
                'repair_bucket' => 'drop_empty_html_block',
                'pattern'       => $hadComment ? 'comment_only_or_stripped' : 'whitespace_only',
                'count'         => 1,
            );
        }

        return $findings;
    }

    /**
     * Non-empty core/html escape hatches, clustered by the leading element of
     * their raw content. Surfaces which raw-HTML families still bypass native
     * block conversion.
     *
     * @param array<int, array<string, mixed>> $flat Flattened block list.
     * @return array<int, array<string, mixed>>
     */
    public static function coreHtmlFallback(array $flat): array
    {
        $findings = array();
        foreach ( $flat as $block ) {
            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            if ( 'core/html' !== $name ) {
                continue;
            }
            $content = self::rawContent($block);
            $stripped = trim(preg_replace('/<!--.*?-->/s', '', $content) ?? '');
            if ( '' === $stripped ) {
                continue;
            }
            $tag = preg_match('/<\s*([a-zA-Z][a-zA-Z0-9-]*)/', $stripped, $matches)
                ? '<' . strtolower($matches[1]) . '>'
                : 'text';
            $findings[] = array(
                'source'        => 'detector',
                'detector'      => 'core_html_fallback',
                'repair_bucket' => 'native_block_recognition',
                'pattern'       => $tag,
                'count'         => 1,
            );
        }

        return $findings;
    }

    /**
     * Block-validity findings drawn from the transformer's own
     * source_reports.wp_block_validity report — the same serialization round-trip
     * check the parity suite asserts on. Each finding records the block name and
     * the cause code as its pattern.
     *
     * @param array<string, mixed> $result Canonical transformer result array.
     * @return array{invalid_block_count: int, findings: array<int, array<string, mixed>>}
     */
    public static function blockValidity(array $result): array
    {
        $report = $result['source_reports']['wp_block_validity'] ?? array();
        $rawFindings = is_array($report['findings'] ?? null) ? $report['findings'] : array();

        $findings = array();
        foreach ( $rawFindings as $finding ) {
            if ( ! is_array($finding) ) {
                continue;
            }
            $code = (string) ($finding['code'] ?? 'wp_block_validity_warning');
            $blockName = is_string($finding['block_name'] ?? null) && '' !== $finding['block_name']
                ? $finding['block_name']
                : 'unknown';
            $findings[] = array(
                'source'        => 'validity',
                'detector'      => 'wp_block_validity',
                'repair_bucket' => 'block_serialization_validity_repair',
                'pattern'       => $code . '@' . $blockName,
                'count'         => 1,
            );
        }

        return array(
            'invalid_block_count' => count($findings),
            'findings'            => $findings,
        );
    }

    /**
     * The transformer's own emitted diagnostics, normalized through the canonical
     * finding contract so each carries the (reason_code, pattern_family,
     * repair_bucket) classification triplet. Purely informational summary
     * findings (no_repair_needed) are dropped from the worklist.
     *
     * @param array<string, mixed> $result Canonical transformer result array.
     * @return array<int, array<string, mixed>>
     */
    public static function transformerFindings(array $result): array
    {
        $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : array();

        $findings = array();
        foreach ( $diagnostics as $diagnostic ) {
            if ( ! is_array($diagnostic) ) {
                continue;
            }
            $classified = ConversionFindingContract::withClassification($diagnostic);
            $repairBucket = (string) ($classified['repair_bucket'] ?? '');
            if ( 'no_repair_needed' === $repairBucket ) {
                continue;
            }
            $pattern = (string) ($classified['pattern_family'] ?? '');
            if ( '' === $pattern ) {
                $pattern = ConversionFindingContract::findingCode($classified);
            }
            if ( '' === $pattern ) {
                $pattern = 'unclassified';
            }
            $findings[] = array(
                'source'        => 'transformer',
                'detector'      => 'emitted_finding',
                'repair_bucket' => $repairBucket,
                'pattern'       => $pattern,
                'count'         => 1,
            );
        }

        return $findings;
    }

    /**
     * Cluster key for a finding: the repair lane (falling back to the detector
     * name) paired with the structural pattern.
     *
     * @param array<string, mixed> $finding
     */
    public static function clusterKey(array $finding): string
    {
        $bucket = (string) ($finding['repair_bucket'] ?? '');
        if ( '' === $bucket ) {
            $bucket = (string) ($finding['detector'] ?? 'unclassified');
        }
        $pattern = (string) ($finding['pattern'] ?? 'unclassified');

        return $bucket . ' :: ' . $pattern;
    }

    /**
     * Emitted markup for one block — the saved innerHTML, which is the single
     * source of the rendered style="..." declarations. Reading only innerHTML
     * (rather than also the attribute JSON, which carries the same values) keeps
     * each var() reference counted exactly once.
     *
     * @param array<string, mixed> $block
     */
    private static function blockMarkup(array $block): string
    {
        return is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
    }

    /**
     * RichText content for a paragraph/heading block: the explicit content
     * attribute, falling back to saved innerHTML.
     *
     * @param array<string, mixed> $block
     */
    private static function richTextContent(array $block): string
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        if ( is_string($attrs['content'] ?? null) && '' !== $attrs['content'] ) {
            return $attrs['content'];
        }

        return is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
    }

    /**
     * Raw content for a core/html block.
     *
     * @param array<string, mixed> $block
     */
    private static function rawContent(array $block): string
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        if ( is_string($attrs['content'] ?? null) ) {
            return $attrs['content'];
        }

        return is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
    }

    private static function isPresetVar(string $name): bool
    {
        return str_starts_with($name, self::PRESET_VAR_PREFIX);
    }
}
