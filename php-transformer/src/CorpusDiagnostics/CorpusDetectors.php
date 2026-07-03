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
 * canonical result array produced by HtmlTransformer::transform()->toArray()
 * (plus, for layout-direction faithfulness, the immutable source HTML string).
 *
 * Each finding carries a `severity` (high | medium | info) so the worklist can
 * rank real defects above bulk-but-acceptable behavior, rather than ranking by
 * raw occurrence count alone.
 */
final class CorpusDetectors
{
    /**
     * WordPress preset custom properties (var(--wp--...)) are materialized by the
     * theme/global-styles layer and are not part of any gap, so they are tracked
     * for visibility but excluded from the actionable worklist.
     */
    private const PRESET_VAR_PREFIX = '--wp--';

    public const SEVERITY_HIGH   = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_INFO   = 'info';

    /**
     * Severity ordering used by the runner to rank clusters by importance before
     * raw count. Higher wins.
     */
    private const SEVERITY_RANK = array(
        self::SEVERITY_HIGH   => 3,
        self::SEVERITY_MEDIUM => 2,
        self::SEVERITY_INFO   => 1,
    );

    /**
     * Repair lanes that represent genuine, editor-visible defects: missing
     * artwork, content the block editor would mark invalid, serialization
     * invalidity, and layout that is rendered in the wrong direction. These rank
     * above generic conversion gaps.
     */
    private const HIGH_SEVERITY_BUCKETS = array(
        'svg_content_lost',
        'richtext_invalid_content_risk',
        'block_serialization_validity_repair',
        'layout_direction_misrecognition',
    );

    /**
     * Repair lanes that describe working downstream behavior rather than a defect
     * — they are reported for visibility but kept out of the actionable worklist.
     */
    private const INFO_BUCKETS = array(
        'informational_var_density',
    );

    /**
     * Transformer fallback reason codes that mean an inline <svg> was dropped
     * (its artwork lost) rather than preserved. Routed through the dedicated
     * svg_content_lost detector so they rank by severity instead of hiding among
     * generic asset-materialization findings.
     */
    private const SVG_LOSS_REASON_CODES = array(
        'html_inline_svg_fallback',
        'html_unsafe_inline_svg',
    );

    /**
     * Run every detector over one transformer result envelope.
     *
     * @param array<string, mixed>      $result          Canonical transformer result array.
     * @param string                    $sourceHtml      Original source HTML for the document (for source-aware detectors).
     * @param callable(string): bool|null $columnsVerifier Optional predicate that returns true when a source-element
     *                                                     fragment actually converts to a top-level core/columns block.
     *                                                     Lets the layout-direction detector confirm a misrecognition
     *                                                     instead of guessing from source CSS alone.
     * @return array{
     *     metrics: array<string, int|float>,
     *     findings: array<int, array<string, mixed>>,
     *     var_names: array<int, string>
     * }
     */
    public static function collect(array $result, string $sourceHtml = '', ?callable $columnsVerifier = null): array
    {
        $blocks = is_array($result['blocks'] ?? null) ? $result['blocks'] : array();
        $flat = self::flatten($blocks);

        $native = self::nativeRate($flat);
        $varReport = self::varDependentStyling($flat);
        $validityReport = self::blockValidity($result);

        $richTextRisk = self::richTextInvalidRisk($flat);
        $svgLost = self::svgContentLost($result, $flat);
        $layoutMisrecognition = self::layoutDirectionMisrecognition($sourceHtml, $columnsVerifier);

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
        foreach ( $richTextRisk as $finding ) {
            $findings[] = $finding;
        }
        foreach ( $svgLost as $finding ) {
            $findings[] = $finding;
        }
        foreach ( $layoutMisrecognition as $finding ) {
            $findings[] = $finding;
        }
        foreach ( self::emptyCoreHtml($flat) as $finding ) {
            $findings[] = $finding;
        }
        foreach ( self::coreHtmlFallback($flat) as $finding ) {
            $findings[] = $finding;
        }

        $metrics = array(
            'block_count'                 => $native['total'],
            'native_count'                => $native['native'],
            'core_html_count'             => $native['html'],
            'freeform_count'              => $native['freeform'],
            'native_rate'                 => $native['rate'],
            'var_ref_count'               => $varReport['count'],
            'var_custom_ref_count'        => $varReport['custom_count'],
            // Structural serialization round-trip count. Kept for transparency,
            // but it is NOT the editor-invalidity signal — see the RichText risk
            // metric below.
            'invalid_block_count'         => $validityReport['invalid_block_count'],
            // The authoritative "the editor would flag this as invalid/unexpected
            // content" signal: blocks whose RichText content carries a
            // class/style-bearing inline <span>/<a> that RichText will not
            // preserve on parse.
            'richtext_invalid_risk_count' => count($richTextRisk),
            'svg_content_lost_count'      => count($svgLost),
            'layout_direction_misrecognition_count' => count($layoutMisrecognition),
        );

        return array(
            'metrics'   => $metrics,
            'findings'  => $findings,
            'var_names' => $varReport['names'],
        );
    }

    /**
     * Map a repair lane to its severity tier.
     */
    public static function severityForBucket(string $bucket): string
    {
        if ( in_array($bucket, self::HIGH_SEVERITY_BUCKETS, true) ) {
            return self::SEVERITY_HIGH;
        }
        if ( in_array($bucket, self::INFO_BUCKETS, true) ) {
            return self::SEVERITY_INFO;
        }

        return self::SEVERITY_MEDIUM;
    }

    /**
     * Numeric rank for a severity label (higher = more important).
     */
    public static function severityRank(string $severity): int
    {
        return self::SEVERITY_RANK[$severity] ?? self::SEVERITY_RANK[self::SEVERITY_MEDIUM];
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
     * var(--x) references in the emitted block markup.
     *
     * These references are materialized downstream (the SSI compile layer
     * resolves them end-to-end), so a high density of resolved var() references
     * is NOT a repair gap — it is working behavior. The findings are therefore
     * labeled informational (`informational_var_density`) and tracked for
     * visibility, kept out of the actionable defect worklist. WordPress preset
     * properties (var(--wp--...)) are excluded from the findings entirely.
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
            if ( '' !== $haystack && preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $haystack, $matches) ) {
                foreach ( $matches[1] as $name ) {
                    ++$total;
                    $occurrences[$name] = ($occurrences[$name] ?? 0) + 1;
                }
            }

            foreach ( self::presetVarNamesFromAttrs($block) as $name ) {
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
                'repair_bucket' => 'informational_var_density',
                'severity'      => self::SEVERITY_INFO,
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
     * RichText editor-invalidity risk: paragraph/heading/list-item blocks whose
     * RichText `content` carries an inline <span> or <a> that bears a class or
     * style attribute. RichText normalizes such content on parse — it strips the
     * unsupported class/style off inline formats it does not model — so the
     * editor shows "unexpected/invalid content" even though the structural
     * serialization round-trip (wp_block_validity) reports the block as valid.
     *
     * This is the authoritative editor-invalid-risk signal, ranked HIGH. The
     * structural `invalid_block_count` of 0 does NOT mean there is no invalid
     * content.
     *
     * @param array<int, array<string, mixed>> $flat Flattened block list.
     * @return array<int, array<string, mixed>>
     */
    public static function richTextInvalidRisk(array $flat): array
    {
        $richTextBlocks = array( 'core/paragraph', 'core/heading', 'core/list-item' );

        $findings = array();
        foreach ( $flat as $block ) {
            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            if ( ! in_array($name, $richTextBlocks, true) ) {
                continue;
            }
            $content = self::richTextContent($block);
            if ( '' === $content ) {
                continue;
            }
            if ( preg_match('/<(?:span|a)\b[^>]*\s(?:class|style)\s*=/i', $content) ) {
                $findings[] = array(
                    'source'        => 'detector',
                    'detector'      => 'richtext_invalid_risk',
                    'repair_bucket' => 'richtext_invalid_content_risk',
                    'severity'      => self::SEVERITY_HIGH,
                    'pattern'       => $name,
                    'count'         => 1,
                );
            }
        }

        return $findings;
    }

    /**
     * SVG-loss detector (HIGH severity): the recurring missing-image signal.
     *
     * Two complementary sources are routed into one `svg_content_lost` lane:
     *   1. Transformer inline-SVG fallback diagnostics — an <svg> whose artwork
     *      was dropped (no drawable/safe content left) rather than preserved.
     *   2. core/html blocks that are empty or whitespace+HTML-comments-only yet
     *      whose raw content still bears an SVG remnant/marker (the image was
     *      stripped, leaving a dead block).
     *
     * A core/html that PRESERVES an <svg> with real shape elements
     * (path/circle/rect/...) is acceptable and is NOT flagged here.
     *
     * @param array<string, mixed>             $result Canonical transformer result array.
     * @param array<int, array<string, mixed>> $flat   Flattened block list.
     * @return array<int, array<string, mixed>>
     */
    public static function svgContentLost(array $result, array $flat): array
    {
        $findings = array();

        $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : array();
        foreach ( $diagnostics as $diagnostic ) {
            if ( ! is_array($diagnostic) ) {
                continue;
            }
            $code = (string) ($diagnostic['reason_code'] ?? $diagnostic['code'] ?? '');
            if ( ! in_array($code, self::SVG_LOSS_REASON_CODES, true) ) {
                continue;
            }
            $findings[] = array(
                'source'        => 'detector',
                'detector'      => 'svg_content_lost',
                'repair_bucket' => 'svg_content_lost',
                'severity'      => self::SEVERITY_HIGH,
                'pattern'       => 'html_unsafe_inline_svg' === $code ? 'unsafe_inline_svg_dropped' : 'inline_svg_dropped',
                'count'         => 1,
            );
        }

        foreach ( $flat as $block ) {
            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            if ( 'core/html' !== $name ) {
                continue;
            }
            $content = self::rawContent($block);
            if ( ! self::looksLikeSvgSource($content) ) {
                continue;
            }
            if ( self::svgHasPreservedShapes($content) ) {
                // SVG preserved as core/html with real shape elements — acceptable.
                continue;
            }
            $stripped = trim(preg_replace('/<!--.*?-->/s', '', $content) ?? '');
            if ( '' !== $stripped ) {
                continue;
            }
            $findings[] = array(
                'source'        => 'detector',
                'detector'      => 'svg_content_lost',
                'repair_bucket' => 'svg_content_lost',
                'severity'      => self::SEVERITY_HIGH,
                'pattern'       => 'empty_core_html_from_svg',
                'count'         => 1,
            );
        }

        return $findings;
    }

    /**
     * Layout-direction faithfulness (HIGH severity): a vertical stack
     * (display:flex; flex-direction:column / column-reverse) emitted as a
     * horizontal core/columns block is a misrecognition — the content renders in
     * the wrong direction.
     *
     * Detection is conservative: it only inspects source container elements
     * (div/section/article/...) whose inline style explicitly declares a column
     * flex direction and that hold two or more element children. Genuine
     * horizontal flex (row / default) and grid layouts are never matched, so
     * faithful horizontal columns are not flagged. When a verifier callback is
     * supplied, each candidate is confirmed to actually convert to a top-level
     * core/columns block before it is reported, eliminating cases the transformer
     * routes to core/group or core/list instead.
     *
     * @param string                       $sourceHtml Original source HTML for the document.
     * @param callable(string): bool|null  $verifier   Optional confirmation predicate over an element fragment.
     * @return array<int, array<string, mixed>>
     */
    public static function layoutDirectionMisrecognition(string $sourceHtml, ?callable $verifier = null): array
    {
        if ( '' === trim($sourceHtml) ) {
            return array();
        }

        $containerTags = array( 'div', 'section', 'article', 'aside', 'main', 'header', 'footer', 'nav' );

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadHTML('<?xml encoding="utf-8"?>' . $sourceHtml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ( ! $loaded ) {
            return array();
        }

        $findings = array();
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//*[@style]');
        if ( false === $nodes ) {
            return array();
        }

        foreach ( $nodes as $node ) {
            if ( ! $node instanceof \DOMElement ) {
                continue;
            }
            if ( ! in_array(strtolower($node->tagName), $containerTags, true) ) {
                continue;
            }
            $style = strtolower($node->getAttribute('style'));
            if ( ! preg_match('/(?:^|;)\s*display\s*:\s*(?:inline-)?flex\b/', $style) ) {
                continue;
            }
            if ( ! preg_match('/flex-direction\s*:\s*column(?:-reverse)?\b/', $style) ) {
                continue;
            }
            $elementChildren = 0;
            foreach ( $node->childNodes as $child ) {
                if ( $child instanceof \DOMElement ) {
                    ++$elementChildren;
                }
            }
            if ( $elementChildren < 2 ) {
                continue;
            }
            if ( null !== $verifier ) {
                $fragment = $doc->saveHTML($node);
                if ( ! is_string($fragment) || ! $verifier($fragment) ) {
                    continue;
                }
            }
            $findings[] = array(
                'source'        => 'detector',
                'detector'      => 'layout_direction_misrecognition',
                'repair_bucket' => 'layout_direction_misrecognition',
                'severity'      => self::SEVERITY_HIGH,
                'pattern'       => 'columns_from_vertical_flex',
                'count'         => 1,
            );
        }

        return $findings;
    }

    /**
     * core/html blocks whose content is only whitespace and/or HTML comments —
     * dead blocks. SVG-sourced empties are excluded here because they are the
     * higher-severity svg_content_lost signal.
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
            if ( self::looksLikeSvgSource($content) ) {
                // Reported by svgContentLost (HIGH) instead of as a generic empty.
                continue;
            }
            $findings[] = array(
                'source'        => 'detector',
                'detector'      => 'empty_core_html',
                'repair_bucket' => 'drop_empty_html_block',
                'severity'      => self::SEVERITY_MEDIUM,
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
                'severity'      => self::SEVERITY_MEDIUM,
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
                'severity'      => self::SEVERITY_HIGH,
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
     * findings (no_repair_needed) are dropped from the worklist, and inline-SVG
     * loss diagnostics are routed to the dedicated svg_content_lost detector.
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
            $reasonCode = (string) ($diagnostic['reason_code'] ?? $diagnostic['code'] ?? '');
            if ( in_array($reasonCode, self::SVG_LOSS_REASON_CODES, true) ) {
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
                'severity'      => self::severityForBucket($repairBucket),
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
     * Whether the raw content of a core/html block carries an SVG remnant/marker
     * — either a literal <svg ...> tag or an HTML comment that names svg (the
     * trace left when SVG artwork is stripped out of a wrapper block).
     */
    private static function looksLikeSvgSource(string $content): bool
    {
        if ( preg_match('/<\s*svg\b/i', $content) ) {
            return true;
        }

        return (bool) preg_match('/<!--[^>]*\bsvg\b[^>]*-->/i', $content);
    }

    /**
     * Whether SVG content was preserved with real, renderable shape elements
     * (as opposed to an empty/stripped shell).
     */
    private static function svgHasPreservedShapes(string $content): bool
    {
        $stripped = preg_replace('/<!--.*?-->/s', '', $content) ?? $content;

        return (bool) preg_match(
            '/<\s*(?:path|circle|rect|polygon|polyline|line|ellipse|g|use|text|image|symbol|defs|tspan)\b/i',
            $stripped
        );
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
     * Native preset color attrs are the valid form of CSS preset vars, so they no
     * longer appear in innerHTML. Keep them in var_names for corpus visibility.
     *
     * @param array<string, mixed> $block
     * @return array<int, string>
     */
    private static function presetVarNamesFromAttrs(array $block): array
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $names = array();
        foreach ( array( 'textColor', 'backgroundColor' ) as $attrName ) {
            $slug = is_string($attrs[ $attrName ] ?? null) ? strtolower(trim($attrs[ $attrName ])) : '';
            if ( '' !== $slug && preg_match('/^[a-z0-9_-]+$/', $slug) ) {
                $names[] = '--wp--preset--color--' . $slug;
            }
        }

        return $names;
    }

    /**
     * RichText content for a paragraph/heading/list-item block: the explicit
     * content attribute, falling back to saved innerHTML.
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
