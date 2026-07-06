<?php

declare(strict_types=1);

require_once __DIR__ . '/../../scripts/figma-fixture-matrix-quality.php';

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_fixture_matrix_contract(callable $assert): void
{
    $matrixFixtureDir = sys_get_temp_dir() . '/figma-fixture-matrix-contract-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($matrixFixtureDir, 0777, true);
    file_put_contents($matrixFixtureDir . '/alias.fig', 'placeholder fig fixture');
    file_put_contents($matrixFixtureDir . '/explicit.fig', 'explicit fig fixture');

    $matrixSelection = matrix_select_frame_ids(array(
        'candidates' => array(
            array(
                'id'         => 'frame:title-card',
                'name'       => 'Title Card',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'page',
                'dev_status' => 'ready_for_dev',
                'score'      => 2000,
                'width'      => 2238,
                'height'     => 291,
                'text_count' => 1,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
            array(
                'id'         => 'frame:home-desktop',
                'name'       => 'Home Page - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'front_page',
                'score'      => 500,
                'width'      => 1440,
                'height'     => 4400,
                'text_count' => 2,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
            array(
                'id'         => 'frame:single-desktop',
                'name'       => 'Blog Post - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'single',
                'score'      => 650,
                'width'      => 1440,
                'height'     => 8400,
                'text_count' => 31,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
        ),
    ), 5);

    $wideRootSelection = matrix_select_frame_ids(array(
        'candidates' => array(
            array(
                'id'         => 'frame:fisiostetic-home',
                'name'       => 'Fisiostetic home page',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'front_page',
                'score'      => 500,
                'width'      => 2095,
                'height'     => 6200,
                'text_count' => 120,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Fisiostetic'),
            ),
            array(
                'id'         => 'frame:pricing-section',
                'name'       => 'Pricing',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'page',
                'score'      => 900,
                'width'      => 1440,
                'height'     => 1200,
                'text_count' => 20,
                'parent'     => array('type' => 'SECTION'),
                'page'       => array('name' => 'Fisiostetic'),
            ),
        ),
    ), 1);

    $componentComposedFrontPageSelection = matrix_select_frame_ids(array(
        'candidates' => array(
            array(
                'id'         => 'frame:archive-template',
                'name'       => 'News blog with various grids',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'archive',
                'score'      => 722,
                'width'      => 1440,
                'height'     => 3328,
                'text_count' => 28,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Templates'),
            ),
            array(
                'id'         => 'frame:screenshot',
                'name'       => 'screenshot',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'page',
                'score'      => 1200,
                'width'      => 1200,
                'height'     => 900,
                'text_count' => 12,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Templates'),
            ),
            array(
                'id'         => 'frame:component-front-page',
                'name'       => 'Business Homepage',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'front_page',
                'score'      => 480,
                'width'      => 1440,
                'height'     => 3875,
                'text_count' => 0,
                'parent'     => array('type' => 'SECTION'),
                'page'       => array('name' => 'Patterns'),
            ),
            array(
                'id'         => 'frame:comments-utility',
                'name'       => 'Comments',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'page',
                'score'      => 900,
                'width'      => 1440,
                'height'     => 1800,
                'text_count' => 3,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Templates'),
            ),
        ),
    ), 2);

    $canonicalTemplateSelectionInspection = array(
        'candidates' => array(
            array(
                'id'         => 'frame:home-desktop',
                'name'       => 'Home Page - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'front_page',
                'score'      => 500,
                'width'      => 1440,
                'height'     => 4400,
                'text_count' => 20,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
            array(
                'id'         => 'frame:single-desktop',
                'name'       => 'Blog Post - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'single',
                'score'      => 650,
                'width'      => 1440,
                'height'     => 8400,
                'text_count' => 80,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
            array(
                'id'         => 'frame:page-desktop',
                'name'       => 'Page - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'page',
                'score'      => 620,
                'width'      => 1440,
                'height'     => 3200,
                'text_count' => 30,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
            array(
                'id'         => 'frame:archive-desktop',
                'name'       => 'Archive - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'archive',
                'score'      => 440,
                'width'      => 1440,
                'height'     => 3600,
                'text_count' => 36,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
            array(
                'id'         => 'frame:404-desktop',
                'name'       => '404 Page - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => '404',
                'score'      => 350,
                'width'      => 1440,
                'height'     => 1800,
                'text_count' => 12,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
            array(
                'id'         => 'frame:contact-desktop',
                'name'       => 'Contact - Desktop',
                'type'       => 'FRAME',
                'role'       => 'page',
                'page_type'  => 'page',
                'score'      => 300,
                'width'      => 1440,
                'height'     => 1800,
                'text_count' => 12,
                'parent'     => array('type' => 'CANVAS'),
                'page'       => array('name' => 'Mockups (dev handoff)'),
            ),
        ),
    );
    $canonicalTemplateSelection = matrix_select_frame_ids($canonicalTemplateSelectionInspection, 5);
    $canonicalTemplateOmissions = matrix_omitted_page_candidate_records($canonicalTemplateSelectionInspection, $canonicalTemplateSelection);
    $matrixVisualReadiness = matrix_fixture_visual_readiness(array(
        'id'                 => 'risk-fixture',
        'selected_frame_ids' => array('frame:home'),
        'omitted_page_candidates' => array(array('id' => 'frame:contact')),
        'quality_summary'    => array(
            'fixed_width_without_responsive_override_count' => 2,
            'fixed_width_over_desktop_uncovered_count' => 1,
            'desktop_canvas_without_responsive_breakpoints' => true,
            'large_absolute_offset_count' => 3,
            'large_css_offset_count' => 1,
            'missing_emitted_text_nodes' => 4,
            'layout_mismatch_count' => 2,
            'missing_asset_nodes' => 1,
            'fallback_prone_form_island_count' => 1,
            'fallback_prone_input_island_count' => 2,
            'link_targets_unresolved' => 1,
            'vector_placeholders' => 2,
            'vector_decode_coverage_ratio' => 0.8,
            'html_artifact' => array(
                'breakpoint_override_leak_count' => 1,
            ),
        ),
    ));
    $matrixQualitySummary = matrix_quality_matrix(array(
        array(
            'id' => 'ready-fixture',
            'status' => 'completed',
            'quality_status' => 'pass',
            'selected_frame_ids' => array('frame:home', 'frame:about'),
            'omitted_page_candidates' => array(),
            'quality_summary' => array(
                'fixed_width_declaration_count' => 4,
                'fixed_width_with_responsive_override_count' => 4,
                'vector_decode_coverage_ratio' => 1.0,
            ),
            'artifact_quality' => array('signals' => array()),
        ),
        array(
            'id' => 'risk-fixture',
            'status' => 'completed',
            'quality_status' => 'warn',
            'selected_frame_ids' => array('frame:home'),
            'omitted_page_candidates' => array(array('id' => 'frame:contact')),
            'quality_summary' => array(
                'fixed_width_declaration_count' => 4,
                'fixed_width_with_responsive_override_count' => 1,
                'fixed_width_without_responsive_override_count' => 3,
                'fallback_prone_input_island_count' => 2,
                'link_targets_unresolved' => 1,
                'vector_placeholders' => 1,
                'html_artifact' => array(
                    'breakpoint_override_leak_count' => 2,
                ),
            ),
            'artifact_quality' => array('signals' => array(
                array('code' => 'responsive_fixed_width_without_override'),
                array('code' => 'fallback_prone_html_islands'),
            )),
        ),
    ));

    $matrixSelectionLockPath = $matrixFixtureDir . '/selection-lock.json';
    file_put_contents($matrixSelectionLockPath, json_encode(array(
        'schema'   => 'blocks-engine/figma-transformer/fixture-matrix/v1',
        'fixtures' => array(
            array(
                'id'                 => 'alias',
                'selected_frame_ids' => array('locked:home', 'locked:about'),
                'entry_frame_id'     => 'locked:home',
            ),
        ),
    ), JSON_THROW_ON_ERROR));

    $matrixDryRun = static function (array $args) use ($matrixFixtureDir): ?array {
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-fixture-matrix.php')
            . ' --dry-run --capture-dom-boxes --fixture-dir=' . escapeshellarg($matrixFixtureDir);
        foreach ( $args as $arg ) {
            $command .= ' ' . escapeshellarg($arg);
        }

        $output = shell_exec($command);
        return is_string($output) ? json_decode($output, true) : null;
    };

    $matrixAliasSummary = $matrixDryRun(array(
        '--homeboy-bin=/opt/homeboy-alias',
        '--dom-box-command=node dom-box-alias',
    ));
    $matrixCanonicalSummary = $matrixDryRun(array(
        '--homeboy-command=/opt/homeboy-canonical',
        '--dom-box-provider-command=node dom-box-canonical',
    ));
    $matrixSelectionLockSummary = $matrixDryRun(array(
        '--selection-lock=' . $matrixSelectionLockPath,
    ));
    $matrixEvidenceSummary = $matrixDryRun(array(
        '--frame-ids=render:home',
        '--render-evidence=' . $matrixFixtureDir . '/{fixture}/render-evidence.json',
    ));
    $matrixScreenshotSummary = $matrixDryRun(array(
        '--frame-ids=render:home',
        '--source-screenshot=' . $matrixFixtureDir . '/screenshots/{fixture}/{slug}-source.png',
        '--generated-screenshot=' . $matrixFixtureDir . '/screenshots/{fixture}/{slug}-generated.png',
        '--diff-image=' . $matrixFixtureDir . '/screenshots/{fixture}/{slug}-diff.png',
    ));
    $missingHomeboyOutput = array();
    $missingHomeboyCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-fixture-matrix.php')
        . ' --capture-dom-boxes --fixture-dir=' . escapeshellarg($matrixFixtureDir)
        . ' --homeboy-command=' . escapeshellarg($matrixFixtureDir . '/missing-homeboy')
        . ' 2>&1';
    exec($missingHomeboyCommand, $missingHomeboyOutput, $missingHomeboyExitCode);
    $matrixHelpOutput = array();
    $matrixHelpCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-fixture-matrix.php')
        . ' --help'
        . ' 2>&1';
    exec($matrixHelpCommand, $matrixHelpOutput, $matrixHelpExitCode);
    $explicitFixtureCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-fixture-matrix.php')
        . ' --dry-run --fixture-dir=' . escapeshellarg($matrixFixtureDir)
        . ' --fixture=' . escapeshellarg($matrixFixtureDir . '/explicit.fig');
    $explicitFixtureOutput = shell_exec($explicitFixtureCommand);
    $explicitFixtureSummary = is_string($explicitFixtureOutput) ? json_decode($explicitFixtureOutput, true) : null;
    $missingExplicitFixtureOutput = array();
    $missingExplicitFixtureCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-fixture-matrix.php')
        . ' --dry-run --fixture=' . escapeshellarg($matrixFixtureDir . '/missing.fig')
        . ' 2>&1';
    exec($missingExplicitFixtureCommand, $missingExplicitFixtureOutput, $missingExplicitFixtureExitCode);

    $assert(! in_array('frame:title-card', $matrixSelection, true), 'fixture-matrix-selection-skips-dev-marked-title-card');
    $assert(array('frame:home-desktop', 'frame:single-desktop') === $matrixSelection, 'fixture-matrix-selection-falls-back-to-page-like-frames');
    $assert(array('frame:fisiostetic-home') === $wideRootSelection, 'fixture-matrix-selection-prefers-wide-root-page-over-section-frame');
    $assert('frame:component-front-page' === ($componentComposedFrontPageSelection[0] ?? null), 'fixture-matrix-selection-keeps-component-composed-front-page');
    $assert(! in_array('frame:comments-utility', $componentComposedFrontPageSelection, true), 'fixture-matrix-selection-skips-utility-template-frame');
    $assert(! in_array('frame:screenshot', $componentComposedFrontPageSelection, true), 'fixture-matrix-selection-skips-screenshot-utility-frame');
    $assert(array('frame:home-desktop', 'frame:single-desktop', 'frame:archive-desktop', 'frame:404-desktop', 'frame:page-desktop') === $canonicalTemplateSelection, 'fixture-matrix-selection-preserves-canonical-template-coverage-under-page-cap');
    $assert('frame:contact-desktop' === ($canonicalTemplateOmissions[0]['id'] ?? null), 'fixture-matrix-selection-reports-omitted-page-candidates');
    $assert('medium' === ($matrixVisualReadiness['visual_risk_bucket'] ?? null), 'fixture-matrix-visual-readiness-buckets-risk');
    $assert(0.5 === ($matrixVisualReadiness['route_coverage_ratio'] ?? null), 'fixture-matrix-visual-readiness-route-coverage');
    $assert(4 === ($matrixVisualReadiness['risk_categories']['responsive_coverage']['count'] ?? null), 'fixture-matrix-visual-readiness-responsive-risk-count');
    $assert(7 === ($matrixVisualReadiness['risk_categories']['text_wrapping_leaks']['count'] ?? null), 'fixture-matrix-visual-readiness-text-risk-count');
    $assert('blocks-engine/figma-transformer/fixture-matrix-quality/v1' === ($matrixQualitySummary['schema'] ?? null), 'fixture-matrix-quality-schema');
    $assert(0.625 === ($matrixQualitySummary['effective_responsive_coverage_ratio'] ?? null), 'fixture-matrix-quality-responsive-coverage-ratio');
    $assert(0.75 === ($matrixQualitySummary['route_coverage_ratio'] ?? null), 'fixture-matrix-quality-route-coverage-ratio');
    $assert(2 === ($matrixQualitySummary['totals']['breakpoint_override_leak_count'] ?? null), 'fixture-matrix-quality-nested-html-artifact-total');
    $assert(3 === ($matrixQualitySummary['risk_category_totals']['responsive_coverage'] ?? null), 'fixture-matrix-quality-risk-category-total');
    $assert(2 === count($matrixQualitySummary['per_fixture_readiness'] ?? array()), 'fixture-matrix-quality-per-fixture-readiness');
    $assert(is_array($matrixAliasSummary), 'fixture-matrix-alias-json-summary');
    $assert('/opt/homeboy-alias' === ($matrixAliasSummary['homeboy_command'] ?? null), 'fixture-matrix-homeboy-bin-alias');
    $assert(true === ($matrixAliasSummary['dom_box_provider_command_configured'] ?? null), 'fixture-matrix-dom-box-command-alias-configured');
    $matrixAliasCaptureCommand = (string) ($matrixAliasSummary['fixtures'][0]['dom_box_capture']['command'] ?? '');
    $assert(str_contains($matrixAliasCaptureCommand, escapeshellarg('/opt/homeboy-alias')), 'fixture-matrix-alias-capture-uses-homeboy-bin');
    $assert(str_contains($matrixAliasCaptureCommand, 'HOMEBOY_DOM_BOX_CAPTURE_COMMAND=' . escapeshellarg('node dom-box-alias')), 'fixture-matrix-alias-capture-uses-dom-box-command');

    $assert(is_array($matrixCanonicalSummary), 'fixture-matrix-canonical-json-summary');
    $assert('/opt/homeboy-canonical' === ($matrixCanonicalSummary['homeboy_command'] ?? null), 'fixture-matrix-homeboy-command-canonical');
    $assert(true === ($matrixCanonicalSummary['dom_box_provider_command_configured'] ?? null), 'fixture-matrix-dom-box-provider-command-canonical-configured');
    $matrixCanonicalCaptureCommand = (string) ($matrixCanonicalSummary['fixtures'][0]['dom_box_capture']['command'] ?? '');
    $assert(str_contains($matrixCanonicalCaptureCommand, escapeshellarg('/opt/homeboy-canonical')), 'fixture-matrix-canonical-capture-uses-homeboy-command');
    $assert(str_contains($matrixCanonicalCaptureCommand, 'HOMEBOY_DOM_BOX_CAPTURE_COMMAND=' . escapeshellarg('node dom-box-canonical')), 'fixture-matrix-canonical-capture-uses-dom-box-provider-command');

    $assert(is_array($matrixSelectionLockSummary), 'fixture-matrix-selection-lock-json-summary');
    $assert('locked_frame_ids' === ($matrixSelectionLockSummary['selection']['mode'] ?? null), 'fixture-matrix-selection-lock-summary-mode');
    $assert(1 === ($matrixSelectionLockSummary['selection']['fixture_count'] ?? null), 'fixture-matrix-selection-lock-fixture-count');
    $assert('selection_lock' === ($matrixSelectionLockSummary['fixtures'][0]['selection'] ?? null), 'fixture-matrix-selection-lock-fixture-source');
    $matrixSelectionLockCommand = (string) ($matrixSelectionLockSummary['fixtures'][0]['command'] ?? '');
    $assert(str_contains($matrixSelectionLockCommand, "--frame-ids='locked:home,locked:about'"), 'fixture-matrix-selection-lock-frame-ids');
    $assert(str_contains($matrixSelectionLockCommand, "--entry-frame-id='locked:home'"), 'fixture-matrix-selection-lock-entry-frame');
    $assert(is_array($matrixEvidenceSummary), 'fixture-matrix-render-evidence-json-summary');
    $assert($matrixFixtureDir . '/{fixture}/render-evidence.json' === ($matrixEvidenceSummary['evidence']['templates']['render_evidence_path'] ?? null), 'fixture-matrix-render-evidence-template-summary');
    $matrixEvidenceCommand = (string) ($matrixEvidenceSummary['fixtures'][0]['command'] ?? '');
    $assert(str_contains($matrixEvidenceCommand, '--parity-render-evidence-path=' . escapeshellarg($matrixFixtureDir . '/alias/render-evidence.json')), 'fixture-matrix-render-evidence-transform-argument');
    $assert(is_array($matrixScreenshotSummary), 'fixture-matrix-screenshot-json-summary');
    $assert($matrixFixtureDir . '/screenshots/{fixture}/{slug}-source.png' === ($matrixScreenshotSummary['evidence']['templates']['source_screenshot_path'] ?? null), 'fixture-matrix-source-screenshot-template-summary');
    $matrixScreenshotPaths = $matrixScreenshotSummary['fixtures'][0]['evidence']['pages'][0]['paths'] ?? array();
    $assert(false === ($matrixScreenshotPaths['source_screenshot_path']['exists'] ?? null), 'fixture-matrix-source-screenshot-missing-recorded');
    $matrixScreenshotCommand = (string) ($matrixScreenshotSummary['fixtures'][0]['command'] ?? '');
    $assert(str_contains($matrixScreenshotCommand, '--parity-source-screenshot-path=' . escapeshellarg($matrixFixtureDir . '/screenshots/alias/alias-source.png')), 'fixture-matrix-source-screenshot-transform-argument');
    $assert(str_contains($matrixScreenshotCommand, '--parity-generated-screenshot-path=' . escapeshellarg($matrixFixtureDir . '/screenshots/alias/alias-generated.png')), 'fixture-matrix-generated-screenshot-transform-argument');
    $assert(str_contains($matrixScreenshotCommand, '--parity-diff-image-path=' . escapeshellarg($matrixFixtureDir . '/screenshots/alias/alias-diff.png')), 'fixture-matrix-diff-image-transform-argument');
    $assert(0 !== $missingHomeboyExitCode, 'fixture-matrix-capture-preflight-missing-homeboy-fails');
    $missingHomeboyMessage = implode("\n", $missingHomeboyOutput);
    $assert(str_contains($missingHomeboyMessage, 'DOM box capture requires a runnable Homeboy command'), 'fixture-matrix-capture-preflight-missing-homeboy-message');
    $assert(str_contains($missingHomeboyMessage, 'Set --homeboy-command, --homeboy-bin, or HOMEBOY_COMMAND'), 'fixture-matrix-capture-preflight-homeboy-remediation');
    $assert(0 === $matrixHelpExitCode, 'fixture-matrix-help-exits-zero');
    $matrixHelpMessage = implode("\n", $matrixHelpOutput);
    $assert(str_contains($matrixHelpMessage, 'Usage:'), 'fixture-matrix-help-usage');
    $assert(str_contains($matrixHelpMessage, '--fixture=/path/to/file.fig'), 'fixture-matrix-help-explicit-fixture');
    $assert(is_array($explicitFixtureSummary), 'fixture-matrix-explicit-fixture-json-summary');
    $assert(1 === count($explicitFixtureSummary['fixtures'] ?? array()), 'fixture-matrix-explicit-fixture-disables-dir-discovery');
    $assert($matrixFixtureDir . '/explicit.fig' === ($explicitFixtureSummary['fixtures'][0]['path'] ?? null), 'fixture-matrix-explicit-fixture-path');
    $assert(0 !== $missingExplicitFixtureExitCode, 'fixture-matrix-missing-explicit-fixture-fails');
    $missingExplicitFixtureMessage = implode("\n", $missingExplicitFixtureOutput);
    $assert(str_contains($missingExplicitFixtureMessage, 'Explicit fixture is not readable'), 'fixture-matrix-missing-explicit-fixture-message');
}
