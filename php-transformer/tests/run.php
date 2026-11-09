<?php
/**
 * Runs every PHP test script in the given directories in parallel.
 *
 * Each script is its own process and signals failure with a nonzero exit
 * code. Files ending in `-fixture.php` are builders that tests require, not
 * tests, and are skipped.
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

$queue = $tests;
$running = array();
$failures = array();
$durations = array();
$started = microtime(true);

while ( array() !== $queue || array() !== $running ) {
    while ( count($running) < $jobs && array() !== $queue ) {
        $test = array_shift($queue);
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
