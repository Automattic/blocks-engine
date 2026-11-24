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
 * Defaults: tests/contract tests/unit. The job count is `--jobs`, else
 * BLOCKS_ENGINE_TEST_JOBS, else the CPUs this process may run on (affinity
 * mask and cgroup quota, not the host count); see TestJobBudget.
 *
 * Every script still runs in its own process, so a fatal error or exit in one
 * cannot hide another's failure. Set BLOCKS_ENGINE_TEST_OPCACHE=1 to have
 * children share an opcache file cache, so the autoloader and source classes
 * compile once per change instead of once per script. It is opt-in because
 * every child also maps its own opcache shared memory, which exhausts memory
 * on small CI runners.
 */

declare(strict_types=1);

require_once __DIR__ . '/Support/TestJobBudget.php';

use Automattic\BlocksEngine\PhpTransformer\Tests\Support\TestJobBudget;

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
$jobs = TestJobBudget::resolve($jobs, getenv(TestJobBudget::ENV), $jobs > 0 ? 0 : TestJobBudget::availableCpus());

$tests = array();
foreach ( $dirs as $dir ) {
    $absolute = str_starts_with($dir, '/');
    foreach ( glob(($absolute ? rtrim($dir, '/') : $root . '/' . trim($dir, '/')) . '/*.php') ?: array() as $file ) {
        if ( !str_ends_with($file, '-fixture.php') ) {
            $tests[] = str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
        }
    }
}
sort($tests);
$php = child_php_command($root);
$scaling = array_values(array_filter($tests, static fn (string $test): bool => str_ends_with($test, '-scaling.php')));

/**
 * The PHP command each test runs under: this binary plus a shared opcache file
 * cache when OPcache is available and not disabled.
 *
 * Each child loads compiled scripts from the shared file cache into its own
 * opcache shared memory, as production opcache serves them, instead of
 * compiling the autoloader and every source class again. (File-cache-only
 * mode is avoided: it copies scripts into the request heap and pushes tight
 * memory-limit tests over their limit.) Timestamps are revalidated on every
 * load, so an edited file is recompiled rather than served stale.
 *
 * @return list<string>
 */
function child_php_command(string $root): array
{
    $command = array(PHP_BINARY);
    if ( '1' !== getenv('BLOCKS_ENGINE_TEST_OPCACHE') || !extension_loaded('Zend OPcache') ) {
        return $command;
    }
    $cache = sys_get_temp_dir() . '/blocks-engine-test-opcache-' . md5($root . PHP_VERSION);
    if ( !is_dir($cache) && !@mkdir($cache, 0700, true) && !is_dir($cache) ) {
        return $command;
    }
    foreach ( array(
        'opcache.enable_cli' => '1',
        'opcache.file_cache' => $cache,
        'opcache.validate_timestamps' => '1',
        'opcache.revalidate_freq' => '0',
    ) as $setting => $value ) {
        array_push($command, '-d', $setting . '=' . $value);
    }

    return $command;
}

/**
 * Total CPU seconds (user + system) used by finished child processes.
 */
function children_cpu_seconds(): float
{
    $usage = getrusage(1);
    if ( false === $usage ) {
        return 0.0;
    }

    return $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'] + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1e6;
}

/**
 * Runs the tests with at most $jobs at once.
 *
 * @param list<string> $tests
 * @param list<string> $php Command prefix for each child.
 * @return array{0: array<string, float>, 1: array<string, string>} Durations and failure output, keyed by test.
 */
function run_tests(array $tests, int $jobs, string $root, array $php): array
{
    $running = array();
    $failures = array();
    $durations = array();
    $total = count($tests);
    $started = microtime(true);
    $lastProgress = $started;
    while ( array() !== $tests || array() !== $running ) {
        while ( count($running) < $jobs && array() !== $tests ) {
            $test = array_shift($tests);
            $log = tmpfile();
            $process = proc_open(array_merge($php, array($test)), array(0 => array('file', '/dev/null', 'r'), 1 => $log, 2 => $log), $pipes, $root);
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
            } else {
                // One line per finished test keeps long parallel runs observable.
                printf("ok %6.1fs %s\n", $durations[$job['test']], $job['test']);
            }
            fclose($job['log']);
            unset($running[$index]);
        }
        $now = microtime(true);
        if ( $now - $lastProgress >= 30 ) {
            printf("%d/%d done, %.1fs\n", count($durations), $total, $now - $started);
            fflush(STDOUT);
            $lastProgress = $now;
        }
        usleep(20000);
    }

    return array($durations, $failures);
}

$started = microtime(true);
[$durations, $failures] = run_tests(array_values(array_diff($tests, $scaling)), $jobs, $root, $php);
[$scalingDurations, $scalingFailures] = run_tests($scaling, 1, $root, $php);
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
printf(
    "\n%d tests, %d failed, %.1fs wall, %.1fs cpu, %d jobs, opcache file cache %s\n",
    count($tests),
    count($failures),
    microtime(true) - $started,
    children_cpu_seconds(),
    $jobs,
    count($php) > 1 ? 'on' : 'off'
);
exit(array() === $failures ? 0 : 1);
