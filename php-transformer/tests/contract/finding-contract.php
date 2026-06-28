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

// --- Walk every finding the transformer actually emits ----------------------

/**
 * Collect every conversion finding carried by a transformer/compiler result,
 * across all producers (flat diagnostics, fallbacks, and the report-scoped
 * findings), and assert each one conforms to the contract. Also verifies the
 * report-level `finding_schema` stamp is present and versioned.
 *
 * @param array<string, mixed> $result
 */
$walk = static function (array $result, string $context) use ($assert): int {
    $count = 0;

    $diagnostics = $result['diagnostics'] ?? array();
    $assert(is_array($diagnostics), "{$context}: diagnostics is an array");
    ConversionFindingContract::assertFindings(array_values($diagnostics), "{$context} diagnostics");
    $count += count($diagnostics);

    $fallbacks = $result['fallbacks'] ?? array();
    $assert(is_array($fallbacks), "{$context}: fallbacks is an array");
    ConversionFindingContract::assertFindings(array_values($fallbacks), "{$context} fallbacks");
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
        $count += count($fallbackDiagnostics);
    }

    return $count;
};

$htmlCases = array(
    'unsafe-inline-svg'   => '<main><svg onload="alert(1)"><path d="M0 0h1v1z"></path></svg></main>',
    'script-fallback'     => '<main><div id="widget">Hi</div><script>document.getElementById("widget").addEventListener("click", function () { window.__x = 1; });</script></main>',
    'template-fallback'   => '<main><template id="card"><div data-role="card"><script>render()</script></div></template></main>',
    'iframe-embed'        => '<main><iframe src="https://example.com/widget"></iframe></main>',
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
// first-party script references a DOM target that the generated markup drops.
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
    'warning' === ($runtimeDependencyParity['status'] ?? ''),
    'artifact runtime dependency parity flags the missing DOM target'
);
$assert(
    array() !== ($runtimeDependencyParity['findings'] ?? array()),
    'artifact runtime dependency parity emits at least one finding to validate'
);

$assert($totalFindings > 0, 'the contract walk validated a non-empty set of real conversion findings');

fwrite(STDOUT, "Conversion finding contract passed: {$totalFindings} finding(s) validated.\n");
