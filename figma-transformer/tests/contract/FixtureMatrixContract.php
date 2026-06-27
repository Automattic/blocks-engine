<?php

declare(strict_types=1);

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_fixture_matrix_contract(callable $assert): void
{
    $matrixFixtureDir = sys_get_temp_dir() . '/figma-fixture-matrix-contract-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($matrixFixtureDir, 0777, true);
    file_put_contents($matrixFixtureDir . '/alias.fig', 'placeholder fig fixture');
    file_put_contents($matrixFixtureDir . '/explicit.fig', 'explicit fig fixture');

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
