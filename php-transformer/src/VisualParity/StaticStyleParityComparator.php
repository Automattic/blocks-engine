<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\VisualParity;

use Automattic\BlocksEngine\PhpTransformer\Contract\VisualParityReportContract;

/**
 * Deterministic, render-free visual-parity comparator.
 *
 * Compares the {@see StaticStyleParityProbe} fingerprints of a SOURCE document
 * and a transformed CANDIDATE document and emits a
 * {@see VisualParityReportContract::REPORT_SCHEMA} report carrying:
 *
 *  - a stable parity SCORE (property agreement × element coverage, 0..1),
 *  - per-element MATCHES with the matched selector pair and confidence,
 *  - per-element / per-PROPERTY findings naming exactly which CSS property on
 *    which selector diverges (far more actionable than a pixel ratio).
 *
 * Element correspondence is solved deterministically (never by pixels). Because
 * the transformer preserves source classes (class -> className) and semantic
 * tags, source and candidate elements are matched by stable identity in a fixed
 * tier order, consuming the first still-unmatched candidate in document order:
 *
 *   1. selector   — identical stable selector path (tag + id/classes + nth-of-type)
 *   2. class+text — same tag, same sorted class set, same trimmed text
 *   3. class      — same tag, same sorted class set
 *   4. structural — same class-free structural path (tag + nth-of-type chain)
 *
 * Same inputs -> byte-identical report on every run. No screenshots, no
 * dimensions, no scroll/animation flakiness, no OOM.
 */
final class StaticStyleParityComparator
{
    public const DEFAULT_WARN_AT = 0.98;
    public const DEFAULT_FAIL_AT = 0.85;

    /**
     * Match tiers in priority order. Each maps a tier id to a confidence weight.
     */
    private const TIERS = array(
        'selector' => 1.0,
        'class+text' => 0.9,
        'class' => 0.75,
        'structural' => 0.6,
    );

    private float $warnAt;
    private float $failAt;

    public function __construct(float $warnAt = self::DEFAULT_WARN_AT, float $failAt = self::DEFAULT_FAIL_AT)
    {
        $this->warnAt = $warnAt;
        $this->failAt = $failAt;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    public function compare(array $source, array $target): array
    {
        $sourceProbes = $this->probes($source);
        $targetProbes = $this->probes($target);
        $available = array_fill_keys(array_keys($targetProbes), true);

        $matches = array();
        $findings = array();
        $recommendations = array();
        $unmatchedSource = array();

        $comparedProperties = 0;
        $agreedProperties = 0;

        foreach ( $sourceProbes as $sourceProbe ) {
            [$targetIndex, $tier] = $this->bestTargetIndex($sourceProbe, $targetProbes, $available);
            if ( null === $targetIndex ) {
                $unmatchedSource[] = $this->candidateSummary($sourceProbe);
                $finding = $this->missingElementFinding($sourceProbe, count($findings) + 1);
                $recommendation = $this->recommendationFor($finding, count($recommendations) + 1);
                $finding['recommendation_ids'] = array($recommendation['id']);
                $findings[] = $finding;
                $recommendations[] = $recommendation;
                continue;
            }

            unset($available[$targetIndex]);
            $targetProbe = $targetProbes[$targetIndex];

            $sourceStyle = $this->style($sourceProbe);
            $deltas = array();
            foreach ( self::sortedKeys($sourceStyle) as $property ) {
                $sourceValue = $this->normalizeValue($property, (string) $sourceStyle[$property]);
                if ( '' === $sourceValue ) {
                    continue;
                }
                ++$comparedProperties;
                $targetRaw = (string) ($this->style($targetProbe)[$property] ?? '');
                $targetValue = $this->normalizeValue($property, $targetRaw);
                if ( $sourceValue === $targetValue ) {
                    ++$agreedProperties;
                    continue;
                }
                $deltas[] = array(
                    'property' => $property,
                    'source' => (string) $sourceStyle[$property],
                    'target' => $targetRaw,
                );
            }

            $matches[] = array(
                'kind' => 'generic',
                'source_selector' => $this->selector($sourceProbe),
                'target_selector' => $this->selector($targetProbe),
                'confidence' => self::TIERS[$tier],
                'match_tier' => $tier,
                'style_deltas' => $deltas,
            );

            foreach ( $deltas as $delta ) {
                $finding = $this->deltaFinding($sourceProbe, $delta, count($findings) + 1);
                $recommendation = $this->recommendationFor($finding, count($recommendations) + 1);
                $finding['recommendation_ids'] = array($recommendation['id']);
                $findings[] = $finding;
                $recommendations[] = $recommendation;
            }
        }

        $sourceTotal = count($sourceProbes);
        $matchedTotal = count($matches);
        $propertyParity = $comparedProperties > 0 ? $agreedProperties / $comparedProperties : 1.0;
        $coverage = $sourceTotal > 0 ? $matchedTotal / $sourceTotal : 1.0;
        $score = round($propertyParity * $coverage, 4);

        $status = $this->status($score, array() === $findings);
        $severity = $this->severity($status);

        $report = array(
            'schema' => VisualParityReportContract::REPORT_SCHEMA,
            'status' => $status,
            'severity' => $severity,
            'source_render' => array('kind' => 'source', 'renderer' => 'php-transformer-static'),
            'target_render' => array('kind' => 'target', 'renderer' => 'php-transformer-static'),
            'viewports' => array(),
            'matches' => $matches,
            'findings' => $findings,
            'recommendations' => $recommendations,
            'parity' => array(
                'score' => $score,
                'property_parity' => round($propertyParity, 4),
                'coverage' => round($coverage, 4),
                'warn_at' => $this->warnAt,
                'fail_at' => $this->failAt,
            ),
            'summary' => array(
                'source_total' => $sourceTotal,
                'target_total' => count($targetProbes),
                'matched_total' => $matchedTotal,
                'unmatched_source_total' => count($unmatchedSource),
                'compared_properties' => $comparedProperties,
                'agreed_properties' => $agreedProperties,
                'finding_total' => count($findings),
            ),
            'unmatched_source' => $unmatchedSource,
            'diagnostics' => array(),
        );

        return $report;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<int, array<string, mixed>>
     */
    private function probes(array $result): array
    {
        $probes = $result['probes'] ?? array();
        return is_array($probes) ? array_values(array_filter($probes, 'is_array')) : array();
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @param array<int, array<string, mixed>> $targetProbes
     * @param array<int, bool> $available
     * @return array{0: int|null, 1: string}
     */
    private function bestTargetIndex(array $sourceProbe, array $targetProbes, array $available): array
    {
        foreach ( array_keys(self::TIERS) as $tier ) {
            foreach ( array_keys($available) as $index ) {
                if ( $this->tierMatches($tier, $sourceProbe, $targetProbes[$index]) ) {
                    return array($index, $tier);
                }
            }
        }

        return array(null, '');
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     */
    private function tierMatches(string $tier, array $source, array $target): bool
    {
        switch ( $tier ) {
            case 'selector':
                return '' !== $this->selector($source) && $this->selector($source) === $this->selector($target);
            case 'class+text':
                return $this->tag($source) === $this->tag($target)
                    && $this->classes($source) === $this->classes($target)
                    && '' !== $this->text($source)
                    && $this->text($source) === $this->text($target);
            case 'class':
                return $this->tag($source) === $this->tag($target)
                    && array() !== $this->classes($source)
                    && $this->classes($source) === $this->classes($target);
            case 'structural':
                return '' !== $this->structuralPath($source) && $this->structuralPath($source) === $this->structuralPath($target);
            default:
                return false;
        }
    }

    private function status(float $score, bool $noFindings): string
    {
        if ( $noFindings && $score >= 1.0 ) {
            return 'pass';
        }
        if ( $score < $this->failAt ) {
            return 'fail';
        }
        if ( $score < $this->warnAt ) {
            return 'warning';
        }

        return $noFindings ? 'pass' : 'warning';
    }

    private function severity(string $status): string
    {
        return array(
            'pass' => 'none',
            'warning' => 'warning',
            'fail' => 'error',
        )[$status] ?? 'none';
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @param array{property: string, source: string, target: string} $delta
     * @return array<string, mixed>
     */
    private function deltaFinding(array $sourceProbe, array $delta, int $sequence): array
    {
        $target = '' === $delta['target'] ? 'no declared value' : $delta['target'];

        return array(
            'id' => 'static-parity-' . $delta['property'] . '-' . $sequence,
            'severity' => 'warning',
            'category' => 'visual-style',
            'summary' => sprintf('%s %s changed from "%s" to "%s".', $this->selector($sourceProbe), $delta['property'], $delta['source'], $target),
            'kind' => 'generic',
            'property' => $delta['property'],
            'source_value' => $delta['source'],
            'target_value' => $delta['target'],
            'selector' => $this->selector($sourceProbe),
        );
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @return array<string, mixed>
     */
    private function missingElementFinding(array $sourceProbe, int $sequence): array
    {
        return array(
            'id' => 'static-parity-missing-' . $sequence,
            'severity' => 'warning',
            'category' => 'visual-style',
            'summary' => sprintf('Source element %s has no matching candidate element.', $this->selector($sourceProbe)),
            'kind' => 'generic',
            'property' => 'presence',
            'selector' => $this->selector($sourceProbe),
        );
    }

    /**
     * @param array<string, mixed> $finding
     * @return array<string, mixed>
     */
    private function recommendationFor(array $finding, int $sequence): array
    {
        $property = (string) ($finding['property'] ?? 'visual-style');
        $summary = 'presence' === $property
            ? 'Preserve the source element so candidate structure matches.'
            : sprintf('Align the candidate %s with the source value.', $property);

        return array(
            'id' => 'rec-static-parity-' . $sequence,
            'priority' => 'medium',
            'summary' => $summary,
            'finding_ids' => array((string) $finding['id']),
        );
    }

    /**
     * @param array<string, mixed> $probe
     * @return array<string, string>
     */
    private function style(array $probe): array
    {
        $style = $probe['style'] ?? array();
        return is_array($style) ? array_filter($style, 'is_string') : array();
    }

    /**
     * @param array<string, string> $style
     * @return array<int, string>
     */
    private static function sortedKeys(array $style): array
    {
        $keys = array_keys($style);
        sort($keys);
        return $keys;
    }

    private function normalizeValue(string $property, string $value): string
    {
        $value = strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
        if ( '' === $value ) {
            return '';
        }

        if ( 'font-family' === $property ) {
            $value = str_replace(array('"', "'"), '', $value);
            return preg_replace('/\s*,\s*/', ',', $value) ?? $value;
        }

        if ( 'font-weight' === $property ) {
            return array('normal' => '400', 'bold' => '700', 'lighter' => '300', 'bolder' => '700')[$value] ?? $value;
        }

        // Light, deterministic color normalization: drop spaces inside rgb()/rgba()
        // and lowercase hex so equivalent declarations compare equal.
        $value = preg_replace('/\s*,\s*/', ',', $value) ?? $value;
        $value = preg_replace('/\(\s+/', '(', $value) ?? $value;
        $value = preg_replace('/\s+\)/', ')', $value) ?? $value;

        return $value;
    }

    /** @param array<string, mixed> $probe */
    private function selector(array $probe): string
    {
        $selector = trim((string) ($probe['selector'] ?? ''));
        return '' !== $selector ? $selector : (string) ($probe['tag'] ?? '');
    }

    /** @param array<string, mixed> $probe */
    private function structuralPath(array $probe): string
    {
        return trim((string) ($probe['structural_path'] ?? ''));
    }

    /** @param array<string, mixed> $probe */
    private function tag(array $probe): string
    {
        return strtolower(trim((string) ($probe['tag'] ?? '')));
    }

    /**
     * @param array<string, mixed> $probe
     * @return array<int, string>
     */
    private function classes(array $probe): array
    {
        $classes = $probe['classes'] ?? array();
        if ( ! is_array($classes) ) {
            return array();
        }
        $classes = array_values(array_filter($classes, 'is_string'));
        sort($classes);
        return $classes;
    }

    /** @param array<string, mixed> $probe */
    private function text(array $probe): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) ($probe['text'] ?? '')) ?? ''));
    }

    /**
     * @param array<string, mixed> $probe
     * @return array<string, mixed>
     */
    private function candidateSummary(array $probe): array
    {
        return array_filter(array(
            'id' => $probe['id'] ?? null,
            'tag' => $probe['tag'] ?? null,
            'selector' => $probe['selector'] ?? null,
            'text' => $probe['text'] ?? null,
        ), static fn ($value): bool => null !== $value && '' !== $value);
    }
}
