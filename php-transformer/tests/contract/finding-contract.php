<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if ( $condition ) {
        return;
    }

    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    exit(1);
};

$assertThrows = static function (callable $callback, string $message): void {
    try {
        $callback();
    } catch ( InvalidArgumentException ) {
        return;
    }

    fwrite(STDERR, 'FAIL: expected InvalidArgumentException - ' . $message . PHP_EOL);
    exit(1);
};

// --- Direct contract unit coverage -----------------------------------------

$assert(
    'blocks-engine/php-transformer/conversion-finding/v1' === ConversionFindingContract::SCHEMA,
    'finding contract exposes the versioned schema constant'
);

// A finding identified by `code` (collectors / parity reports) validates.
ConversionFindingContract::assertFinding(array(
    'code'     => 'landmark_count_mismatch',
    'severity' => 'warning',
    'summary'  => 'Source header landmarks exceed generated core block representation.',
    'selector' => 'header:nth-of-type(1)',
));

// A finding identified by `diagnostic_code` (fallback emitter) validates.
ConversionFindingContract::assertFinding(array(
    'diagnostic_code' => 'html_script_fallback',
    'severity'        => 'warning',
    'message'         => 'Script HTML requires runtime behavior.',
    'classification'  => array('bucket' => 'unknown', 'confidence' => 0.1, 'signals' => array()),
    'observed_block'  => 'none',
));

$assert(
    'html_script_fallback' === ConversionFindingContract::findingCode(array('diagnostic_code' => 'html_script_fallback')),
    'findingCode resolves the diagnostic_code identifier'
);
$assert(
    'landmark_count_mismatch' === ConversionFindingContract::findingCode(array('code' => 'landmark_count_mismatch')),
    'findingCode resolves the code identifier'
);
$assert(
    '' === ConversionFindingContract::findingCode(array('severity' => 'warning')),
    'findingCode returns empty string when no identifier is present'
);

$assert(ConversionFindingContract::isFinding(array('code' => 'x')), 'isFinding accepts an identified finding');
$assert(! ConversionFindingContract::isFinding(array('severity' => 'warning')), 'isFinding rejects a finding without an identifier');
$assert(! ConversionFindingContract::isFinding('not-an-array'), 'isFinding rejects a non-array');

$assertThrows(
    static fn () => ConversionFindingContract::assertFinding(array('severity' => 'warning', 'message' => 'no id')),
    'finding without an identifier is rejected'
);
$assertThrows(
    static fn () => ConversionFindingContract::assertFinding(array('code' => 'x', 'severity' => 'fatal')),
    'finding with an out-of-band severity is rejected'
);
$assertThrows(
    static fn () => ConversionFindingContract::assertFinding(array('code' => 'x', 'selector' => array('not', 'a', 'string'))),
    'finding with a non-string scalar field is rejected'
);
$assertThrows(
    static fn () => ConversionFindingContract::assertFinding(array('code' => 'x', 'events' => 'not-an-array')),
    'finding with a non-array structured field is rejected'
);
$assertThrows(
    static fn () => ConversionFindingContract::assertFindings(array(array('code' => 'ok'), 'not-an-array'), 'sample findings'),
    'a non-array entry in a findings list is rejected'
);

// `observed_block` is the one field producers emit as string OR array.
ConversionFindingContract::assertFinding(array('code' => 'x', 'observed_block' => array('block_names' => array('core/navigation'))));
$assertThrows(
    static fn () => ConversionFindingContract::assertFinding(array('code' => 'x', 'observed_block' => 42)),
    'finding with a numeric observed_block is rejected'
);

// --- Canonical classification derivation -----------------------------------

// The fallback emitter's richer, tag-aware classification is authoritative and
// is never overwritten; only the canonical reason_code is filled from the code.
$svgClassified = ConversionFindingContract::withClassification(array(
    'diagnostic_code'        => 'html_unsafe_inline_svg',
    'pattern_family'         => 'inline_svg',
    'suggested_repair_class' => 'materialize_static_asset',
));
$assert('html_unsafe_inline_svg' === ($svgClassified['reason_code'] ?? null), 'reason_code is derived from diagnostic_code');
$assert('inline_svg' === ($svgClassified['pattern_family'] ?? null), 'existing pattern_family is honored, not overwritten');
$assert('materialize_static_asset' === ($svgClassified['repair_bucket'] ?? null), 'repair_bucket is derived from the producer suggested_repair_class');

// A block-validity finding carries no repair/family signal of its own; the
// contract derives a concrete, non-generic triplet from its stable identifier.
$validityClassified = ConversionFindingContract::withClassification(array(
    'code'       => 'wp_block_validity_warning',
    'severity'   => 'warning',
    'block_name' => 'core/group',
));
$assert('wp_block_validity_warning' === ($validityClassified['reason_code'] ?? null), 'block-validity reason_code is the stable identifier');
$assert('block_serialization' === ($validityClassified['pattern_family'] ?? null), 'block-validity pattern_family is derived structurally');
$assert('block_serialization_validity_repair' === ($validityClassified['repair_bucket'] ?? null), 'block-validity repair_bucket is concrete');

// A semantic-parity finding clusters under a structural sub-family.
$navClassified = ConversionFindingContract::withClassification(array(
    'code' => 'html_semantic_parity_navigation_menu_missing',
));
$assert('navigation_menu' === ($navClassified['pattern_family'] ?? null), 'semantic-parity navigation pattern_family is derived from the structural concept');
$assert('semantic_structure_parity_restoration' === ($navClassified['repair_bucket'] ?? null), 'semantic-parity repair_bucket routes to the parity lane');

// The runtime canvas signal drives the canvas family/bucket without overwriting
// an existing specific repair_bucket.
$canvasClassified = ConversionFindingContract::withClassification(array(
    'code'          => 'runtime_dependency_target_missing',
    'canvas_api'    => true,
    'repair_bucket' => 'runtime_canvas_target_preservation',
));
$assert('runtime_canvas' === ($canvasClassified['pattern_family'] ?? null), 'canvas_api signal drives the runtime_canvas family');
$assert('runtime_canvas_target_preservation' === ($canvasClassified['repair_bucket'] ?? null), 'existing specific repair_bucket is honored');

// classify() never folds per-instance noise (selectors/classes/urls) into the
// clustering keys, so two same-root findings on different elements cluster.
$a = ConversionFindingContract::classify(array('code' => 'runtime_dependency_target_missing', 'selector' => '#alpha'));
$b = ConversionFindingContract::classify(array('code' => 'runtime_dependency_target_missing', 'selector' => '.beta-widget'));
$assert($a === $b, 'same-root findings cluster identically regardless of per-instance selector');

// --- Walk every finding the transformer actually emits ----------------------

/**
 * The generic catch-all reason codes/buckets a downstream classifier would
 * collapse into a single "needs triage" family. The point of this PR is that
 * the transformer no longer emits findings that land here for the common loss
 * types, so the specificity walk treats these as failures.
 */
$genericSentinels = array('', 'generic_finding_family', 'unknown', 'finding', 'unclassified', 'generic', 'review_generic_mapping', 'html_fallback');

/**
 * Assert every finding in a list carries a concrete, non-generic classification
 * triplet (reason_code / repair_bucket / pattern_family). This is the guard that
 * keeps the transformer's findings swarmable: a missing or generic value here is
 * exactly what previously bucketed 143/173 corpus findings as generic.
 *
 * @param array<int, array<string, mixed>> $findings
 */
$assertClassified = static function (array $findings, string $context) use ($assert, $genericSentinels): void {
    foreach ( array_values($findings) as $index => $finding ) {
        if ( ! is_array($finding) ) {
            continue;
        }
        foreach ( ConversionFindingContract::CLASSIFICATION_FIELDS as $field ) {
            $value = $finding[$field] ?? '';
            $assert(
                is_string($value) && '' !== trim($value) && ! in_array($value, $genericSentinels, true),
                "{$context}.{$index}: finding carries a specific, non-generic {$field}",
                sprintf('code=%s %s=%s', ConversionFindingContract::findingCode($finding), $field, var_export($value, true))
            );
        }
    }
};

// --- Walk every finding the transformer actually emits ----------------------

/**
 * Collect every conversion finding carried by a transformer/compiler result,
 * across all producers (flat diagnostics, fallbacks, and the report-scoped
 * findings), and assert each one conforms to the contract. Also verifies the
 * report-level `finding_schema` stamp is present and versioned.
 *
 * @param array<string, mixed> $result
 */
$walk = static function (array $result, string $context) use ($assert, $assertClassified): int {
    $count = 0;

    $diagnostics = $result['diagnostics'] ?? array();
    $assert(is_array($diagnostics), "{$context}: diagnostics is an array");
    ConversionFindingContract::assertFindings(array_values($diagnostics), "{$context} diagnostics");
    $assertClassified(array_values($diagnostics), "{$context} diagnostics");
    $count += count($diagnostics);

    $fallbacks = $result['fallbacks'] ?? array();
    $assert(is_array($fallbacks), "{$context}: fallbacks is an array");
    ConversionFindingContract::assertFindings(array_values($fallbacks), "{$context} fallbacks");
    $assertClassified(array_values($fallbacks), "{$context} fallbacks");
    $count += count($fallbacks);

    $sourceReports = is_array($result['source_reports'] ?? null) ? $result['source_reports'] : array();

    $reportFindingPaths = array(
        'semantic_parity'           => $sourceReports['semantic_parity'] ?? null,
        'runtime_dependency_parity' => $sourceReports['runtime_dependency_parity'] ?? null,
    );
    foreach ( $reportFindingPaths as $name => $report ) {
        if ( ! is_array($report) ) {
            continue;
        }

        $assert(
            ConversionFindingContract::SCHEMA === ($report['finding_schema'] ?? null),
            "{$context}: {$name} report stamps the finding_schema version"
        );

        $findings = $report['findings'] ?? array();
        $assert(is_array($findings), "{$context}: {$name} findings is an array");
        ConversionFindingContract::assertFindings(array_values($findings), "{$context} {$name} findings");
        $assertClassified(array_values($findings), "{$context} {$name} findings");
        $count += count($findings);
    }

    $conversionReport = $sourceReports['conversion_report'] ?? null;
    if ( is_array($conversionReport) ) {
        $assert(
            ConversionFindingContract::SCHEMA === ($conversionReport['finding_schema'] ?? null),
            "{$context}: conversion report stamps the finding_schema version"
        );

        $fallbackDiagnostics = $conversionReport['fallback_diagnostics'] ?? array();
        $assert(is_array($fallbackDiagnostics), "{$context}: conversion report fallback_diagnostics is an array");
        ConversionFindingContract::assertFindings(array_values($fallbackDiagnostics), "{$context} conversion report fallback_diagnostics");
        $assertClassified(array_values($fallbackDiagnostics), "{$context} conversion report fallback_diagnostics");
        $count += count($fallbackDiagnostics);
    }

    return $count;
};

$htmlCases = array(
    'unsafe-inline-svg'   => '<main><svg onload="alert(1)"><path d="M0 0h1v1z"></path></svg></main>',
    'script-fallback'     => '<main><div id="widget">Hi</div><script>document.getElementById("widget").addEventListener("click", function () { window.__x = 1; });</script></main>',
    'template-fallback'   => '<main><template id="card"><div data-role="card"><script>render()</script></div></template></main>',
    'iframe-embed'        => '<main><iframe src="https://example.com/widget"></iframe></main>',
    'custom-iframe-gap'   => '<main><vendor-iframe data-widget-id="comp-runtime" width="640" height="360"></vendor-iframe></main>',
    'unsupported-element' => '<main><marquee>Scrolling</marquee></main>',
    'header-footer-nav'   => '<body><header><nav><a href="/a">A</a><a href="/b">B</a></nav></header><main><p>Body</p></main><footer><nav><a href="/c">C</a><a href="/d">D</a></nav></footer></body>',
);

$totalFindings = 0;
foreach ( $htmlCases as $name => $html ) {
    $result = ( new HtmlTransformer() )->transform($html)->toArray();
    $totalFindings += $walk($result, "html:{$name}");
}

// Canvas requires a runtime selector hint to be flagged as a runtime island.
$canvasResult = ( new HtmlTransformer() )->transform(
    '<main><canvas id="scene">Fallback</canvas><script>document.getElementById("scene").getContext("2d");</script></main>',
    array('runtime_canvas_selectors' => array('#scene'))
)->toArray();
$totalFindings += $walk($canvasResult, 'html:canvas-runtime');

// Artifact compilation exercises the runtime-dependency parity producer: a
// shared first-party script may reference a selector absent from the current
// entry page. That dependency is recorded, but it is not a missing-target
// finding for the entry conversion.
$artifactResult = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files'      => array(
        'index.html' => '<main><div class="hero">Welcome</div><script src="js/app.js"></script></main>',
        'js/app.js'  => 'document.getElementById("ghost").addEventListener("click", function () { window.__y = 1; });',
    ),
))->toArray();
$totalFindings += $walk($artifactResult, 'artifact:runtime-dependency');

$runtimeDependencyParity = $artifactResult['source_reports']['runtime_dependency_parity'] ?? array();
$assert(
    'pass' === ($runtimeDependencyParity['status'] ?? ''),
    'artifact runtime dependency parity does not flag selectors absent from the entry source'
);
$assert(
    array() !== ($runtimeDependencyParity['dependencies'] ?? array()),
    'artifact runtime dependency parity records the shared-script dependency row'
);

$emptyDocumentResult = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'website/index.html',
    'files'      => array(
        'website/index.html' => '',
        'website/about.html' => '',
    ),
))->toArray();
$totalFindings += $walk($emptyDocumentResult, 'artifact:empty-documents');
$emptyDocumentFindings = array_values(array_filter(
    $emptyDocumentResult['diagnostics'],
    static fn (array $diagnostic): bool => 'wordpress_site_plan_not_self_contained' === ConversionFindingContract::findingCode($diagnostic)
));
$assert(2 === count($emptyDocumentFindings), 'empty compiled documents emit per-document identity findings');
$emptyDocumentClassified = ConversionFindingContract::classify(array('code' => 'wordpress_site_plan_not_self_contained'));
$assert('site_plan_document' === ($emptyDocumentClassified['pattern_family'] ?? null), 'empty-document findings cluster under site_plan_document');
$assert('restore_compiled_document_identity' === ($emptyDocumentClassified['repair_bucket'] ?? null), 'empty-document findings route to a concrete identity repair bucket');

$assert($totalFindings > 0, 'the contract walk validated a non-empty set of real conversion findings');

fwrite(STDOUT, "Conversion finding contract passed: {$totalFindings} finding(s) validated.\n");
