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

    // One shared site stylesheet may legitimately exceed the route-local
    // budget. Retain a single bounded hot analysis instead of rebuilding it
    // for every page in the worker batch.
    private const MAX_OVERSIZED_PAYLOAD_BYTES = 16777216;

    private ?string $oversizedStyleKey = null;

    private ?string $oversizedAuthorSelectorKey = null;

    /** @var array<string, int> */
    private array $styleAnalysisBytes = array();

    /** @var array<string, int> */
    private array $authorSelectorAnalysisBytes = array();

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

    public int $sourceSelectorMatchMisses = 0;

    public int $sourceSelectorMatchEvictions = 0;

    public int $sourceSelectorMatchPeakEntries = 0;

    public int $sourceSelectorClassTokenBuilds = 0;

    public int $sourceSelectorClassTokenHits = 0;

    public int $sourceSelectorAttributeReads = 0;

    public int $sourceStyleCandidateRuleChecks = 0;

    public int $sourceStyleCandidateRulesSkipped = 0;

    public int $sourceStyleCandidateRuleHits = 0;

    public int $sourceStyleCandidateRuleMisses = 0;

    public int $sourceStyleCandidateRuleEvictions = 0;

    public int $sourceStyleCandidateRulePeakEntries = 0;

    public int $sourceStyleCandidateRulePeakRetained = 0;

    public int $sourceStructuralDeclarationBuilds = 0;

    public int $sourceStructuralDeclarationHits = 0;

    /** @param array{static: array, conditional: array, navigation_state: array, image_shape: array, pseudo: array, custom_properties: array} $analysis */
    public function rememberStyle(string $key, array $analysis): void
    {
        $this->remember(
            $this->styles,
            $this->styleAnalysisBytes,
            $this->styleBytes,
            $this->styleEvictions,
            $this->styleEvictedBytes,
            $this->oversizedStyleKey,
            $key,
            $analysis
        );
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
        $this->remember(
            $this->authorSelectorAnalyses,
            $this->authorSelectorAnalysisBytes,
            $this->authorSelectorBytes,
            $this->authorSelectorEvictions,
            $this->authorSelectorEvictedBytes,
            $this->oversizedAuthorSelectorKey,
            $key,
            $analysis
        );
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

    private function remember(
        array &$cache,
        array &$analysisBytes,
        int &$retainedBytes,
        int &$evictions,
        int &$evictedBytes,
        ?string &$oversizedKey,
        string $key,
        array $analysis
    ): void {
        $bytes = $this->analysisBytes($analysis);
        if ( $bytes > self::MAX_OVERSIZED_PAYLOAD_BYTES ) {
            ++$evictions;
            $evictedBytes += $bytes;
            return;
        }

        $isOversized = $bytes > self::MAX_PAYLOAD_BYTES;
        if ( $isOversized && null !== $oversizedKey ) {
            $this->evict($cache, $analysisBytes, $retainedBytes, $evictions, $evictedBytes, $oversizedKey);
            $oversizedKey = null;
        }

        while ( count($cache) >= self::MAX_PAYLOAD_ENTRIES || ( ! $isOversized && $this->regularBytes($analysisBytes, $retainedBytes, $oversizedKey) + $bytes > self::MAX_PAYLOAD_BYTES ) ) {
            $evictionKey = null;
            foreach ( $cache as $candidate => $_analysis ) {
                if ( $candidate !== $oversizedKey ) {
                    $evictionKey = $candidate;
                    break;
                }
            }
            if ( null === $evictionKey ) {
                break;
            }
            $this->evict($cache, $analysisBytes, $retainedBytes, $evictions, $evictedBytes, $evictionKey);
        }

        $cache[$key] = $analysis;
        $analysisBytes[$key] = $bytes;
        $retainedBytes += $bytes;
        if ( $isOversized ) {
            $oversizedKey = $key;
        }
    }

    /** @param array<string, int> $analysisBytes */
    private function regularBytes(array $analysisBytes, int $retainedBytes, ?string $oversizedKey): int
    {
        if ( null === $oversizedKey || ! isset($analysisBytes[$oversizedKey]) ) {
            return $retainedBytes;
        }

        return $retainedBytes - $analysisBytes[$oversizedKey];
    }

    /** @param array<string, int> $analysisBytes */
    private function evict(array &$cache, array &$analysisBytes, int &$retainedBytes, int &$evictions, int &$evictedBytes, string $key): void
    {
        $bytes = $analysisBytes[$key];
        unset($cache[$key]);
        unset($analysisBytes[$key]);
        ++$evictions;
        $retainedBytes -= $bytes;
        $evictedBytes += $bytes;
    }

    private function analysisBytes(array $analysis): int
    {
        return strlen(serialize($analysis));
    }
}
