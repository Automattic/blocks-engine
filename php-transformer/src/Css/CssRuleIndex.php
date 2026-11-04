<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Immutable ordered rule streams. Scope retention never changes the shared analysis. */
final class CssRuleIndex
{
    /** @param list<list<array<string, mixed>>> $streams */
    public function __construct(private readonly array $streams)
    {
    }

    public function entries(): int
    {
        return array_sum(array_map('count', $this->streams));
    }

    /**
     * Apply a consumer's scope and budgets, preserving source block exits,
     * diagnostics and order even when a retained-rule limit is reached.
     *
     * @param callable(array<string, mixed>, string): bool|null $retainSelector
     * @return array{rules: list<array<string, mixed>>, diagnostics: list<string>, truncated: bool}
     */
    public function query(int $maxRules, int $maxSelectors, ?callable $retainSelector = null, ?int $maxScannedSelectors = null): array
    {
        $result = array('rules' => array(), 'diagnostics' => array(), 'truncated' => false);
        $counters = array('order' => 0, 'retained' => 0, 'scanned' => 0, 'max_scanned' => $maxScannedSelectors ?? $maxSelectors);
        foreach ( $this->streams as $stream ) {
            if ( ! $this->replay($stream, $maxRules, $maxSelectors, $retainSelector, $result, $counters) ) {
                break;
            }
        }
        $result['diagnostics'] = array_values(array_unique($result['diagnostics']));
        return $result;
    }

    /**
     * @param list<array<string, mixed>> $stream
     * @param array{rules: list<array<string, mixed>>, diagnostics: list<string>, truncated: bool} $result
     * @param array{order: int, retained: int, scanned: int, max_scanned: int} $counters
     */
    private function replay(array $stream, int $maxRules, int $maxSelectors, ?callable $retainSelector, array &$result, array &$counters): bool
    {
        for ( $index = 0, $count = count($stream); $index < $count; ) {
            $entry = $stream[$index];
            if ( isset($entry['diagnostic']) ) {
                $result['diagnostics'][] = $entry['diagnostic'];
                $result['truncated'] = $result['truncated'] || $entry['truncated'];
                ++$index;
                continue;
            }
            if ( ++$counters['scanned'] > $counters['max_scanned'] ) {
                $result['truncated'] = true;
                $result['diagnostics'][] = 'css_selector_scan_limit';
                return false;
            }
            if ( null !== $retainSelector && ! $retainSelector($entry['parsed_selector'], $entry['selector']) ) {
                ++$index;
                continue;
            }
            if ( $counters['retained'] >= $maxSelectors || count($result['rules']) >= $maxRules ) {
                $result['truncated'] = true;
                $result['diagnostics'][] = 'css_rule_or_selector_limit';
                $index = $entry['block_end'];
                continue;
            }
            ++$counters['retained'];
            $result['rules'][] = array(
                'selector' => $entry['selector'],
                'parsed_selector' => $entry['parsed_selector'],
                'declarations' => $entry['declarations'],
                'condition' => $entry['condition'],
                'layer' => $entry['layer'],
                'path' => $entry['path'],
                'hash' => $entry['hash'],
                'order' => $counters['order']++,
                'specificity' => $entry['specificity'],
            );
            ++$index;
        }
        return true;
    }
}
