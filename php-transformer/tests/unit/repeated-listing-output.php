<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$n = 200;
$html = require __DIR__ . '/repeated-listing-fixture.php';
$output = (new HtmlTransformer())->transform($html, array())->toArray();
// Baseline hashes; trunk differs only in geometry carrier digests (#7366).
if ( 'bf9ed14c7c433ea79aa775c98321baf6af321e72ce4769e7de2fde2d1474e0ad' !== hash('sha256', $output['serialized_blocks'])
    || 'fbe60166b22eea24acd1f11bc3e604cce40adcd319c013457e489903b4e4c0e8' !== hash('sha256', serialize($output['fallbacks'])) ) {
    throw new RuntimeException('Repeated listing markup and fallbacks must remain byte-identical.');
}
echo "Repeated listing output passed.\n";
