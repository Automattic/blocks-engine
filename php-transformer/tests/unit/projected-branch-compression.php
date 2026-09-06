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

// Responsive copies of a provider form may each be absorbed into a projected
// shell. Their source identities must rebase onto the final emitted shells.
$responsiveForm = static fn(string $viewport): string => '<div id="' . $viewport . '-shell" class="blocks-engine-source-div-' . $viewport . '-shell-1">'
    . '<form id="' . $viewport . '-claim" class="blocks-engine-source-form-' . $viewport . '-claim-1">'
    . str_repeat('<div>', 9) . '<input name="email" type="email">' . str_repeat('</div>', 9)
    . '<button type="submit">Claim my spot</button>'
    . '</form></div>';
$formResult = (new ArtifactCompiler())->compile(array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'website/index.html',
    'files' => array(array(
        'path' => 'website/index.html',
        'content' => '<!doctype html><html><body>' . $responsiveForm('desktop') . $responsiveForm('mobile') . '</body></html>',
    )),
))->toArray();
$formPlan = $formResult['source_reports']['wordpress_site_plan'] ?? array();
$formMarkup = (string) ($formPlan['pages'][0]['canonical_block_markup'] ?? '');
$formDeclaration = current(array_filter($formPlan['runtime_declarations'] ?? array(), static fn(array $declaration): bool => 'forms' === ($declaration['type'] ?? null)));
$formBindings = array_map(static fn(array $entity): array => $entity['bindings'][0] ?? array(), $formDeclaration['payload']['entities'] ?? array());
$formTopologies = array_column($formDeclaration['payload']['entities'] ?? array(), 'control_topology');
$formIdentities = array_column($formDeclaration['payload']['entities'] ?? array(), 'fallback_identity');

$assert(2 === substr_count($formMarkup, '<!-- wp:custom/layout-shell'), 'Both responsive form copies compress into projected layout shells.');
$assert(2 === count($formBindings), 'Both projected form copies remain provider-materializable entities.');
$assert(array_reduce($formTopologies, static fn(bool $valid, array $topology): bool => $valid && 16 === ($topology['max_depth'] ?? null) && false === ($topology['truncated'] ?? null), true), 'Deep but bounded responsive form topology remains complete for provider materialization.');
$assert(array_reduce($formBindings, static fn(bool $valid, array $binding): bool => $valid
    && str_starts_with((string) ($binding['search_block_markup'] ?? ''), '<!-- wp:custom/layout-shell')
    && ($binding['search_block_markup'] ?? '') === substr($formMarkup, (int) ($binding['position']['offset'] ?? -1), (int) ($binding['position']['length'] ?? 0)), true), 'Projected form bindings rebase onto their exact final layout-shell ranges.');
$assert(2 === count(array_unique($formIdentities)) && array_reduce($formDeclaration['payload']['entities'] ?? array(), static fn(bool $valid, array $entity): bool => $valid && ($entity['fallback_identity'] ?? null) === ($entity['reconciliation_identity'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $entity['fallback_identity'] ?? '') === 1, true), 'Responsive duplicate provider forms retain distinct stable source fallback identities.');

// Variant composition adds ordinary source wrappers rather than source-projection
// markers. They must still compress when their safe, exact chain would otherwise
// exceed the producer's List View depth limit.
$variantChain = static function (string $variant, string $copy): string {
    $content = '<p id="hero-copy">' . $copy . '</p>';
    for ($depth = 20; 1 <= $depth; --$depth) {
        $content = '<div data-shell="' . $variant . '-' . $depth . '" class="' . $variant . '-shell layer-' . $depth . '">' . $content . '</div>';
    }
    return $content;
};
$variantResult = (new ArtifactCompiler())->compile(array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'website/index.html',
    'files' => array(
        array('path' => 'website/index.html', 'content' => '<!doctype html><html><body>' . $variantChain('desktop', 'Desktop copy') . '</body></html>'),
        array('path' => 'website/mobile.html', 'content' => '<!doctype html><html><body>' . $variantChain('mobile', 'Mobile copy') . '</body></html>'),
    ),
    'document_variants' => array(array(
        'source_path' => 'website/index.html',
        'variants' => array(array('id' => 'mobile', 'source_path' => 'website/mobile.html', 'media' => '(max-width: 700px)')),
    )),
))->toArray();
$variantMetrics = $variantResult['source_reports']['editability_report']['metrics'] ?? array();
$variantPolicy = $variantResult['source_reports']['editability_policy'] ?? array();
$variantBlocks = (string) ($variantResult['serialized_blocks'] ?? '');

$assert('passed' === ($variantPolicy['status'] ?? null) && !in_array('editability_policy_failed', array_column($variantResult['diagnostics'] ?? array(), 'code'), true) && 20 >= ($variantMetrics['max_nesting_depth'] ?? PHP_INT_MAX), 'Deep provider-neutral responsive counterparts pass the unchanged required editability policy.');
$assert(2 === substr_count($variantBlocks, '<!-- wp:custom/layout-shell'), 'Each responsive counterpart branch compresses into one bounded layout shell.');
foreach (array('desktop', 'mobile') as $variant) {
    $assert(str_contains($variantBlocks, '<div class="wp-block-group ' . $variant . '-shell layer-1">') && str_contains($variantBlocks, '<div class="wp-block-group ' . $variant . '-shell layer-20">'), 'Layout-shell serialization retains the exact saved outer and inner wrapper markup for the ' . $variant . ' counterpart.');
}
$assert(2 === ($variantMetrics['responsive_counterpart_count'] ?? null) && str_contains($variantBlocks, '>Desktop copy<') && str_contains($variantBlocks, '>Mobile copy<'), 'Compression retains native editable counterpart leaves and their correspondence contract.');

fwrite(STDOUT, "Projected branch compression tests passed.\n");
