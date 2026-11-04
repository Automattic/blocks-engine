<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Repeated neutral sections exercise hidden-panel, commerce and carousel probes.
function recognizerPage(int $count): string
{
    $sections = '';
    for ($i = 0; $i < $count; ++$i) {
        $sections .= '<section>' . str_repeat('<div>', 12) . '<button aria-label="Menu" aria-controls="panel-' . $i . '">☰</button>'
            . '<div id="panel-' . $i . '"><p>Panel content</p></div>'
            . '<div class="product-grid"><div><article><h2><a href="/catalog/item">Item</a></h2><p>Description</p></article></div></div>'
            . '<div class="carousel"><ul class="carousel-list"><li class="carousel-slide"><h3>Slide</h3><p>Slide content</p></li></ul></div>' . str_repeat('</div>', 12) . '</section>';
    }
    return '<!doctype html><html><head><style>div { position: relative; visibility: visible; max-height: none; } @media(max-width: 600px) { div { position: relative; } }</style></head><body>' . $sections . '</body></html>';
}

// Exclude autoload and one-time runtime setup from the small-page sample.
(new HtmlTransformer())->transform(recognizerPage(1));
$expected = array(
    8 => '6e4523088182e7561bedd821c1e7597f37b573d1528f74cd381337c7c23db395',
    128 => '54afbe5eaa3c9c55319ab99ec732452b12037ba77cdecceffca691a704af000f',
);
// Best of two runs per size keeps a shared CI host's pauses out of the ratio.
$times = array();
foreach (array_keys($expected) as $n) {
    $html = recognizerPage($n);
    for ($run = 0; $run < 2; ++$run) {
        $start = microtime(true);
        $blocks = (new HtmlTransformer())->transform($html)->toArray()['blocks'];
        $times[$n] = min($times[$n] ?? INF, microtime(true) - $start);
    }
    $hash = hash('sha256', json_encode($blocks));
    if ($expected[$n] !== $hash) throw new RuntimeException('Recognizer output changed for ' . $n . ' sections: ' . $hash);
    printf("%d sections: %.3fs; hash %s\n", $n, $times[$n], $hash);
}
if ($times[128] / 128 > 2.0 * $times[8] / 8) {
    throw new RuntimeException('Recognizer per-section cost grew superlinearly: ' . json_encode($times));
}
fwrite(STDOUT, "Recognizer compile scaling regression passed.\n");
