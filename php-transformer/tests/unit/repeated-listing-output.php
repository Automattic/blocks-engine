<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$n = 200;
$html = require __DIR__ . '/repeated-listing-fixture.php';
$output = (new HtmlTransformer())->transform($html, array())->toArray();
// Baseline hashes before sharing element identities and indexing label scans.
if ( '0204ab889763037fbd74150327b06ace3c501e683290ff0bee2ce3c43dbee505' !== hash('sha256', $output['serialized_blocks'])
    || 'add384a157e025f6e2b17374785033462ff427116d0ffc3df9f6c83298ae2569' !== hash('sha256', serialize($output['fallbacks'])) ) {
    throw new RuntimeException('Repeated listing markup and fallbacks must remain byte-identical.');
}
echo "Repeated listing output passed.\n";
