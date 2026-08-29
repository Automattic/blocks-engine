<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

/**
 * Each unit contributes an exact two-wrapper projected branch chain: a
 * single-child outer group holding a branch group with two children (a
 * paragraph and the next unit). Each pair has the same safe representation
 * regardless of unrelated document depth, so every unit compresses to one
 * layout shell. No layout-geometry proof accompanies the artifact.
 */
$units = 12;
$deepMarkup = '<h3>Depth leaf heading</h3><p>Depth leaf paragraph</p>';
for ($unit = $units; 1 <= $unit; $unit--) {
    $deepMarkup = '<div id="dp-outer-' . $unit . '" class="blocks-engine-source-div-dp-outer' . $unit . '-1">'
        . '<div id="dp-branch-' . $unit . '" class="blocks-engine-source-div-dp-branch' . $unit . '-1">'
        . $deepMarkup
        . '</div></div>';
    if (1 < $unit) {
        $deepMarkup = '<p>Depth unit ' . $unit . ' content</p>' . $deepMarkup;
    }
}

$deepArtifact = array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'website/index.html',
    'files' => array(
        array(
            'path' => 'website/index.html',
            'content' => '<!doctype html><html><head></head><body>' . $deepMarkup . '</body></html>',
        ),
    ),
);

$deepResult = (new ArtifactCompiler())->compile($deepArtifact)->toArray();
$deepCodes = array_column($deepResult['diagnostics'] ?? array(), 'code');
$deepQuality = $deepResult['source_reports']['wordpress_site_plan']['quality'] ?? array();
$deepMetrics = $deepResult['source_reports']['editability_report']['metrics'] ?? array();
$deepBlocks = (string) ($deepResult['serialized_blocks'] ?? '');

$assert(!in_array('editability_policy_failed', $deepCodes, true), 'A proof-free page over the depth cap compiles without an editability policy failure.');
$assert('failed' !== ($deepResult['status'] ?? null), 'A proof-free page over the depth cap does not fail the whole compile.');
$assert(true === ($deepQuality['pass'] ?? null) && 'failed' !== ($deepQuality['status'] ?? null), 'The canonical plan quality gate passes without layout-geometry proofs.');
$assert('passed' === ($deepQuality['editability_policy']['status'] ?? null), 'The plan editability policy verdict is passed, not failed.');
$assert(is_int($deepMetrics['max_nesting_depth'] ?? null) && 20 >= $deepMetrics['max_nesting_depth'], 'Deterministic branch compression brings the measured nesting depth within the editability maximum.');
$assert($units === substr_count($deepBlocks, '<!-- wp:custom/layout-shell'), 'Every two-wrapper projected branch becomes a layout shell.');
$assert(str_contains($deepBlocks, '>Depth leaf heading<') && str_contains($deepBlocks, '>Depth leaf paragraph<') && str_contains($deepBlocks, '>Depth unit 2 content<'), 'Compressed output preserves the deep editable content.');
$assert(str_contains($deepBlocks, 'id="dp-outer-1"') && str_contains($deepBlocks, 'id="dp-branch-' . $units . '"'), 'Compressed layout shells retain the source wrapper identities.');

// The identical shallow subtree must use the same representation.
$shallowMarkup = '<div id="dp-outer-1" class="blocks-engine-source-div-dp-outer1-1">'
    . '<div id="dp-branch-1" class="blocks-engine-source-div-dp-branch1-1">'
    . '<h3>Depth leaf heading</h3><p>Depth leaf paragraph</p>'
    . '</div></div>';
$shallowArtifact = array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'website/index.html',
    'files' => array(
        array(
            'path' => 'website/index.html',
            'content' => '<!doctype html><html><head></head><body>' . $shallowMarkup . '</body></html>',
        ),
    ),
);

$shallowResult = (new ArtifactCompiler())->compile($shallowArtifact)->toArray();
$shallowCodes = array_column($shallowResult['diagnostics'] ?? array(), 'code');
$shallowBlocks = (string) ($shallowResult['serialized_blocks'] ?? '');

$assert(!in_array('editability_policy_failed', $shallowCodes, true) && 'failed' !== ($shallowResult['status'] ?? null), 'A shallow page keeps compiling cleanly.');
$assert(1 === substr_count($shallowBlocks, '<!-- wp:custom/layout-shell'), 'A shallow two-wrapper branch uses the same layout-shell representation.');
$assert(!str_contains($shallowBlocks, '<!-- wp:group') && str_contains($shallowBlocks, 'id="dp-outer-1"') && str_contains($shallowBlocks, 'id="dp-branch-1"'), 'The shallow shell preserves both source wrappers.');

fwrite(STDOUT, "Projected branch compression tests passed.\n");
