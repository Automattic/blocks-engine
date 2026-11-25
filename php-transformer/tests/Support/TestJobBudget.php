<?php
/**
 * Decides how many test scripts tests/run.php runs at once.
 *
 * The host CPU count overstates what a shared runner may use: a gate pinned
 * with `taskset` or limited by a cgroup CPU quota must not start one compile
 * per host CPU. The budget is, in order: an explicit `--jobs`, the
 * `BLOCKS_ENGINE_TEST_JOBS` environment variable, then the CPUs this process
 * may actually run on (affinity mask, capped by any cgroup quota).
 */

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support;

final class TestJobBudget
{
    public const ENV = 'BLOCKS_ENGINE_TEST_JOBS';

    /**
     * @param int          $requested Value of `--jobs`, 0 when absent.
     * @param string|false $env       Value of BLOCKS_ENGINE_TEST_JOBS, false when unset.
     * @param int          $available CPUs available to this process.
     */
    public static function resolve(int $requested, string|false $env, int $available): int
    {
        if ( $requested > 0 ) {
            return $requested;
        }
        if ( false !== $env && 1 === preg_match('/^\s*[1-9]\d*\s*$/', $env) ) {
            return (int) $env;
        }

        return max(1, $available);
    }

    /**
     * CPUs this process may run on: the affinity mask, capped by a cgroup
     * quota, falling back to the host count where neither is readable.
     */
    public static function availableCpus(): int
    {
        $counts = array();
        $status = @file_get_contents('/proc/self/status');
        if ( is_string($status) && 1 === preg_match('/^Cpus_allowed_list:\s*(\S+)/m', $status, $match) ) {
            $counts[] = self::countCpuList($match[1]);
        }
        $quota = @file_get_contents('/sys/fs/cgroup/cpu.max');
        if ( is_string($quota) ) {
            $counts[] = self::quotaCpus($quota);
        } else {
            $v1Quota = @file_get_contents('/sys/fs/cgroup/cpu/cpu.cfs_quota_us');
            $v1Period = @file_get_contents('/sys/fs/cgroup/cpu/cpu.cfs_period_us');
            if ( is_string($v1Quota) && is_string($v1Period) ) {
                $counts[] = self::quotaCpus(trim($v1Quota) . ' ' . trim($v1Period));
            }
        }
        $counts = array_filter($counts, static fn (int $count): bool => $count > 0);
        if ( array() !== $counts ) {
            return min($counts);
        }

        // GNU nproc honors the affinity mask; macOS has no affinity and reports the host count.
        return max(1, (int) trim((string) shell_exec('nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null')));
    }

    /**
     * Counts a Linux CPU list such as `0-3,8,10-11`; 0 when unreadable.
     */
    public static function countCpuList(string $list): int
    {
        $count = 0;
        foreach ( explode(',', trim($list)) as $range ) {
            if ( 1 === preg_match('/^(\d+)(?:-(\d+))?$/', trim($range), $bounds) ) {
                $count += isset($bounds[2]) ? max(0, (int) $bounds[2] - (int) $bounds[1] + 1) : 1;
            } else {
                return 0;
            }
        }

        return $count;
    }

    /**
     * CPUs granted by a cgroup quota `"<quota> <period>"` (rounded up); 0 when unlimited.
     */
    public static function quotaCpus(string $quota): int
    {
        if ( 1 !== preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $quota, $parts) || 0 === (int) $parts[2] ) {
            return 0;
        }

        return max(1, (int) ceil((int) $parts[1] / (int) $parts[2]));
    }
}
