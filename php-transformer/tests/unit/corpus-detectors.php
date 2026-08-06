<?php
declare(strict_types=1);

/**
 * Unit tests for the corpus-diagnostics detectors.
 *
 * Plain-PHP test script in the style of tests/unit/css-value-splitter.php — no
 * PHPUnit. The five hardened detectors are exercised:
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
 *   5. Media-text corpus metrics count emitted blocks and assign each strict
 *      two-pane candidate to at most one source-derived gate outcome.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\CorpusDiagnostics\CorpusDetectors;
use Automattic\BlocksEngine\PhpTransformer\CorpusDiagnostics\CorpusDiagnosticsRunner;
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

$fixtureCorpus = sys_get_temp_dir() . '/blocks-engine-corpus-' . bin2hex(random_bytes(4));
mkdir($fixtureCorpus . '/canonical', 0777, true);
file_put_contents($fixtureCorpus . '/canonical/index.html', '<main><p>Canonical fixture metadata</p></main>');
file_put_contents($fixtureCorpus . '/canonical/fixture.json', json_encode(array('fixture_class' => 'marketing/static', 'class' => 'unknown')));
$fixtureReport = (new CorpusDiagnosticsRunner())->run($fixtureCorpus);
$assert('marketing/static' === ($fixtureReport['fixtures']['canonical/index.html']['class'] ?? null), '0: corpus diagnostics prefers canonical fixture_class metadata');

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

// Link identity is safe RichText content. A classed inline <a> therefore does
// not count, just like a plain span/anchor.
$anchorResult = ( new HtmlTransformer() )->transform(
    '<ul><li>See <a class="cta" href="/x" aria-hidden="true">link</a></li><li>plain <span>text</span></li></ul>',
    array()
)->toArray();
$anchorRisk = $byDetector(CorpusDetectors::collect($anchorResult)['findings'], 'richtext_invalid_risk');
$assert(0 === count($anchorRisk), '1e: classed link identity in list-item RichText is safe; a plain span is also safe', (string) count($anchorRisk));

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
// 4. Layout-direction misrecognition: the detector flags a vertical flex
//    container that converts to core/columns; horizontal flex does not. The
//    verifier is stubbed here so the detector logic is exercised independently
//    of the live transformer (whose vertical-flex routing is asserted in 4e).
// ---------------------------------------------------------------------------
$verticalFlex = '<div style="display:flex; flex-direction:column; gap:1rem; max-width:760px;">'
    . '<p>One</p><p>Two</p><p>Three</p></div>';
$layout = CorpusDetectors::layoutDirectionMisrecognition(
    $verticalFlex,
    static fn (string $fragment): bool => true
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

// 4e. Regression guard for the fix: the live transformer must route a vertical
//     flex container (display:flex; flex-direction:column) to a vertical
//     core/group block, not a horizontal core/columns. With the real transformer as
//     the verifier the detector therefore finds nothing.
$verticalBlocks = ( new HtmlTransformer() )->transform($verticalFlex, array())->toArray()['blocks'] ?? array();
$assert(
    'core/group' === ($verticalBlocks[0]['blockName'] ?? ''),
    '4e: live transformer emits a core group block for a vertical flex container',
    (string) ($verticalBlocks[0]['blockName'] ?? '(none)')
);
$assert(! isset($verticalBlocks[0]['attrs']['layout']), '4e: CSS-owned group carries no core layout orientation');
$liveLayout = CorpusDetectors::layoutDirectionMisrecognition(
    $verticalFlex,
    static function (string $fragment): bool {
        $blocks = ( new HtmlTransformer() )->transform($fragment, array())->toArray()['blocks'] ?? array();

        return is_array($blocks[0] ?? null) && 'core/columns' === ($blocks[0]['blockName'] ?? '');
    }
);
$assert(0 === count($liveLayout), '4e: detector reports no misrecognition once the transformer stacks the flex column vertically', 'got ' . count($liveLayout));

// 4f. Horizontal flex is also source CSS-owned and uses the same core group contract.
$horizontalColumns = '<div style="display:flex; gap:1rem;"><div><h2>A</h2><p>one</p></div><div><h2>B</h2><p>two</p></div></div>';
$horizontalBlocks = ( new HtmlTransformer() )->transform($horizontalColumns, array())->toArray()['blocks'] ?? array();
$assert(
    'core/group' === ($horizontalBlocks[0]['blockName'] ?? ''),
    '4f: live transformer keeps horizontal flex under author CSS ownership',
    (string) ($horizontalBlocks[0]['blockName'] ?? '(none)')
);

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

// ---------------------------------------------------------------------------
// 7. Cover-gate rejections: inline background-image containers rejected by a
//    style-derived cover gate are informational tuning candidates.
// ---------------------------------------------------------------------------
$subThresholdHero = '<div class="short-hero" style="background-image:url(https://example.com/t.jpg);min-height:120px">'
    . '<h2>T</h2><p>B</p></div>';
$coverRejections = CorpusDetectors::coverGateRejections($subThresholdHero, array());
$assert(
    1 === count($coverRejections)
        && 'cover_gate_rejection' === ($coverRejections[0]['repair_bucket'] ?? '')
        && CorpusDetectors::SEVERITY_INFO === ($coverRejections[0]['severity'] ?? '')
        && array(
            'gate'  => 'not_hero_sized',
            'tag'   => 'div',
            'class' => 'short-hero',
        ) === ($coverRejections[0]['detail'] ?? null),
    '7: a sub-threshold background hero yields one informational cover-gate rejection',
    json_encode($coverRejections)
);

$matchedHero = '<section class="hero" style="background-image:url(https://example.com/hero.jpg);'
    . 'background-size:cover;min-height:480px"><h1>Build</h1><p>Ship</p></section>';
$matchedCover = array(
    array(
        'blockName'   => 'core/cover',
        'attrs'       => array(),
        'innerBlocks' => array(),
    ),
);
$matchedRejections = CorpusDetectors::coverGateRejections($matchedHero, $matchedCover);
$assert(0 === count($matchedRejections), '7b: a matched hero emitted as core/cover yields no rejection', 'got ' . count($matchedRejections));

$noBackgroundRejections = CorpusDetectors::coverGateRejections(
    '<article class="story"><h2>Story</h2><p>Body</p></article>',
    array()
);
$assert(0 === count($noBackgroundRejections), '7c: source without background containers yields no cover rejection', 'got ' . count($noBackgroundRejections));

$collectedCoverRejections = $byDetector(
    CorpusDetectors::collect(array('blocks' => array()), $subThresholdHero)['findings'],
    'cover_gate_rejection'
);
$assert(
    1 === count($collectedCoverRejections),
    '7d: collect wires cover-gate rejection findings into the report',
    'got ' . count($collectedCoverRejections)
);

// ---------------------------------------------------------------------------
// 8. Media-text corpus metrics: emitted blocks plus deterministic, exclusive
//    gate outcomes for strict two-pane source candidates. width_oob is a
//    diagnostic outcome only: the candidate still emits core/media-text.
// ---------------------------------------------------------------------------
$mediaTextCounterKeys = array(
    'media_text_decline_media_impure_count',
    'media_text_decline_no_text_side_count',
    'media_text_decline_vertical_or_reversed_count',
    'media_text_decline_unsafe_url_count',
    'media_text_width_oob_count',
    'media_text_decline_linked_video_count',
    'media_text_decline_other_count',
    'media_text_diagnostic_error_count',
);
$mediaTextCases = array(
    'media_text_decline_media_impure_count' => '<section><figure><img src="/feature.jpg">Caption</figure><div><h2>Title</h2><p>Body</p></div></section>',
    'media_text_decline_no_text_side_count' => '<section><figure><img src="/feature.jpg"></figure><div><hr></div></section>',
    'media_text_decline_vertical_or_reversed_count' => '<html><head><style>.pair{display:flex;flex-direction:column}</style></head><body><section class="pair"><figure><img src="/feature.jpg"></figure><div><h2>Title</h2></div></section></body></html>',
    'media_text_decline_unsafe_url_count' => '<section><figure><img src="javascript:alert(1)"></figure><div><h2>Title</h2></div></section>',
    'media_text_width_oob_count' => '<section style="display:grid;grid-template-columns:5% auto"><figure><img src="/feature.jpg"></figure><div><h2>Title</h2></div></section>',
    'media_text_decline_linked_video_count' => '<section><a href="/watch"><video src="/feature.mp4"></video></a><div><h2>Title</h2></div></section>',
    'media_text_decline_other_count' => '<section><figure><img src="/feature.jpg"></figure><div><h2>Title</h2></div></section>',
);

foreach ( $mediaTextCases as $expectedKey => $source ) {
    $flat = 'media_text_width_oob_count' === $expectedKey
        ? array( array( 'blockName' => 'core/media-text' ) )
        : array();
    $metrics = CorpusDetectors::collect(array( 'blocks' => $flat ), $source)['metrics'];
    $actual = array();
    foreach ( $mediaTextCounterKeys as $counterKey ) {
        if ( 0 !== (int) ($metrics[ $counterKey ] ?? 0) ) {
            $actual[ $counterKey ] = (int) $metrics[ $counterKey ];
        }
    }
    $assert(
        array( $expectedKey => 1 ) === $actual,
        '8: media-text candidate has one exclusive outcome: ' . $expectedKey,
        json_encode($actual)
    );
}

$directRtlSource = '<section style="direction:rtl"><figure><img src="/feature.jpg"></figure><div><h2>Title</h2></div></section>';
$directRtlResult = ( new HtmlTransformer() )->transform($directRtlSource, array())->toArray();
$directRtlMetrics = CorpusDetectors::collect($directRtlResult, $directRtlSource)['metrics'];
$assert(
    0 === (int) $directRtlMetrics['media_text_count']
        && 1 === (int) $directRtlMetrics['media_text_decline_vertical_or_reversed_count'],
    '8b: direction:rtl declared on candidate declines as vertical-or-reversed',
    json_encode($directRtlMetrics)
);

$inheritedRtlSource = '<div style="direction:rtl"><section><figure><img src="/feature.jpg"></figure><div><h2>Title</h2></div></section></div>';
$inheritedRtlResult = ( new HtmlTransformer() )->transform($inheritedRtlSource, array())->toArray();
$inheritedRtlMetrics = CorpusDetectors::collect($inheritedRtlResult, $inheritedRtlSource)['metrics'];
$assert(
    0 === (int) $inheritedRtlMetrics['media_text_count']
        && 1 === (int) $inheritedRtlMetrics['media_text_decline_vertical_or_reversed_count'],
    '8c: inherited direction:rtl declines in production and diagnostics agree',
    json_encode($inheritedRtlMetrics)
);

$nestedWrapperSource = '<div><section style="display:flex"><figure><img src="/feature.jpg"></figure><div><h2>Title</h2></div></section><aside><p>Side</p></aside></div>';
$nestedWrapperResult = ( new HtmlTransformer() )->transform($nestedWrapperSource, array())->toArray();
$nestedWrapperMetrics = CorpusDetectors::collect($nestedWrapperResult, $nestedWrapperSource)['metrics'];
$nestedWrapperDeclines = array_sum(array_map(
    static fn (string $key): int => (int) $nestedWrapperMetrics[ $key ],
    array_filter($mediaTextCounterKeys, static fn (string $key): bool => 'media_text_width_oob_count' !== $key)
));
$assert(
    1 === (int) $nestedWrapperMetrics['media_text_count'] && 0 === $nestedWrapperDeclines,
    '8c2: wrapper around a converting candidate is not itself counted as a declined candidate',
    json_encode($nestedWrapperMetrics)
);

$conditionalLayouts = array(
    'media' => '@media (min-width:1px){.pair{display:flex;flex-direction:column}}',
    'supports' => '@supports (display:grid){.pair{display:flex;flex-direction:column}}',
    'container' => '@container (min-width:1px){.pair{display:flex;flex-direction:column}}',
);
foreach ( $conditionalLayouts as $atRule => $conditionalCss ) {
    $conditionalSource = '<html><head><style>' . $conditionalCss . '</style></head><body>'
        . '<section class="pair"><figure><img src="/feature.jpg"></figure><div><h2>Title</h2></div></section>'
        . '</body></html>';
    $conditionalResult = ( new HtmlTransformer() )->transform($conditionalSource, array())->toArray();
    $conditionalMetrics = CorpusDetectors::collect($conditionalResult, $conditionalSource)['metrics'];
    // No resting display means the mechanism gate declines the candidate;
    // the conditional column direction must NOT be misclassified as a
    // vertical/reversed decline — it lands in `other` via reconciliation.
    $assert(
        0 === (int) $conditionalMetrics['media_text_count']
            && 0 === (int) $conditionalMetrics['media_text_decline_vertical_or_reversed_count']
            && 1 === (int) $conditionalMetrics['media_text_decline_other_count'],
        '8d: conditional @' . $atRule . ' layout affects neither resting gates nor vertical classification',
        json_encode($conditionalMetrics)
    );
}

$compoundClassSource = '<html><head><style>.pair.special{display:flex;flex-direction:column}</style></head><body>'
    . '<section class="pair special"><figure><img src="/feature.jpg"></figure><div><h2>Title</h2></div></section>'
    . '</body></html>';
$compoundClassResult = ( new HtmlTransformer() )->transform($compoundClassSource, array())->toArray();
$compoundClassMetrics = CorpusDetectors::collect($compoundClassResult, $compoundClassSource)['metrics'];
$assert(
    0 === (int) $compoundClassMetrics['media_text_count']
        && 1 === (int) $compoundClassMetrics['media_text_decline_vertical_or_reversed_count']
        && 0 === (int) $compoundClassMetrics['media_text_decline_other_count'],
    '8e: compound-class source rule drives same vertical gate as production matcher',
    json_encode($compoundClassMetrics)
);

$tableTextSource = '<section><figure><img src="/feature.jpg"></figure><div><table><tr><td>Data</td></tr></table></div></section>';
$tableTextResult = ( new HtmlTransformer() )->transform($tableTextSource, array())->toArray();
$tableTextMetrics = CorpusDetectors::collect($tableTextResult, $tableTextSource)['metrics'];
$assert(
    0 === (int) $tableTextMetrics['media_text_count']
        && 1 === (int) $tableTextMetrics['media_text_decline_no_text_side_count']
        && 0 === (int) $tableTextMetrics['media_text_decline_other_count'],
    '8f: text-bearing table lacks production text-bearing block and declines no-text-side',
    json_encode($tableTextMetrics)
);

$nestedMediaTextBlocks = array(
    array(
        'blockName'   => 'core/group',
        'innerBlocks' => array(
            array( 'blockName' => 'core/media-text', 'innerBlocks' => array() ),
        ),
    ),
    array( 'blockName' => 'core/media-text', 'innerBlocks' => array() ),
);
$emittedMetrics = CorpusDetectors::collect(array( 'blocks' => $nestedMediaTextBlocks ))['metrics'];
$assert(
    2 === (int) ($emittedMetrics['media_text_count'] ?? 0),
    '8g: media_text_count includes emitted blocks at every depth',
    (string) ($emittedMetrics['media_text_count'] ?? '(missing)')
);
$actualMediaTextKeys = array_values(array_filter(
    array_keys($emittedMetrics),
    static fn (string $key): bool => str_starts_with($key, 'media_text')
));
$assert(
    array_merge(array( 'media_text_count' ), $mediaTextCounterKeys) === $actualMediaTextKeys,
    '8h: media-text metrics expose exact frozen key set in stable order',
    implode(',', $actualMediaTextKeys)
);

$widthSource = $mediaTextCases['media_text_width_oob_count'];
$widthResult = ( new HtmlTransformer() )->transform($widthSource, array())->toArray();
$widthCollected = CorpusDetectors::collect($widthResult, $widthSource);
$assert(
    1 === (int) ($widthCollected['metrics']['media_text_count'] ?? 0)
        && 1 === (int) ($widthCollected['metrics']['media_text_width_oob_count'] ?? 0),
    '8i: width_oob remains diagnostic while transformer emits core/media-text',
    json_encode($widthCollected['metrics'])
);
$widthDeclined = CorpusDetectors::collect(array( 'blocks' => array() ), $widthSource)['metrics'];
$assert(
    0 === (int) $widthDeclined['media_text_width_oob_count']
        && 1 === (int) $widthDeclined['media_text_decline_other_count'],
    '8j: non-emitted width candidate is exclusively other, never width_oob',
    json_encode($widthDeclined)
);

$summary = ( new CorpusDiagnosticsRunner() )->renderSummary(array(
    'totals' => array(
        'document_count' => 1,
        'fixture_count' => 1,
        'block_count' => 1,
        'native_rate' => 1.0,
        'finding_count' => 0,
        'richtext_invalid_risk_count' => 0,
        'invalid_block_count' => 0,
        'svg_content_lost_count' => 0,
        'layout_direction_misrecognition_count' => 0,
        'var_ref_count' => 0,
        'var_custom_ref_count' => 0,
        'media_text_count' => 1,
        'media_text_decline_media_impure_count' => 2,
        'media_text_decline_no_text_side_count' => 3,
        'media_text_decline_vertical_or_reversed_count' => 4,
        'media_text_decline_unsafe_url_count' => 5,
        'media_text_width_oob_count' => 6,
        'media_text_decline_linked_video_count' => 7,
        'media_text_decline_other_count' => 8,
    ),
    'clusters' => array(),
));
$expectedMediaTextLine = 'MEDIA-TEXT: media_text_count=1 media_text_decline_media_impure_count=2 '
    . 'media_text_decline_no_text_side_count=3 media_text_decline_vertical_or_reversed_count=4 '
    . 'media_text_decline_unsafe_url_count=5 media_text_width_oob_count=6 '
    . 'media_text_decline_linked_video_count=7 media_text_decline_other_count=8';
$assert(
    str_contains($summary, $expectedMediaTextLine),
    '8k: runner summary surfaces exact media_text adoption and gate values on one line',
    $summary
);

if ( $failures > 0 ) {
    fwrite(STDERR, "CorpusDetectors unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "CorpusDetectors unit tests: {$passes} passed\n");
