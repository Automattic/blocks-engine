<?php
declare(strict_types=1);

// Generated large-page contract pins the pre-optimization serialized blocks
// and WordPress site plan, not timings (which depend on the runner).
$argv = array(__FILE__, '--contract');
require dirname(__DIR__, 2) . '/tools/benchmarks/navigation-compilation.php';
