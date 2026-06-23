<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\VisualParity;

final class ButtonMenuVisualProbeComparator
{
    public const SCHEMA = 'blocks-engine/php-transformer/visual-parity-probe-comparison/v1';

    private const STYLE_GROUPS = array(
        'background' => array('background-color'),
        'border' => array(
            'border',
            'border-bottom-color',
            'border-bottom-style',
            'border-bottom-width',
            'border-color',
            'border-left-color',
            'border-left-style',
            'border-left-width',
            'border-right-color',
            'border-right-style',
            'border-right-width',
            'border-style',
            'border-top-color',
            'border-top-style',
            'border-top-width',
            'border-width',
        ),
        'radius' => array(
            'border-bottom-left-radius',
            'border-bottom-right-radius',
            'border-radius',
            'border-top-left-radius',
            'border-top-right-radius',
        ),
        'padding' => array('padding', 'padding-bottom', 'padding-left', 'padding-right', 'padding-top'),
    );

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    public function compare(array $source, array $target): array
    {
        $sourceProbes = $this->probes($source);
        $targetProbes = $this->probes($target);
        $unmatchedTargetIndexes = array_fill_keys(array_keys($targetProbes), true);
        $matches = array();
        $unmatchedSource = array();

        foreach ( $sourceProbes as $sourceIndex => $sourceProbe ) {
            $targetIndex = $this->bestTargetIndex($sourceProbe, $targetProbes, $unmatchedTargetIndexes);
            if ( null === $targetIndex ) {
                $unmatchedSource[] = $this->candidateSummary($sourceProbe);
                continue;
            }

            unset($unmatchedTargetIndexes[$targetIndex]);
            $targetProbe = $targetProbes[$targetIndex];
            $matches[] = array_filter(array(
                'source' => $this->candidateSummary($sourceProbe, $sourceIndex),
                'target' => $this->candidateSummary($targetProbe, $targetIndex),
                'style_deltas' => $this->styleDeltas($sourceProbe, $targetProbe),
                'missing_style_risks' => $this->missingStyleRisks($sourceProbe, $targetProbe),
            ), static fn ($value): bool => array() !== $value);
        }

        $unmatchedTarget = array();
        foreach ( array_keys($unmatchedTargetIndexes) as $targetIndex ) {
            $unmatchedTarget[] = $this->candidateSummary($targetProbes[$targetIndex], $targetIndex);
        }

        $styleDeltaCount = 0;
        $missingStyleRiskCount = 0;
        foreach ( $matches as $match ) {
            $styleDeltaCount += count($match['style_deltas'] ?? array());
            $missingStyleRiskCount += count($match['missing_style_risks'] ?? array());
        }

        return array(
            'schema' => self::SCHEMA,
            'status' => 'success',
            'summary' => array(
                'source_total' => count($sourceProbes),
                'target_total' => count($targetProbes),
                'matched_total' => count($matches),
                'unmatched_source_total' => count($unmatchedSource),
                'unmatched_target_total' => count($unmatchedTarget),
                'style_delta_total' => $styleDeltaCount,
                'missing_style_risk_total' => $missingStyleRiskCount,
            ),
            'matches' => $matches,
            'unmatched_source' => $unmatchedSource,
            'unmatched_target' => $unmatchedTarget,
            'diagnostics' => array(),
        );
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
     * @param array<int, bool> $availableIndexes
     */
    private function bestTargetIndex(array $sourceProbe, array $targetProbes, array $availableIndexes): ?int
    {
        $bestIndex = null;
        $bestScore = 0;
        foreach ( array_keys($availableIndexes) as $index ) {
            $score = $this->matchScore($sourceProbe, $targetProbes[$index]);
            if ( $score > $bestScore ) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestScore >= 8 ? $bestIndex : null;
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @param array<string, mixed> $targetProbe
     */
    private function matchScore(array $sourceProbe, array $targetProbe): int
    {
        $score = 0;
        if ( $this->normalizedText($sourceProbe) !== '' && $this->normalizedText($sourceProbe) === $this->normalizedText($targetProbe) ) {
            $score += 5;
        }
        if ( $this->href($sourceProbe) !== '' && $this->href($sourceProbe) === $this->href($targetProbe) ) {
            $score += 5;
        }
        if ( $this->compatibleKind((string) ($sourceProbe['kind'] ?? ''), (string) ($targetProbe['kind'] ?? '')) ) {
            $score += 3;
        }

        return $score;
    }

    private function compatibleKind(string $sourceKind, string $targetKind): bool
    {
        if ( $sourceKind === $targetKind ) {
            return true;
        }

        $interactive = array('button', 'cta', 'menu_button');
        return in_array($sourceKind, $interactive, true) && in_array($targetKind, $interactive, true);
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @param array<string, mixed> $targetProbe
     * @return array<int, array<string, mixed>>
     */
    private function styleDeltas(array $sourceProbe, array $targetProbe): array
    {
        $deltas = array();
        $sourceStyle = $this->style($sourceProbe);
        $targetStyle = $this->style($targetProbe);

        foreach ( self::STYLE_GROUPS as $group => $fields ) {
            $sourceValues = $this->groupValues($sourceStyle, $fields);
            $targetValues = $this->groupValues($targetStyle, $fields);
            if ( array() === $sourceValues || $sourceValues === $targetValues ) {
                continue;
            }

            $deltas[] = array_filter(array(
                'group' => $group,
                'source' => $sourceValues,
                'target' => $targetValues,
            ), static fn ($value): bool => array() !== $value);
        }

        return $deltas;
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @param array<string, mixed> $targetProbe
     * @return array<int, array<string, mixed>>
     */
    private function missingStyleRisks(array $sourceProbe, array $targetProbe): array
    {
        if ( ! $this->isCtaLike($sourceProbe) ) {
            return array();
        }

        $risks = array();
        $sourceStyle = $this->style($sourceProbe);
        $targetStyle = $this->style($targetProbe);
        foreach ( self::STYLE_GROUPS as $group => $fields ) {
            if ( array() === $this->groupValues($sourceStyle, $fields) ) {
                continue;
            }
            if ( array() === $this->groupValues($targetStyle, $fields) ) {
                $risks[] = array(
                    'code' => 'missing_' . $group . '_style',
                    'group' => $group,
                    'source' => $this->groupValues($sourceStyle, $fields),
                );
            }
        }

        if ( in_array('default-button-style-watch', $this->signals($targetProbe), true) && array() !== $this->sourceVisualStyleGroups($sourceStyle) ) {
            array_unshift($risks, array(
                'code' => 'target_default_button_style',
                'signal' => 'default-button-style-watch',
                'source_style_groups' => $this->sourceVisualStyleGroups($sourceStyle),
            ));
        }

        return $risks;
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
     * @param array<int, string> $fields
     * @return array<string, string>
     */
    private function groupValues(array $style, array $fields): array
    {
        $values = array_intersect_key($style, array_flip($fields));
        ksort($values);
        return array_filter($values, static fn (string $value): bool => '' !== trim($value));
    }

    /**
     * @param array<string, string> $style
     * @return array<int, string>
     */
    private function sourceVisualStyleGroups(array $style): array
    {
        $groups = array();
        foreach ( self::STYLE_GROUPS as $group => $fields ) {
            if ( array() !== $this->groupValues($style, $fields) ) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /** @param array<string, mixed> $probe */
    private function isCtaLike(array $probe): bool
    {
        return in_array((string) ($probe['kind'] ?? ''), array('button', 'cta', 'menu_button'), true) || in_array('cta-signal', $this->signals($probe), true);
    }

    /**
     * @param array<string, mixed> $probe
     * @return array<int, string>
     */
    private function signals(array $probe): array
    {
        $signals = $probe['signals'] ?? array();
        return is_array($signals) ? array_values(array_filter($signals, 'is_string')) : array();
    }

    /**
     * @param array<string, mixed> $probe
     * @return array<string, mixed>
     */
    private function candidateSummary(array $probe, ?int $index = null): array
    {
        return array_filter(array(
            'index' => $index,
            'id' => $probe['id'] ?? null,
            'kind' => $probe['kind'] ?? null,
            'text' => $probe['text'] ?? null,
            'href' => $probe['href'] ?? null,
            'selector' => $probe['selector'] ?? null,
            'signals' => $probe['signals'] ?? array(),
        ), static fn ($value): bool => null !== $value && array() !== $value && '' !== $value);
    }

    /** @param array<string, mixed> $probe */
    private function normalizedText(array $probe): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) ($probe['text'] ?? '')) ?? ''));
    }

    /** @param array<string, mixed> $probe */
    private function href(array $probe): string
    {
        return trim((string) ($probe['href'] ?? ''));
    }
}
