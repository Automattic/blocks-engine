<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/fixtures/generated-gallery.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// Baseline hashes were captured before the optimization, through this same path.
$times = array();
foreach (array(50 => 'd9edfad48c622db0d22f5fc9e4bede90d00bcd2d6d033e048276856bc610408f', 200 => 'f05068b85b4a49ecf5483ab16f465e7602534a035953409ab5d3884c0affbece') as $n => $hash) {
    $html = generatedGallery($n);
    $start = microtime(true);
    $blocks = (new HtmlTransformer())->transform($html)->toArray()['blocks'];
    $times[$n] = microtime(true) - $start;
    if (hash('sha256', (new Runtime())->serializeBlocks($blocks)) !== $hash) throw new RuntimeException('Gallery block output changed.');
}
if ($times[200] / 200 > 1.5 * $times[50] / 50) throw new RuntimeException('Gallery per-item compile cost grew superlinearly: ' . json_encode($times));
fwrite(STDOUT, "Gallery output and scaling regression passed.\n");
