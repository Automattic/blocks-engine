<?php
declare(strict_types=1);

/**
 * Unit tests for the corpus-diagnostics detectors.
 *
 * Plain-PHP test script in the style of tests/unit/css-value-splitter.php — no
 * PHPUnit. A single inline HTML document carries an inline classed/styled span
 * inside RichText and a custom var() reference, and the detectors must report
 * each while leaving the WordPress-preset var() out of the actionable worklist.
 * The empty (stripped-SVG) core/html detector is exercised directly against a
 * synthetic block tree, since it reads the canonical result array the same way
 * the harness does.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\CorpusDiagnostics\CorpusDetectors;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$html = <<<'HTML'
<div>
  <p>Hello <span class="accent">world</span></p>
  <h2 style="color:var(--brand-color)">Big <span style="font-weight:bold">title</span></h2>
  <p style="background:var(--wp--preset--color--primary)">Preset paragraph</p>
</div>
HTML;

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$collected = CorpusDetectors::collect($result);

$byDetector = static function (array $findings, string $detector): array {
    return array_values(array_filter(
        $findings,
        static fn (array $finding): bool => ($finding['detector'] ?? '') === $detector
    ));
};

// ---------------------------------------------------------------------------
// 1. classed-span-in-content: the paragraph and heading each report once.
// ---------------------------------------------------------------------------
$spanFindings = $byDetector($collected['findings'], 'classed_span_in_content');
$assert(2 === count($spanFindings), '1: both classed/styled spans are reported', 'got ' . count($spanFindings));
$spanPatterns = array_map(static fn (array $f): string => (string) $f['pattern'], $spanFindings);
sort($spanPatterns);
$assert(
    array('core/heading', 'core/paragraph') === $spanPatterns,
    '1b: classed-span findings carry the offending block name as pattern',
    implode(',', $spanPatterns)
);

// ---------------------------------------------------------------------------
// 2. var-dependent styling: the custom property is reported; the wp-preset is
//    counted for visibility but excluded from the actionable findings.
// ---------------------------------------------------------------------------
$varFindings = $byDetector($collected['findings'], 'var_dependent_styling');
$assert(1 === count($varFindings), '2: only the custom var() is an actionable finding', 'got ' . count($varFindings));
$assert(
    1 === count($varFindings) && '--brand-color' === $varFindings[0]['pattern'],
    '2b: custom var() finding names the custom property',
    $varFindings[0]['pattern'] ?? '(none)'
);
$assert(
    in_array('--wp--preset--color--primary', $collected['var_names'], true),
    '2c: preset var() is still tracked in var_names for visibility'
);
$assert(
    (int) $collected['metrics']['var_ref_count'] >= 2,
    '2d: var_ref_count counts both custom and preset references',
    (string) $collected['metrics']['var_ref_count']
);
$assert(
    1 === (int) $collected['metrics']['var_custom_ref_count'],
    '2e: var_custom_ref_count counts only the custom reference',
    (string) $collected['metrics']['var_custom_ref_count']
);

// ---------------------------------------------------------------------------
// 3. empty core/html: a comment-only (stripped-SVG) block and a whitespace-only
//    block are each reported, while a non-empty raw-HTML block is not.
// ---------------------------------------------------------------------------
$syntheticBlocks = array(
    array( 'blockName' => 'core/html', 'attrs' => array(), 'innerHTML' => '<!-- svg stripped -->' ),
    array( 'blockName' => 'core/html', 'attrs' => array(), 'innerHTML' => "   \n  " ),
    array( 'blockName' => 'core/html', 'attrs' => array(), 'innerHTML' => '<svg><path></path></svg>' ),
);
$emptyFindings = CorpusDetectors::emptyCoreHtml($syntheticBlocks);
$assert(2 === count($emptyFindings), '3: both empty/stripped core/html blocks are reported', 'got ' . count($emptyFindings));
$emptyPatterns = array_map(static fn (array $f): string => (string) $f['pattern'], $emptyFindings);
sort($emptyPatterns);
$assert(
    array('comment_only_or_stripped', 'whitespace_only') === $emptyPatterns,
    '3b: empty-html pattern distinguishes comment-only/stripped from whitespace-only',
    implode(',', $emptyPatterns)
);
$fallbackFindings = CorpusDetectors::coreHtmlFallback($syntheticBlocks);
$assert(
    1 === count($fallbackFindings) && '<svg>' === $fallbackFindings[0]['pattern'],
    '3c: a non-empty raw-HTML block is clustered by its leading tag, not flagged empty',
    (string) ($fallbackFindings[0]['pattern'] ?? '(none)')
);

// ---------------------------------------------------------------------------
// 4. Cluster keys pair the repair bucket with the structural pattern.
// ---------------------------------------------------------------------------
$assert(
    'css_custom_property_materialization :: --brand-color' === CorpusDetectors::clusterKey($varFindings[0]),
    '4: cluster key joins repair bucket and pattern',
    CorpusDetectors::clusterKey($varFindings[0])
);

// ---------------------------------------------------------------------------
// 5. Native-rate metric stays a bounded ratio.
// ---------------------------------------------------------------------------
$rate = (float) $collected['metrics']['native_rate'];
$assert($rate >= 0.0 && $rate <= 1.0, '5: native_rate is a bounded ratio', (string) $rate);
$assert((int) $collected['metrics']['block_count'] > 0, '5b: block_count is populated');

if ( $failures > 0 ) {
    fwrite(STDERR, "CorpusDetectors unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "CorpusDetectors unit tests: {$passes} passed\n");
