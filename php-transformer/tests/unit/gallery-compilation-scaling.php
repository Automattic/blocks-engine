<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/fixtures/generated-gallery.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Per-item compile cost must stay flat as a gallery grows.
$times = array();
foreach (array(50, 200) as $n) {
    $start = microtime(true);
    (new HtmlTransformer())->transform(generatedGallery($n));
    $times[$n] = microtime(true) - $start;
}
if ($times[200] / 200 > 1.5 * $times[50] / 50) throw new RuntimeException('Gallery per-item compile cost grew superlinearly: ' . json_encode($times));
fwrite(STDOUT, "Gallery compile scaling regression passed.\n");
