<?php
/**
 * Pins tests/run.php: the job budget, per-script failure isolation, and that
 * the shared opcache file cache never serves a stale script.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/Support/TestJobBudget.php';

use Automattic\BlocksEngine\PhpTransformer\Tests\Support\TestJobBudget;

$check = static function (bool $condition, string $message): void {
    if ( !$condition ) {
        throw new RuntimeException($message);
    }
};

// Budget resolution: --jobs, then the environment, then available CPUs.
foreach ( array(
    'explicit --jobs wins' => array(3, '7', 32, 3),
    'environment beats CPUs' => array(0, '4', 32, 4),
    'environment with spaces' => array(0, ' 2 ', 32, 2),
    'empty environment ignored' => array(0, '', 6, 6),
    'zero environment ignored' => array(0, '0', 6, 6),
    'junk environment ignored' => array(0, 'many', 6, 6),
    'unset environment' => array(0, false, 6, 6),
    'never below one' => array(0, false, 0, 1),
) as $name => [$requested, $env, $available, $expected] ) {
    $check($expected === TestJobBudget::resolve($requested, $env, $available), "Job budget: {$name}");
}
foreach ( array('0-3' => 4, '0-3,8,10-11' => 7, '5' => 1, 'garbage' => 0) as $list => $expected ) {
    $check($expected === TestJobBudget::countCpuList((string) $list), "CPU list {$list}");
}
foreach ( array('400000 100000' => 4, '150000 100000' => 2, 'max 100000' => 0, '-1 100000' => 0) as $quota => $expected ) {
    $check($expected === TestJobBudget::quotaCpus($quota), "CPU quota {$quota}");
}
$check(TestJobBudget::availableCpus() >= 1, 'Available CPUs must be at least one');

// Runner behavior on a scratch suite.
$dir = sys_get_temp_dir() . '/blocks-engine-runner-' . bin2hex(random_bytes(6));
mkdir($dir);
$write = static function (string $name, string $body, int $age) use ($dir): void {
    file_put_contents("{$dir}/{$name}", "<?php\n{$body}\n");
    touch("{$dir}/{$name}", time() - $age);
};
$run = static function (array $args, array $env = array()) use ($dir): array {
    $process = proc_open(
        array_merge(array(PHP_BINARY, dirname(__DIR__) . '/run.php'), $args, array($dir)),
        array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        null,
        $env + getenv()
    );
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return array(proc_close($process), $output);
};

try {
    $write('a-pass.php', 'echo "fine\n";', 60);
    $write('b-exit.php', 'exit(3);', 60);
    $write('c-exception.php', 'throw new RuntimeException("boom");', 60);
    $write('d-fatal.php', 'undefined_function_for_runner_test();', 60);
    $write('e-pass.php', 'echo "after the fatal\n";', 60);
    $write('helper-fixture.php', 'exit(1);', 60);

    [$code, $output] = $run(array('--jobs=2'), array(TestJobBudget::ENV => '5'));
    $check(1 === $code, "Failing suite must exit 1:\n{$output}");
    $check(str_contains($output, '5 tests, 3 failed'), "Every failure counted, fixtures skipped:\n{$output}");
    foreach ( array('b-exit', 'c-exception', 'd-fatal') as $failing ) {
        $check(str_contains($output, "FAIL {$dir}/{$failing}.php"), "{$failing} must be reported:\n{$output}");
    }
    $check(str_contains($output, 'e-pass.php') && !str_contains($output, "FAIL {$dir}/e-pass.php"), "A fatal must not hide later scripts:\n{$output}");
    $check(str_contains($output, ' 2 jobs'), "--jobs overrides the environment:\n{$output}");
    $check(str_contains($output, 's cpu,'), "Summary reports child CPU time:\n{$output}");

    foreach ( array('b-exit.php', 'c-exception.php', 'd-fatal.php') as $failing ) {
        unlink("{$dir}/{$failing}");
    }
    [$code, $output] = $run(array(), array(TestJobBudget::ENV => '3'));
    $check(0 === $code && str_contains($output, '2 tests, 0 failed') && str_contains($output, ' 3 jobs'), "Environment sets the budget:\n{$output}");

    // A script edited after it was cached must run as edited, not from the cache.
    $write('a-pass.php', 'exit(9);', 30);
    [$code, $output] = $run(array(), array('BLOCKS_ENGINE_TEST_OPCACHE' => '1'));
    $check(1 === $code && str_contains($output, "FAIL {$dir}/a-pass.php"), "Edited script must not be served from the opcache file cache:\n{$output}");

    [$code, $output] = $run(array());
    $check(1 === $code && str_contains($output, 'opcache file cache off'), "The opcache file cache is opt-in:\n{$output}");

    // With an affinity mask of 4 CPUs (Linux), the default budget is at most 4.
    if ( PHP_OS_FAMILY === 'Linux' && '' !== trim((string) shell_exec('command -v taskset 2>/dev/null')) && TestJobBudget::availableCpus() >= 4 ) {
        $write('a-pass.php', 'echo "fine\n";', 20);
        $output = (string) shell_exec('env -u ' . TestJobBudget::ENV . ' taskset -c 0-3 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/run.php') . ' ' . escapeshellarg($dir) . ' 2>&1');
        $check(1 === preg_match('/, (\d+) jobs,/', $output, $match) && (int) $match[1] <= 4, "taskset -c 0-3 must cap the budget at 4:\n{$output}");
    }
} finally {
    array_map('unlink', glob("{$dir}/*.php") ?: array());
    rmdir($dir);
}

echo "Test runner budget, isolation and cache freshness passed\n";
