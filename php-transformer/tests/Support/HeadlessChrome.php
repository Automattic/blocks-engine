<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support;

/** Shell environment for headless Chrome runs in tests. */
final class HeadlessChrome
{
    /**
     * Chrome creates its process-singleton socket under TMPDIR, and Unix
     * socket paths are capped near 104 bytes. Keep the caller's temp
     * directory when its path leaves room for Chrome's socket name, so runs
     * follow a relocated TMPDIR; fall back to /tmp for deep workspace paths.
     */
    public static function tmpdirPrefix(): string
    {
        $tmp = rtrim(sys_get_temp_dir(), '/');

        return 'TMPDIR=' . escapeshellarg(strlen($tmp) <= 50 ? $tmp : '/tmp') . ' ';
    }
}
