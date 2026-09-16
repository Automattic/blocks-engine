<?php
declare(strict_types=1);

/**
 * HtmlResultComposer is exercised without constructing an HtmlTransformer.
 *
 * Result assembly (parse-failed, empty-body, diagnostics, source reports,
 * metrics, TransformerResult) used to live in HtmlCompilation::transform().
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\BlockCompilationOutput;
use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionReportProjection;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlResultComposer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$assert(! is_a(HtmlResultComposer::class, HtmlCompilation::class, true), 'composer-is-not-htmlcompilation');
$assert(! is_a(HtmlResultComposer::class, HtmlTransformer::class, true), 'composer-is-not-htmltransformer');

$composer = new HtmlResultComposer();
$cache = new CssSelectorMatchCache();
$startedAt = hrtime(true);
$html = '<p>broken';
$provenanceFallback = array('source' => 'fixture.html', 'scope' => 'page');
$provenance = array(array('source_format' => 'html', 'input_bytes' => strlen($html), 'transformer' => HtmlTransformer::class));
$context = array('strict' => false, 'allow_fallbacks' => true);

$parseFailed = $composer->parseFailed($html, $provenanceFallback, $provenance, $context, $startedAt, $cache);
$assert('html_parse_failed' === ($parseFailed->diagnostics[0]['code'] ?? null), 'parse-failed-diagnostic-code');
$assert('Unable to parse HTML input.' === ($parseFailed->diagnostics[0]['message'] ?? null), 'parse-failed-diagnostic-message');
$assert(HtmlTransformer::class === ($parseFailed->diagnostics[0]['source'] ?? null), 'parse-failed-diagnostic-source');
$expectedFallback = FallbackDiagnostic::build(array(
    'type'            => 'html',
    'reason'          => 'parse_failed',
    'diagnostic_code' => 'html_parse_failed',
    'source_format'   => 'html',
    'html'            => $html,
), $provenanceFallback);
$assert($expectedFallback === ($parseFailed->fallbacks[0] ?? null), 'parse-failed-fallback-matches-diagnostic-builder');
$assert(ConversionReportProjection::SCHEMA === ($parseFailed->sourceReports['conversion_report']['schema'] ?? null), 'parse-failed-conversion-report-schema');
$assert('html' === ($parseFailed->sourceReports['conversion_report']['source_format'] ?? null), 'parse-failed-conversion-report-source-format');
$assert($provenance === $parseFailed->provenance, 'parse-failed-provenance');
$assert($context === $parseFailed->context, 'parse-failed-context');
$assert(strlen($html) === ($parseFailed->metrics['input_bytes'] ?? null), 'parse-failed-input-bytes');
$assert(1 === ($parseFailed->metrics['fallback_count'] ?? null), 'parse-failed-fallback-count');
$assert(1 === ($parseFailed->metrics['diagnostic_count'] ?? null), 'parse-failed-diagnostic-count');
$assert(0 === ($parseFailed->metrics['block_count'] ?? null), 'parse-failed-block-count');
$assert(is_float($parseFailed->metrics['transform_duration_ms'] ?? null) && 0 <= $parseFailed->metrics['transform_duration_ms'], 'parse-failed-duration');
$assert(null !== $parseFailed->blockCompilationOutput, 'parse-failed-has-empty-compilation-output');
$assert(array() === $parseFailed->blocks, 'parse-failed-has-no-blocks');
$assert('' === $parseFailed->serializedBlocks, 'parse-failed-has-no-serialized-blocks');

$emptyBody = $composer->emptyBody($html, $provenance, $context, $startedAt, $cache);
$assert(array() === $emptyBody->diagnostics, 'empty-body-has-no-diagnostics');
$assert(array() === $emptyBody->fallbacks, 'empty-body-has-no-fallbacks');
$assert(ConversionReportProjection::SCHEMA === ($emptyBody->sourceReports['conversion_report']['schema'] ?? null), 'empty-body-conversion-report-schema');
$assert(0 === ($emptyBody->metrics['fallback_count'] ?? null), 'empty-body-fallback-count');
$assert(0 === ($emptyBody->metrics['diagnostic_count'] ?? null), 'empty-body-diagnostic-count');
$assert($provenance === $emptyBody->provenance, 'empty-body-provenance');

$appended = $composer->diagnostics(array(
    'diagnostics' => array(array('code' => 'html_to_blocks_core_slice')),
    'responsive_geometry_ambiguities' => array(array('selector' => '.shell', 'min_width' => '90rem')),
    'responsive_height_ambiguities' => array(array('selector' => '.hero', 'height' => '100%')),
    'head_metadata' => array(array('name' => 'description', 'content' => 'Site')),
    'author_layout_topology_findings' => array(array('selector' => '.grid', 'source_child_count' => 3, 'block_child_count' => 2)),
    'has_description_list_block' => true,
    'source' => HtmlTransformer::class,
));
$codes = array_column($appended, 'code');
$assert(array(
    'html_to_blocks_core_slice',
    'responsive_geometry_ambiguous_min_width',
    'responsive_geometry_ambiguous_percentage_height',
    'html_head_metadata_not_carried',
    'author_layout_topology_changed',
    'semantic_description_list_gutenberg_gap',
) === $codes, 'diagnostics-append-order');
$assert('.shell' === ($appended[1]['selector'] ?? null) && '90rem' === ($appended[1]['min_width'] ?? null), 'responsive-geometry-fields');
$assert('.hero' === ($appended[2]['selector'] ?? null) && '100%' === ($appended[2]['height'] ?? null), 'responsive-height-fields');
$assert(array(array('name' => 'description', 'content' => 'Site')) === ($appended[3]['entries'] ?? null), 'head-metadata-entries');
$assert(3 === ($appended[4]['source_child_count'] ?? null) && 2 === ($appended[4]['block_child_count'] ?? null), 'author-layout-topology-fields');
$assert(array('https://github.com/WordPress/gutenberg/issues/4880', 'https://github.com/WordPress/gutenberg/pull/20760') === ($appended[5]['references'] ?? null), 'description-list-references');

$unchanged = $composer->diagnostics(array(
    'diagnostics' => array(array('code' => 'html_to_blocks_core_slice')),
    'responsive_geometry_ambiguities' => array(),
    'responsive_height_ambiguities' => array(),
    'head_metadata' => array(),
    'author_layout_topology_findings' => array(),
    'has_description_list_block' => false,
    'source' => HtmlTransformer::class,
));
$assert(array(array('code' => 'html_to_blocks_core_slice')) === $unchanged, 'diagnostics-identity-when-empty');

$blocks = array(
    array(
        'blockName' => 'core/group',
        'attrs' => array(),
        'innerBlocks' => array(
            array('blockName' => 'core/paragraph', 'attrs' => array('content' => 'Hi'), 'innerBlocks' => array()),
        ),
    ),
);
$fallbacks = array(array('diagnostic_code' => 'html_unsupported_element', 'selector' => 'canvas'));
$compilation = new BlockCompilationOutput(
    sourceProvenance: array(array('block_path' => 'blocks.0', 'selector' => 'div')),
    editabilityReport: array('schema' => 'test'),
    authorStylesheetProjections: array(array('selector' => '.hero')),
    runtimeScriptProjections: array(),
    responsiveCounterpartContracts: array()
);
$successInput = array(
    'source' => HtmlTransformer::class,
    'html' => '<div><p>Hi</p></div>',
    'blocks' => $blocks,
    'serialized_blocks' => '<!-- wp:group --><!-- wp:paragraph --><!-- /wp:paragraph --><!-- /wp:group -->',
    'assets' => array(array('kind' => 'css', 'content' => 'p{color:red}')),
    'fallbacks' => $fallbacks,
    'provenance' => $provenance,
    'context' => array('strict' => false, 'allow_fallbacks' => true),
    'started_at' => $startedAt,
    'selector_cache' => $cache,
    'diagnostics' => array(array('code' => 'html_to_blocks_core_slice')),
    'supported_blocks' => array('core/paragraph', 'core/group'),
    'native_target_blocks' => array('core/paragraph'),
    'bundled_snapshot_blocks' => array('core/paragraph', 'core/verse'),
    'runtime_registered_blocks' => null,
    'capability_matrix' => array('supported_blocks' => array('core/paragraph', 'core/group')),
    'head_metadata' => array(),
    'runtime_dom_contracts' => array(),
    'runtime_dom_fallbacks' => array(),
    'block_validity_report' => array('status' => 'passed'),
    'semantic_parity_report' => array('status' => 'passed'),
    'content_round_trip_report' => array('status' => 'passed'),
    'presentation_signals' => array(),
    'frozen_hidden_state' => array(),
    'dropped_link_wrappers' => array(),
    'gutenberg_incompatibilities' => array(),
    'author_layout_topology_findings' => array(),
    'structure_signals' => array(),
    'script_metadata' => array(),
    'source_target_projections' => array(),
    'responsive_geometry_ambiguities' => array(),
    'responsive_height_ambiguities' => array(),
    'has_description_list_block' => false,
);
$success = $composer->result($successInput, $compilation);
$assert('success' === $success->status, 'result-status-allow-fallbacks');
$assert($blocks === $success->blocks, 'result-blocks');
$assert($successInput['serialized_blocks'] === $success->serializedBlocks, 'result-serialized-blocks');
$assert($successInput['assets'] === $success->assets, 'result-assets');
$assert($fallbacks === $success->fallbacks, 'result-fallbacks');
$assert(2 === ($success->metrics['block_count'] ?? null), 'result-recursive-block-count');
$assert(strlen($successInput['serialized_blocks']) === ($success->metrics['output_bytes'] ?? null), 'result-output-bytes');
$assert(array('core/paragraph', 'core/group') === ($success->coverage[0]['supported_blocks'] ?? null), 'result-coverage-supported-blocks');
$assert(1 === ($success->coverage[0]['block_count'] ?? null), 'result-coverage-uses-top-level-block-count');
$assert(1 === ($success->coverage[0]['source_provenance_count'] ?? null), 'result-coverage-provenance-count');
$assert(array('core/paragraph') === ($success->sourceReports['native_target_blocks'] ?? null), 'result-source-reports-native-targets');
$assert(array('core/paragraph') === ($success->sourceReports['available_core_blocks'] ?? null), 'result-source-reports-available-core-blocks-alias');
$assert(array('status' => 'passed') === ($success->sourceReports['wp_block_validity'] ?? null), 'result-source-reports-validity');
$assert(array(array('block_path' => 'blocks.0', 'selector' => 'div')) === ($success->sourceReports['html']['source_provenance'] ?? null), 'result-html-source-provenance');
$assert(array(array('selector' => '.hero')) === ($success->sourceReports['author_stylesheet_projections'] ?? null), 'result-includes-nonempty-stylesheet-projections');
$assert(! array_key_exists('runtime_script_projections', $success->sourceReports), 'result-omits-empty-script-projections');
$assert(! array_key_exists('responsive_counterpart_contracts', $success->sourceReports), 'result-omits-empty-responsive-contracts');
$assert(ConversionReportProjection::SCHEMA === ($success->sourceReports['conversion_report']['schema'] ?? null), 'result-conversion-report-schema');
$assert($compilation === $success->blockCompilationOutput, 'result-compilation-output');

$warned = $composer->result(array_merge($successInput, array(
    'context' => array('strict' => false, 'allow_fallbacks' => false),
)), $compilation);
$assert('success_with_warnings' === $warned->status, 'result-status-warnings');

$failed = $composer->result(array_merge($successInput, array(
    'context' => array('strict' => true, 'allow_fallbacks' => false),
)), $compilation);
$assert('failed' === $failed->status, 'result-status-failed');

$clean = $composer->result(array_merge($successInput, array(
    'fallbacks' => array(),
    'context' => array('strict' => true, 'allow_fallbacks' => false),
)), $compilation);
$assert('success' === $clean->status, 'result-status-success-without-fallbacks');

if ( 0 < $failures ) {
    fwrite(STDERR, $failures . " html-result-composer failure(s), " . $passes . " passed\n");
    exit(1);
}

echo "html-result-composer ok\n";
