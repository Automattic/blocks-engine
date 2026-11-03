<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/tests/fixtures/generated-gallery.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

foreach (array_slice($argv, 1) ?: array(25, 50, 100, 200) as $count) {
    $html = generatedGallery((int) $count);
    $start = microtime(true);
    $blocks = (new HtmlTransformer())->transform($html)->toArray()['blocks'];
    $elapsed = microtime(true) - $start;
    $markup = (new Runtime())->serializeBlocks($blocks);
    if (getenv('GALLERY_OUTPUT_DIR')) {
        file_put_contents(getenv('GALLERY_OUTPUT_DIR') . '/' . $count . '.blocks', $markup);
    }
    printf("%d %.6fs %s\n", $count, $elapsed, hash('sha256', $markup));
}
