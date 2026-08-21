<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/**
 * Bounded cache for immutable stylesheet analysis shared by one site compile.
 */
final class HtmlTransformerAnalysisCache
{
    // Full parsed CSS analyses can be large; eight covers shared site styles
    // without retaining an unbounded corpus of route-specific stylesheets.
    private const MAX_ENTRIES = 8;

    /** @var array<string, array{static: array, conditional: array, navigation_state: array, image_shape: array, pseudo: array, custom_properties: array}> */
    public array $styles = array();

    public int $styleBuilds = 0;

    public int $styleHits = 0;

    /** @var array<string, array{source_tags: array<string, bool>, selectors: list<array{selector: string, parsed: array<string, mixed>}>, rules: list<array<string, mixed>}>} */
    public array $authorSelectorAnalyses = array();

    public int $authorSelectorBuilds = 0;

    public int $authorSelectorHits = 0;

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
        if ( count($this->styles) >= self::MAX_ENTRIES ) {
            array_shift($this->styles);
        }
        $this->styles[$key] = $analysis;
    }

    /** @param array{source_tags: array<string, bool>, selectors: list<array{selector: string, parsed: array<string, mixed>}>, rules: list<array<string, mixed>>} $analysis */
    public function rememberAuthorSelectors(string $key, array $analysis): void
    {
        if ( count($this->authorSelectorAnalyses) >= self::MAX_ENTRIES ) {
            array_shift($this->authorSelectorAnalyses);
        }
        $this->authorSelectorAnalyses[$key] = $analysis;
    }
}
