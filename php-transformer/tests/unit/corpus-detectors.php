<?php
declare(strict_types=1);

/**
 * Unit tests for the corpus-diagnostics detectors.
 *
 * Plain-PHP test script in the style of tests/unit/css-value-splitter.php — no
 * PHPUnit. The four hardened detectors are exercised:
 *   1. RichText editor-invalid risk: a class/style-bearing inline <span>/<a> in
 *      paragraph/heading/list-item content is the authoritative invalidity
 *      signal, ranked HIGH (structural wp_block_validity=0 is not "no invalid
 *      content").
 *   2. Layout-direction faithfulness: a display:flex;flex-direction:column source
 *      that converts to core/columns flags layout_direction_misrecognition,
 *      while genuine horizontal flex does not.
 *   3. SVG loss: an <svg>-sourced empty/comment-only core/html flags
 *      svg_content_lost, while a shape-bearing svg core/html does NOT.
 *   4. var density is informational (severity=info), not a top-ranked repair gap.
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

// Spans carry aria-hidden so the transformer preserves them in RichText content
// (a bare classed span is collapsed) — this mirrors how class/style-bearing
// inline formats actually survive into the corpus, where RichText would then
// drop the unsupported class on parse.
$html = <<<'HTML'
<div>
  <p>Total <span class="metric-unit" aria-hidden="true">+</span> growth and var(--brand-color)</p>
  <h2 style="color:var(--brand-color)">Big <span class="quote-glyph" aria-hidden="true">&ldquo;</span> title</h2>
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
// 1. RichText invalid risk: the classed/styled-span paragraph and heading each
//    report once as HIGH-severity editor-invalid risk.
// ---------------------------------------------------------------------------
$riskFindings = $byDetector($collected['findings'], 'richtext_invalid_risk');
$assert(2 === count($riskFindings), '1: both classed/styled spans are reported as richtext invalid risk', 'got ' . count($riskFindings));
$riskPatterns = array_map(static fn (array $f): string => (string) $f['pattern'], $riskFindings);
sort($riskPatterns);
$assert(
    array('core/heading', 'core/paragraph') === $riskPatterns,
    '1b: richtext-risk findings carry the offending block name as pattern',
    implode(',', $riskPatterns)
);
$assert(
    array() === array_values(array_filter($riskFindings, static fn (array $f): bool => CorpusDetectors::SEVERITY_HIGH !== ($f['severity'] ?? ''))),
    '1c: richtext invalid risk is HIGH severity'
);
$assert(
    2 === (int) $collected['metrics']['richtext_invalid_risk_count'],
    '1d: richtext_invalid_risk_count metric surfaces the real invalidity count',
    (string) $collected['metrics']['richtext_invalid_risk_count']
);

// A classed inline <a> inside paragraph/list-item content also counts; a plain
// (attribute-free) span/anchor does not.
$anchorResult = ( new HtmlTransformer() )->transform(
    '<ul><li>See <a class="cta" href="/x" aria-hidden="true">link</a></li><li>plain <span>text</span></li></ul>',
    array()
)->toArray();
$anchorRisk = $byDetector(CorpusDetectors::collect($anchorResult)['findings'], 'richtext_invalid_risk');
$assert(
    1 === count($anchorRisk) && 'core/list-item' === $anchorRisk[0]['pattern'],
    '1e: a classed <a> in list-item content is richtext invalid risk; a plain span is not',
    (string) count($anchorRisk) . ':' . (string) ($anchorRisk[0]['pattern'] ?? '(none)')
);

// ---------------------------------------------------------------------------
// 2. var density: the custom property is reported as INFORMATIONAL (not a repair
//    gap, not top-ranked); the wp-preset is excluded from findings entirely.
// ---------------------------------------------------------------------------
$varFindings = $byDetector($collected['findings'], 'var_dependent_styling');
$assert(1 === count($varFindings), '2: only the custom var() yields a finding', 'got ' . count($varFindings));
$assert(
    1 === count($varFindings) && '--brand-color' === $varFindings[0]['pattern'],
    '2b: custom var() finding names the custom property',
    $varFindings[0]['pattern'] ?? '(none)'
);
$assert(
    1 === count($varFindings) && CorpusDetectors::SEVERITY_INFO === ($varFindings[0]['severity'] ?? ''),
    '2c: var density is informational severity, not an actionable defect'
);
$assert(
    1 === count($varFindings) && 'informational_var_density' === ($varFindings[0]['repair_bucket'] ?? ''),
    '2d: var density is labeled informational, not a css materialization repair gap',
    (string) ($varFindings[0]['repair_bucket'] ?? '(none)')
);
$assert(
    in_array('--wp--preset--color--primary', $collected['var_names'], true),
    '2e: preset var() is still tracked in var_names for visibility'
);
$assert(
    (int) $collected['metrics']['var_custom_ref_count'] >= 1,
    '2f: var_custom_ref_count counts the custom reference',
    (string) $collected['metrics']['var_custom_ref_count']
);

// ---------------------------------------------------------------------------
// 3. SVG loss: an <svg>-sourced empty/comment-only core/html flags
//    svg_content_lost (HIGH); a shape-bearing svg core/html does NOT; a generic
//    empty html (no svg trace) is the lower-severity empty_core_html signal.
// ---------------------------------------------------------------------------
$svgBlocks = array(
    array( 'blockName' => 'core/html', 'attrs' => array(), 'innerHTML' => '<!-- svg icon stripped -->' ),
    array( 'blockName' => 'core/html', 'attrs' => array(), 'innerHTML' => '<svg viewBox="0 0 10 10"><path d="M0 0"></path></svg>' ),
    array( 'blockName' => 'core/html', 'attrs' => array(), 'innerHTML' => "   \n  " ),
);
$svgLost = CorpusDetectors::svgContentLost(array(), $svgBlocks);
$assert(1 === count($svgLost), '3: only the stripped-svg empty block flags svg_content_lost', 'got ' . count($svgLost));
$assert(
    1 === count($svgLost) && 'empty_core_html_from_svg' === ($svgLost[0]['pattern'] ?? '') && CorpusDetectors::SEVERITY_HIGH === ($svgLost[0]['severity'] ?? ''),
    '3b: svg_content_lost is HIGH severity and names the empty-from-svg pattern',
    (string) ($svgLost[0]['pattern'] ?? '(none)')
);
$emptyFindings = CorpusDetectors::emptyCoreHtml($svgBlocks);
$assert(
    1 === count($emptyFindings) && 'whitespace_only' === ($emptyFindings[0]['pattern'] ?? ''),
    '3c: the whitespace-only block is a generic empty_core_html; the svg one is not double-counted here',
    (string) count($emptyFindings) . ':' . (string) ($emptyFindings[0]['pattern'] ?? '(none)')
);

// SVG loss also surfaces from the transformer inline-svg fallback diagnostics.
$diagResult = array(
    'diagnostics' => array(
        array( 'reason_code' => 'html_inline_svg_fallback', 'message' => 'dropped' ),
        array( 'reason_code' => 'html_unsafe_inline_svg', 'message' => 'unsafe' ),
    ),
);
$diagSvg = CorpusDetectors::svgContentLost($diagResult, array());
$assert(2 === count($diagSvg), '3d: inline-svg fallback diagnostics route into svg_content_lost', 'got ' . count($diagSvg));

// ---------------------------------------------------------------------------
// 4. Layout-direction misrecognition: a vertical flex container that converts to
//    core/columns flags columns_from_vertical_flex; horizontal flex does not.
// ---------------------------------------------------------------------------
$verticalFlex = '<div style="display:flex; flex-direction:column; gap:1rem; max-width:760px;">'
    . '<p>One</p><p>Two</p><p>Three</p></div>';
$layout = CorpusDetectors::layoutDirectionMisrecognition(
    $verticalFlex,
    static function (string $fragment): bool {
        $blocks = ( new HtmlTransformer() )->transform($fragment, array())->toArray()['blocks'] ?? array();

        return is_array($blocks[0] ?? null) && 'core/columns' === ($blocks[0]['blockName'] ?? '');
    }
);
$assert(1 === count($layout), '4: a vertical-flex container that becomes core/columns is flagged', 'got ' . count($layout));
$assert(
    1 === count($layout)
        && 'columns_from_vertical_flex' === ($layout[0]['pattern'] ?? '')
        && CorpusDetectors::SEVERITY_HIGH === ($layout[0]['severity'] ?? ''),
    '4b: layout misrecognition is HIGH severity with the columns_from_vertical_flex pattern',
    (string) ($layout[0]['pattern'] ?? '(none)')
);

$horizontalFlex = '<div style="display:flex; flex-direction:row; gap:1rem;"><div>A</div><div>B</div></div>';
$horizontal = CorpusDetectors::layoutDirectionMisrecognition(
    $horizontalFlex,
    static fn (string $fragment): bool => true
);
$assert(0 === count($horizontal), '4c: genuine horizontal flex is never flagged as a misrecognition', 'got ' . count($horizontal));

// Verifier veto: a vertical-flex source that does NOT convert to columns is not flagged.
$vetoed = CorpusDetectors::layoutDirectionMisrecognition(
    $verticalFlex,
    static fn (string $fragment): bool => false
);
$assert(0 === count($vetoed), '4d: a vertical-flex candidate that does not become columns is vetoed', 'got ' . count($vetoed));

// ---------------------------------------------------------------------------
// 5. Severity ranking and cluster keys.
// ---------------------------------------------------------------------------
$assert(
    CorpusDetectors::severityRank(CorpusDetectors::SEVERITY_HIGH) > CorpusDetectors::severityRank(CorpusDetectors::SEVERITY_MEDIUM)
        && CorpusDetectors::severityRank(CorpusDetectors::SEVERITY_MEDIUM) > CorpusDetectors::severityRank(CorpusDetectors::SEVERITY_INFO),
    '5: severity ranks high > medium > info'
);
$assert(
    'informational_var_density :: --brand-color' === CorpusDetectors::clusterKey($varFindings[0]),
    '5b: cluster key joins repair bucket and pattern',
    CorpusDetectors::clusterKey($varFindings[0])
);

// ---------------------------------------------------------------------------
// 6. Native-rate metric stays a bounded ratio.
// ---------------------------------------------------------------------------
$rate = (float) $collected['metrics']['native_rate'];
$assert($rate >= 0.0 && $rate <= 1.0, '6: native_rate is a bounded ratio', (string) $rate);
$assert((int) $collected['metrics']['block_count'] > 0, '6b: block_count is populated');

if ( $failures > 0 ) {
    fwrite(STDERR, "CorpusDetectors unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "CorpusDetectors unit tests: {$passes} passed\n");
