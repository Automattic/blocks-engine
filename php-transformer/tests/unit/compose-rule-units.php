<?php
declare(strict_types=1);

// The benchmark asserts parse counts and byte-identical compose output, never
// wall-clock thresholds. Separate workers keep the instrumented class isolated.
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/tools/benchmarks/compose-rule-units.php');
passthru($command, $status);
exit($status);
