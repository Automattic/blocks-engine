<?php
declare(strict_types=1);

/**
 * Unit tests for the responsive counterpart correspondence contract (issue #1572).
 *
 * Plain-PHP test script in the style of tests/unit/custom-block-generator.php.
 * Proves a correspondence is declared ONLY from stable source provenance (a
 * bounded id, unique per document, with matching tags) plus responsive-document
 * structure — never from equal text, labels, order, or visual similarity — and
 * that the declaration persists into serialized markup, reports, and the
 * bounded editor module.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
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

$desktopHtml = '<!doctype html><html><head><style>body.desktop{display:grid;min-width:980px}h1{color:red}</style></head>'
    . '<body class="desktop"><main>'
    . '<h1 id="hero-title">Desktop title</h1>'
    . '<p>Same words</p>'
    . '<p id="lede">Desktop lede</p>'
    . '<p>Repeated lede echo</p>'
    . '<h2 id="mismatched-kind">Desktop heading</h2>'
    . '<p id="duplicated-id">First</p>'
    . '<button id="join-cta">Join desktop</button>'
    . '<p id="cta-inline">Desktop plain inline</p>'
    . '</main></body></html>';
$mobileHtml = '<!doctype html><html><head><style>body.mobile{display:flex;min-width:320px}h1{color:blue}</style></head>'
    . '<body class="mobile"><main>'
    . '<p>Repeated lede echo</p>'
    . '<h1 id="hero-title">Mobile title</h1>'
    . '<div id="depth-outer" class="blocks-engine-source-div-outer-3"><section id="depth-branch" class="blocks-engine-source-section-branch-3"><p id="lede">Mobile lede</p><p>Second branch</p></section></div>'
    . '<p>Same words</p>'
    . '<p id="mismatched-kind">Mobile paragraph</p>'
    . '<p id="duplicated-id">First</p><p id="duplicated-id">Second</p>'
    . '<button id="join-cta">Join mobile</button>'
    . '<table><tr><td><p id="cta-inline">Mobile absorbed inline</p></td></tr></table>'
    . '</main></body></html>';

$artifact = array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'website/index.html',
    'document_variants' => array(
        array(
            'source_path' => 'website/index.html',
            'variants' => array(
                array(
                    'id' => 'mobile',
                    'source_path' => 'website/.variants/mobile/index.html',
                    'media' => '(max-width: 768px)',
                ),
            ),
        ),
    ),
    'files' => array(
        array( 'path' => 'website/index.html', 'content' => $desktopHtml ),
        array( 'path' => 'website/.variants/mobile/index.html', 'role' => 'document_variant', 'content' => $mobileHtml ),
    ),
);

$compiler  = new ArtifactCompiler();
$result    = $compiler->compile($artifact)->toArray();
$contracts = $result['source_reports']['responsive_counterpart_contracts'] ?? null;
$markup    = (string) ($result['serialized_blocks'] ?? '');

// ---------------------------------------------------------------------------
// 1. Declared pairs: stable source id + responsive-document structure only.
// ---------------------------------------------------------------------------
$assert(is_array($contracts), '1: compiling paired documents emits a correspondence contract report');
$assert('blocks-engine/responsive-counterpart-contracts/v1' === ($contracts['schema'] ?? ''), '1: contract report carries its schema');
$assert('stable_source_id_and_variant_structure_only' === ($contracts['pairing_rule'] ?? ''), '1: contract records the evidence-only pairing rule');
$counterparts = is_array($contracts['counterparts'] ?? null) ? $contracts['counterparts'] : array();
$byToken      = array_column($counterparts, null, 'token');
$assert(3 === count($counterparts), '1: exactly the three provenance-proven pairs are declared (hero-title, lede, join-cta)', json_encode(array_column($counterparts, 'source_id')));
$assert(3 === ($contracts['metrics']['declared_count'] ?? 0), '1: declared_count matches the emitted counterparts');

$hero = $byToken['be-responsive-counterpart-' . substr(hash('sha256', "mobile\0hero-title"), 0, 12)] ?? array();
$assert(array('kind' => 'text', 'attribute' => 'content', 'source_id' => 'hero-title') === array_intersect_key($hero, array('kind' => true, 'attribute' => true, 'source_id' => true)), '1: hero pair is a text correspondence derived from its source id', json_encode($hero));
$assert('core/heading' === ($hero['variants']['default']['block_name'] ?? '') && 'core/heading' === ($hero['variants']['mobile']['block_name'] ?? ''), '1: hero contract carries both variant block names');
$assert('hero-title' === ($hero['variants']['default']['anchor'] ?? '') && 'hero-title' === ($hero['variants']['mobile']['anchor'] ?? ''), '1: both sides persist their stable source anchor');
$assert('blocks.0' === ($hero['variants']['default']['block_path'] ?? '') || str_starts_with((string) ($hero['variants']['default']['block_path'] ?? ''), 'blocks.'), '1: default side resolves under the default variant root');
$assert(str_starts_with((string) ($hero['variants']['mobile']['block_path'] ?? 'missing'), 'blocks.'), '1: mobile side resolves a concrete block path');

$lede = $byToken['be-responsive-counterpart-' . substr(hash('sha256', "mobile\0lede"), 0, 12)] ?? array();
$assert('' !== ($lede['variants']['mobile']['block_path'] ?? ''), '1: a paired paragraph inside a projected layout-shell chain still declares its correspondence');

$cta = $byToken['be-responsive-counterpart-' . substr(hash('sha256', "mobile\0join-cta"), 0, 12)] ?? array();
$assert(array('kind' => 'link', 'attribute' => 'text') === array_intersect_key($cta, array('kind' => true, 'attribute' => true)), '1: button pair declares a link correspondence on the compatible text attribute');

// ---------------------------------------------------------------------------
// 2. Independence: equal text, order, or shape never pairs.
// ---------------------------------------------------------------------------
$tokenClasses = array_column($counterparts, 'token');
foreach ( $tokenClasses as $token ) {
    preg_match_all('/<!-- wp:[a-z0-9\/-]+ \{[^}]*' . $token . '/', $markup, $tokenBlockHeaders);
    $assert(2 === count($tokenBlockHeaders[0]), '2: declared token ' . $token . ' persists on exactly the two paired blocks');
}
$assert(false === strpos($markup, '>Same words<</p>') || 2 === substr_count($markup, '>Same words<'), '2: same-text paragraphs on both sides serialize independently');
foreach ( array('Same words', 'Repeated lede echo') as $echoText ) {
    $before = null;
    preg_match_all('/<p([^>]*)>' . preg_quote($echoText, '/') . '<\/p>/i', $markup, $echoMatches);
    foreach ( $echoMatches[1] as $echoClassAttribute ) {
        $carriesToken = (bool) preg_match('/be-responsive-counterpart-[a-f0-9]{12}/', $echoClassAttribute);
        $assert(!$carriesToken, '2: identical text never establishes a correspondence');
        $before = true;
    }
    $assert(null !== $before, '2: the repeated text fixture survived serialization for ' . $echoText);
}

$mismatchTokens = preg_grep('/' . preg_quote(substr(hash('sha256', "mobile\0mismatched-kind"), 0, 12), '/') . '/', $tokenClasses);
$assert(array() === $mismatchTokens, '2: the same id on mismatched tags (h2 vs p) never pairs');
$duplicateTokens = preg_grep('/' . preg_quote(substr(hash('sha256', "mobile\0duplicated-id"), 0, 12), '/') . '/', $tokenClasses);
$assert(array() === $duplicateTokens, '2: an id duplicated inside one document stays unpaired everywhere');

$declined = $contracts['declined'] ?? array();
$inlineCtaToken = 'be-responsive-counterpart-' . substr(hash('sha256', "mobile\0cta-inline"), 0, 12);
$inlineDeclined = null;
foreach ( $declined as $declinedEntry ) {
    if ( ($declinedEntry['token'] ?? '') === $inlineCtaToken ) $inlineDeclined = $declinedEntry;
}
$assert(is_array($inlineDeclined), '2: a token whose mobile element was absorbed into a table cell is declined', json_encode($declined));
$assert('single_structure_occurrence' === ($inlineDeclined['reason'] ?? ''), '2: decline reason records the failed structural proof');
$assert(1 === ($contracts['metrics']['declined_count'] ?? 0), '2: exactly the structurally unproven token is declined');

// ---------------------------------------------------------------------------
// 3. Geometry independence: token classes carry no styles.
// ---------------------------------------------------------------------------
$assetCss = implode("\n", array_map(
    static fn(array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($result['assets'] ?? array(), 'is_array')
));
$assert(!str_contains($assetCss, 'be-responsive-counterpart-'), '3: correspondence tokens introduce no CSS rules');
$assert(str_contains($assetCss, '@media (max-width: 768px)') && str_contains($assetCss, '@scope (.site-document-variant-mobile)'), '3: desktop/mobile document geometry keeps its independent scoped styles');
$assert(str_contains($markup, 'site-document-variant-default') && str_contains($markup, 'site-document-variant-mobile'), '3: both document roots remain independent wrappers');

// ---------------------------------------------------------------------------
// 4. Persisted bounded editor module.
// ---------------------------------------------------------------------------
$module = $contracts['editor_module'] ?? null;
$assert(is_array($module), '4: declared contracts ship one bounded editor module');
$script = (string) ($module['content'] ?? '');
$assert('blocks-engine-responsive-counterparts' === ($module['handle'] ?? ''), '4: module declares its handle');
$assert(array('wp-hooks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-notices') === ($module['script_dependencies'] ?? null), '4: module declares bounded WordPress dependencies');
$assert(str_contains($script, "hooks.addFilter( 'editor.BlockEdit'"), '4: module extends the block editor through the supported filter seam');
$assert(str_contains($script, '1 !== matches.length') && str_contains($script, 'return null;'), '4: module requires exactly one counterpart block before acting');
$assert(str_contains($script, "data.dispatch( 'core/block-editor' ).updateBlockAttributes"), '4: applying a change targets the counterpart through the editor store');
$assert(str_contains($script, "data.dispatch( 'core/notices' ).createNotice"), '4: every applied change surfaces an auditable editor notice');
$assert(str_contains($script, 'CONTENT_ATTRIBUTES') && !str_contains($script, 'setAttributes'), '4: module never mutates the selected block, only the declared counterpart attribute');
$payload = $result['source_reports']['companion_plugin_payload'] ?? array();
$editorScripts = is_array($payload['editor_scripts'] ?? null) ? $payload['editor_scripts'] : array();
$assert(1 === count($editorScripts) && 'blocks-engine-responsive-counterparts' === ($editorScripts[0]['handle'] ?? '') && $script === ($editorScripts[0]['content'] ?? '') && ($module['script_dependencies'] ?? null) === ($editorScripts[0]['dependencies'] ?? null), '4: the declared editor module is delivered through the generic companion editor-script contract');

$plainArtifact = $artifact;
unset($plainArtifact['document_variants']);
$plainResult = (new ArtifactCompiler())->compile($plainArtifact)->toArray();
$assert(!isset($plainResult['source_reports']['responsive_counterpart_contracts']), '4: documents without declared variants emit no contract report or editor module');

$noPairArtifact = $artifact;
$noPairArtifact['files'][0]['content'] = str_replace(' id="hero-title"', '', $desktopHtml);
$noPairArtifact['files'][1]['content'] = str_replace(' id="hero-title"', '', $mobileHtml);
$noPairResult = (new ArtifactCompiler())->compile($noPairArtifact)->toArray();
$noPairContracts = $noPairResult['source_reports']['responsive_counterpart_contracts'] ?? array();
$assert(2 === ($noPairContracts['metrics']['declared_count'] ?? 0), '4: removing one stable id removes exactly that pair');
$assert(in_array('hero-title', array_column($noPairContracts['counterparts'] ?? array(), 'source_id'), true) === false, '4: the removed id no longer declares a correspondence');
$assert(isset($noPairContracts['editor_module']), '4: the editor module ships only alongside remaining declared pairs');

// ---------------------------------------------------------------------------
// 5. Determinism and staged parity.
// ---------------------------------------------------------------------------
$repeat = (new ArtifactCompiler())->compile($artifact)->toArray();
$assert(json_encode($contracts) === json_encode($repeat['source_reports']['responsive_counterpart_contracts'] ?? null) && $markup === (string) ($repeat['serialized_blocks'] ?? ''), '5: contracts and markup are deterministic across compilations');
$sharedPlan = $compiler->prepareShared($artifact);
$pagePlan   = $compiler->preparePage($artifact, $sharedPlan, 'website/index.html');
$staged     = $compiler->compose($sharedPlan, array($pagePlan))->toArray();
$assert(json_encode($contracts) === json_encode($staged['source_reports']['responsive_counterpart_contracts'] ?? null), '5: staged compilation declares identical contracts');
$assert($markup === (string) ($staged['serialized_blocks'] ?? ''), '5: staged compilation serializes identical paired markup');

// ---------------------------------------------------------------------------
// 6. Editability report surfaces the declared correspondence structure.
// ---------------------------------------------------------------------------
$metrics = $result['source_reports']['editability_report']['metrics'] ?? array();
$assert(7 === ($metrics['responsive_counterpart_count'] ?? null), '6: editability metrics count every block carrying a persisted declaration mark (6 paired + 1 structure-declined)', json_encode($metrics));
$shellSignals = array_values(array_filter(
    ($result['source_reports']['editability_report']['documents'][0]['signals'] ?? array()),
    static fn(array $signal): bool => 'layout_shell' === ($signal['kind'] ?? '')
));
$assert(1 === count($shellSignals), '6: the projected mobile wrapper chain is reported as a custom/layout-shell wrapper structure');
$assert(2 === ($shellSignals[0]['wrapper_count'] ?? null) && 2 === ($shellSignals[0]['editable_descendant_count'] ?? null), '6: the shell signal distinguishes layout-only wrappers from its editable descendants', json_encode($shellSignals[0] ?? array()));

// ---------------------------------------------------------------------------
// 7. Standalone transformer: only composed documents carry pairings.
// ---------------------------------------------------------------------------
$standalone = (new HtmlTransformer())->transform($desktopHtml)->toArray();
$assert(!isset($standalone['source_reports']['responsive_counterpart_contracts']), '7: the plain transformer declares nothing without a composed responsive document');

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "Responsive counterpart contract tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Responsive counterpart contract tests: {$passes} passed" . PHP_EOL);
