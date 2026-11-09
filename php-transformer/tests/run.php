<?php
/**
 * Runs every PHP test script in the given directories in parallel.
 *
 * Each script is its own process and signals failure with a nonzero exit
 * code. Files ending in `-fixture.php` are builders that tests require, not
 * tests, and are skipped. Files ending in `-scaling.php` compare their own
 * timings, so they run one at a time after the parallel batch.
 *
 * Usage: php tests/run.php [--jobs=N] [dir ...]
 * Defaults: tests/contract tests/unit, one job per CPU.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$jobs = 0;
$dirs = array();
foreach ( array_slice($argv, 1) as $arg ) {
    if ( str_starts_with($arg, '--jobs=') ) {
        $jobs = (int) substr($arg, 7);
    } else {
        $dirs[] = $arg;
    }
}
$dirs = $dirs ?: array('tests/contract', 'tests/unit');
$jobs = $jobs > 0 ? $jobs : max(1, (int) trim((string) shell_exec('nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null')));

$tests = array();
foreach ( $dirs as $dir ) {
    foreach ( glob($root . '/' . trim($dir, '/') . '/*.php') ?: array() as $file ) {
        if ( !str_ends_with($file, '-fixture.php') ) {
            $tests[] = substr($file, strlen($root) + 1);
        }
    }
}
sort($tests);
$scaling = array_values(array_filter($tests, static fn (string $test): bool => str_ends_with($test, '-scaling.php')));

/**
 * Runs the tests with at most $jobs at once.
 *
 * @param list<string> $tests
 * @return array{0: array<string, float>, 1: array<string, string>} Durations and failure output, keyed by test.
 */
function run_tests(array $tests, int $jobs, string $root): array
{
    $running = array();
    $failures = array();
    $durations = array();
    while ( array() !== $tests || array() !== $running ) {
        while ( count($running) < $jobs && array() !== $tests ) {
            $test = array_shift($tests);
            $log = tmpfile();
            $process = proc_open(array(PHP_BINARY, $test), array(0 => array('file', '/dev/null', 'r'), 1 => $log, 2 => $log), $pipes, $root);
            $running[] = array('test' => $test, 'process' => $process, 'log' => $log, 'start' => microtime(true));
        }
        foreach ( $running as $index => $job ) {
            $status = proc_get_status($job['process']);
            if ( $status['running'] ) {
                continue;
            }
            proc_close($job['process']);
            $durations[$job['test']] = microtime(true) - $job['start'];
            if ( 0 !== $status['exitcode'] ) {
                rewind($job['log']);
                $failures[$job['test']] = (string) stream_get_contents($job['log']);
                fwrite(STDERR, "FAIL {$job['test']}\n");
            }
            fclose($job['log']);
            unset($running[$index]);
        }
        usleep(20000);
    }

    return array($durations, $failures);
}

$started = microtime(true);
[$durations, $failures] = run_tests(array_values(array_diff($tests, $scaling)), $jobs, $root);
[$scalingDurations, $scalingFailures] = run_tests($scaling, 1, $root);
$durations += $scalingDurations;
$failures += $scalingFailures;

arsort($durations);
echo "Slowest:\n";
foreach ( array_slice($durations, 0, 5, true) as $test => $seconds ) {
    printf("  %6.1fs %s\n", $seconds, $test);
}
foreach ( $failures as $test => $output ) {
    $tail = implode("\n", array_slice(explode("\n", rtrim($output)), -15));
    echo "\n--- FAIL {$test}\n{$tail}\n";
}
printf("\n%d tests, %d failed, %.1fs wall, %d jobs\n", count($tests), count($failures), microtime(true) - $started, $jobs);
exit(array() === $failures ? 0 : 1);
