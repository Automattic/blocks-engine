<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform('<img src="portrait.jpg" alt="Portrait">')->toArray();
if (isset($result['blocks'][0]['attrs']['metadata']['blocksEngineImageSelection'])) {
    fwrite(STDERR, "FAIL: plain images must not acquire responsive selection metadata\n");
    exit(1);
}
echo "Plain image selection passed\n";
