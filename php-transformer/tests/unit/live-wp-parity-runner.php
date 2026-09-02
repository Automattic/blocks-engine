<?php
declare(strict_types=1);

/**
 * Unit tests for the live-WP external-candidate runner entry
 * ({@see StaticStyleParityRunner::compareSourceToCandidate}).
 *
 * Plain-PHP test script in the style of tests/unit/css-value-splitter.php — no
 * PHPUnit. The live-WP variant must:
 *   1. Run the EXISTING deterministic comparator against an external candidate and
 *      produce a byte-identical report across repeated runs (determinism).
 *   2. Reduce to the render-free proxy when fed the proxy's own candidate HTML and
 *      the same CSS on both sides (wiring proof — same comparator, same contract).
 *   3. Name the exact diverged CSS property when WP's render emits different
 *      effective styling than the source (the bug class this variant exists for).
 *   4. Judge the candidate solely on its own rendered styling: the source's author
 *      CSS must NOT leak onto the candidate side.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

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

$runner = new StaticStyleParityRunner();

$sourceHtml = <<<'HTML'
<!doctype html>
<html><head><style>
.hero { color: #112233; font-size: 24px; text-align: center; }
.cta  { background-color: #ff0000; border-radius: 8px; }
</style></head>
<body>
  <div class="hero">Welcome aboard</div>
  <a class="cta">Get started</a>
</body></html>
HTML;

// A faithful "live-WP render": same content, same effective styling, but expressed
// the way WordPress would emit it (block wrapper classes + an inline global-styles
// <style> block carrying the same declarations). The DOM differs; the effective
// style on the matched content does not.
$candidateFaithful = <<<'HTML'
<!doctype html>
<html><head><style id="global-styles-inline-css">
.hero { color: #112233; font-size: 24px; text-align: center; }
.cta  { background-color: #ff0000; border-radius: 8px; }
</style></head>
<body>
  <div class="wp-block-group">
    <div class="hero">Welcome aboard</div>
    <a class="cta">Get started</a>
  </div>
</body></html>
HTML;

// A regressed "live-WP render": WP's rendering layer dropped the CTA background
// color and shifted the hero color — exactly the global-styles/block-supports
// regression class the render-free proxy cannot see.
$candidateRegressed = <<<'HTML'
<!doctype html>
<html><head><style id="global-styles-inline-css">
.hero { color: #999999; font-size: 24px; text-align: center; }
.cta  { border-radius: 8px; }
</style></head>
<body>
  <div class="wp-block-group">
    <div class="hero">Welcome aboard</div>
    <a class="cta">Get started</a>
  </div>
</body></html>
HTML;

// ---------------------------------------------------------------------------
// 1. Determinism: same inputs -> byte-identical report across two runs.
// ---------------------------------------------------------------------------
$run1 = $runner->compareSourceToCandidate($sourceHtml, $candidateRegressed);
$run2 = $runner->compareSourceToCandidate($sourceHtml, $candidateRegressed);
$json1 = json_encode($run1);
$json2 = json_encode($run2);
$assert($json1 === $json2, '1: external-candidate report is byte-identical across runs');

// ---------------------------------------------------------------------------
// 2. Wiring proof: feeding the render-free proxy's own candidate HTML through the
//    external entry (same CSS both sides) reproduces compareSourceToTransform.
// ---------------------------------------------------------------------------
$proxyCss = '.hero { color: #112233; font-size: 24px; } .cta { background-color: #ff0000; }';
$proxyReport = $runner->compareSourceToTransform($sourceHtml, $proxyCss);
$transformResult = (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())
    ->transform($sourceHtml, array( 'static_css' => $proxyCss ))->toArray();
$proxyCandidateHtml = StaticStyleParityRunner::candidateHtmlFromSerializedBlocks(
    (string) ($transformResult['serialized_blocks'] ?? '')
);
$proxyAssetCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $transformResult['assets'] ?? array()));
$viaExternal = $runner->compareSourceToCandidate($sourceHtml, $proxyCandidateHtml, $proxyCss, $proxyAssetCss);
$assert(
    json_encode($proxyReport) === json_encode($viaExternal),
    '2: external entry reproduces the render-free proxy when fed the proxy candidate + same CSS'
);

$socialProxy = StaticStyleParityRunner::candidateHtmlFromSerializedBlocks(
    '<!-- wp:social-links --><ul class="wp-block-social-links"><!-- wp:social-link {"url":"#","service":"github","label":"GitHub","className":"source-item"} /--></ul><!-- /wp:social-links -->'
);
$assert(
    str_contains($socialProxy, '<li class="wp-social-link wp-social-link-github source-item">')
        && str_contains($socialProxy, '<a href="#" class="wp-block-social-link-anchor">')
        && str_contains($socialProxy, '<svg width="24" height="24"')
        && str_contains($socialProxy, '>GitHub</span>'),
    '2b: render-free proxy materializes the stable structure of dynamic social-link children'
);

$navigationProxy = StaticStyleParityRunner::candidateHtmlFromSerializedBlocks(
    '<!-- wp:navigation {"className":"main-nav"} --><!-- wp:navigation-submenu {"className":"main-nav__item","label":"Platform \\u003cspan class=\\u0022caret\\u0022\\u003e↓\\u003c/span\\u003e","url":"/platform"} --><!-- wp:navigation-link {"className":"mega-link","label":"Supplier 360\\u003cspan class=\\u0022description\\u0022\\u003eUnified record\\u003c/span\\u003e","url":"/platform#supplier"} /--><!-- /wp:navigation-submenu --><!-- /wp:navigation -->'
);
$assert(
    str_contains($navigationProxy, '<nav class="wp-block-navigation main-nav"><ul class="wp-block-navigation__container wp-block-navigation main-nav">')
        && str_contains($navigationProxy, '<li class="wp-block-navigation-item main-nav__item has-child">')
        && str_contains($navigationProxy, '<a class="wp-block-navigation-item__content" href="/platform">')
        && str_contains($navigationProxy, '<span class="caret">↓</span>')
        && str_contains($navigationProxy, '<li class="wp-block-navigation-item mega-link">')
        && str_contains($navigationProxy, '<span class="description">Unified record</span>')
        && str_ends_with($navigationProxy, '</li></ul></li></ul></nav>'),
    '2c: render-free proxy materializes nested dynamic navigation block structure'
);

// ---------------------------------------------------------------------------
// 3. Faithful live render scores perfectly (parity == 1.0, no findings).
// ---------------------------------------------------------------------------
$faithful = $runner->compareSourceToCandidate($sourceHtml, $candidateFaithful);
$faithfulScore = (float) ($faithful['parity']['score'] ?? 0.0);
$faithfulFindings = (int) ($faithful['summary']['finding_total'] ?? -1);
$assert($faithfulScore >= 0.999, '3: faithful live render reaches full parity', sprintf('score=%.4f', $faithfulScore));
$assert(0 === $faithfulFindings, '3b: faithful live render yields no findings', sprintf('findings=%d', $faithfulFindings));

// ---------------------------------------------------------------------------
// 4. Regressed live render is caught and names the exact diverged properties.
// ---------------------------------------------------------------------------
$regressed = $runner->compareSourceToCandidate($sourceHtml, $candidateRegressed);
$regressedScore = (float) ($regressed['parity']['score'] ?? 1.0);
$assert($regressedScore < $faithfulScore, '4: regressed live render scores below faithful render', sprintf('regressed=%.4f faithful=%.4f', $regressedScore, $faithfulScore));

$divergedProperties = array();
foreach ( (array) ($regressed['matches'] ?? array()) as $match ) {
    foreach ( (array) ($match['style_deltas'] ?? array()) as $delta ) {
        $divergedProperties[(string) ($delta['property'] ?? '')] = true;
    }
}
$assert(isset($divergedProperties['background-color']), '4b: dropped CTA background-color is reported as a per-property diff');
$assert(isset($divergedProperties['color']), '4c: shifted hero color is reported as a per-property diff');

// ---------------------------------------------------------------------------
// 5. Source author CSS must NOT leak onto the candidate side. If the source's CSS
//    were applied to the candidate, the regressed candidate (whose own <style>
//    dropped the background) would falsely score as a match.
// ---------------------------------------------------------------------------
$sourceOnlyCss = '.cta { background-color: #ff0000; }';
$leakCheck = $runner->compareSourceToCandidate($sourceHtml, $candidateRegressed, $sourceOnlyCss, '');
$leakDiverged = array();
foreach ( (array) ($leakCheck['matches'] ?? array()) as $match ) {
    foreach ( (array) ($match['style_deltas'] ?? array()) as $delta ) {
        $leakDiverged[(string) ($delta['property'] ?? '')] = true;
    }
}
$assert(isset($leakDiverged['background-color']), '5: source author CSS does not leak onto the candidate side');

// ---------------------------------------------------------------------------
// 6. Generated inline-layout carriers project direct-child author selectors
//    through a display:contents paragraph without hiding their declarations
//    from the deterministic cascade.
// ---------------------------------------------------------------------------
$childSource = '<div class="row"><span class="item">Vessel Beauty</span></div>';
$childCandidate = '<div class="row"><p class="carrier"><span class="item">Vessel Beauty</span></p></div>';
$childParity = $runner->compareSourceToCandidate(
    $childSource,
    $childCandidate,
    '.row > .item{display:inline-flex;color:#c4a35a;font-size:24px}',
    '.row > p.carrier > .item{display:inline-flex;color:#c4a35a;font-size:24px}'
);
$assert(
    1.0 === (float) ($childParity['parity']['score'] ?? 0.0)
        && 0 === (int) ($childParity['summary']['finding_total'] ?? -1),
    '6: child-combinator carrier projection retains full static style parity'
);

if ( $failures > 0 ) {
    fwrite(STDERR, "live-wp-parity runner unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "live-wp-parity runner unit tests: {$passes} passed\n");
