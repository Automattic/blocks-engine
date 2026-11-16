<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform('<style>.identity{display:flex;align-items:center;gap:10px;color:white;background:blue}</style><main><a role="button" href="/next" class="identity btn">Example</a></main>', array())->toArray();
$dom = new DOMDocument();
@$dom->loadHTML($result['serialized_blocks']);
$link = (new DOMXPath($dom))->query('//a[contains(@class,"wp-block-button__link")]')->item(0);
if (!$link instanceof DOMElement) {
    throw new RuntimeException('Fixture must exercise native button anchor promotion');
}
foreach (array('top', 'right', 'bottom', 'left') as $side) {
    if (!preg_match('/padding-' . $side . ':0(?:px)?(?:;|$)/', $link->getAttribute('style'))) {
        throw new RuntimeException('Promoted anchor must preserve zero source padding on ' . $side);
    }
}
echo "Button anchor source padding passed\n";
