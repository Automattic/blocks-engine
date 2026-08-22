<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/**
 * Bounded cache for immutable stylesheet analysis shared by one site compile.
 */
final class HtmlTransformerAnalysisCache
{
    // Payload analyses contain parsed selector graphs, so keep the site-scoped
    // cache small even when every route contributes a local stylesheet.
    private const MAX_PAYLOAD_ENTRIES = 16;

    private const MAX_PAYLOAD_BYTES = 1048576;

    /** @var array<string, array{static: array, conditional: array, navigation_state: array, image_shape: array, pseudo: array, custom_properties: array}> */
    public array $styles = array();

    public int $styleBuilds = 0;

    public int $styleHits = 0;

    public int $styleEvictions = 0;

    public int $styleBytes = 0;

    public int $styleEvictedBytes = 0;

    /** @var array<string, array{source_tags: array<string, bool>, selectors: list<array{selector: string, parsed: array<string, mixed>}>, rules: list<array<string, mixed>}>} */
    public array $authorSelectorAnalyses = array();

    public int $authorSelectorBuilds = 0;

    public int $authorSelectorHits = 0;

    public int $authorSelectorEvictions = 0;

    public int $authorSelectorBytes = 0;

    public int $authorSelectorEvictedBytes = 0;

    public int $authorSelectorClassTokenBuilds = 0;

    public int $authorSelectorClassTokenHits = 0;

    public int $authorSelectorAttributeReads = 0;

    public int $authorSelectorMatchResultBuilds = 0;

    public int $authorSelectorMatchResultHits = 0;

    public int $authorStyleRuleBuilds = 0;

    public int $sourceSelectorMatchExecutions = 0;

    public int $sourceSelectorMatchHits = 0;

    public int $sourceSelectorClassTokenBuilds = 0;

    public int $sourceSelectorClassTokenHits = 0;

    public int $sourceSelectorAttributeReads = 0;

    public int $sourceStyleCandidateRuleChecks = 0;

    public int $sourceStyleCandidateRulesSkipped = 0;

    /** @param array{static: array, conditional: array, navigation_state: array, image_shape: array, pseudo: array, custom_properties: array} $analysis */
    public function rememberStyle(string $key, array $analysis): void
    {
        $bytes = $this->analysisBytes($analysis);
        if ( $bytes > self::MAX_PAYLOAD_BYTES ) {
            ++$this->styleEvictions;
            $this->styleEvictedBytes += $bytes;
            return;
        }
        while ( count($this->styles) >= self::MAX_PAYLOAD_ENTRIES || $this->styleBytes + $bytes > self::MAX_PAYLOAD_BYTES ) {
            $evicted = array_shift($this->styles);
            ++$this->styleEvictions;
            $this->styleBytes -= $this->analysisBytes($evicted);
            $this->styleEvictedBytes += $this->analysisBytes($evicted);
        }
        $this->styles[$key] = $analysis;
        $this->styleBytes += $bytes;
    }

    public function style(string $key): ?array
    {
        if ( ! isset($this->styles[$key]) ) {
            return null;
        }
        $analysis = $this->styles[$key];
        unset($this->styles[$key]);
        $this->styles[$key] = $analysis;

        return $analysis;
    }

    /** @param array{source_tags: array<string, bool>, selectors: list<array{selector: string, parsed: array<string, mixed>}>, rules: list<array<string, mixed>>} $analysis */
    public function rememberAuthorSelectors(string $key, array $analysis): void
    {
        $bytes = $this->analysisBytes($analysis);
        if ( $bytes > self::MAX_PAYLOAD_BYTES ) {
            ++$this->authorSelectorEvictions;
            $this->authorSelectorEvictedBytes += $bytes;
            return;
        }
        while ( count($this->authorSelectorAnalyses) >= self::MAX_PAYLOAD_ENTRIES || $this->authorSelectorBytes + $bytes > self::MAX_PAYLOAD_BYTES ) {
            $evicted = array_shift($this->authorSelectorAnalyses);
            ++$this->authorSelectorEvictions;
            $this->authorSelectorBytes -= $this->analysisBytes($evicted);
            $this->authorSelectorEvictedBytes += $this->analysisBytes($evicted);
        }
        $this->authorSelectorAnalyses[$key] = $analysis;
        $this->authorSelectorBytes += $bytes;
    }

    public function authorSelectors(string $key): ?array
    {
        if ( ! isset($this->authorSelectorAnalyses[$key]) ) {
            return null;
        }
        $analysis = $this->authorSelectorAnalyses[$key];
        unset($this->authorSelectorAnalyses[$key]);
        $this->authorSelectorAnalyses[$key] = $analysis;

        return $analysis;
    }

    private function analysisBytes(array $analysis): int
    {
        return strlen(serialize($analysis));
    }
}
