<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform('<img src="portrait.jpg" alt="Portrait">')->toArray();
$selection = $result['blocks'][0]['attrs']['metadata']['blocksEngineImageSelection'] ?? null;
if (array('src' => 'portrait.jpg') !== $selection) {
    fwrite(STDERR, "FAIL: a plain image must retain its single-source selection, excluding generated attachment renditions\n");
    exit(1);
}
echo "Plain image selection passed\n";
