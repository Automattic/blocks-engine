<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$result = (new HtmlTransformer())->transform(
    '<ul><li>— <!-- -->Wheel &amp; tyre fitting and balancing</li>'
    . '<li>— <!-- -->4-wheel alignment<ul><li>Nested<!-- --> service</li></ul></li></ul>'
)->toArray();
$items = $result['blocks'][0]['innerBlocks'] ?? array();
$carrier = '<mark style="background-color:transparent;color:inherit" role="none">';
foreach (array(
    array($items[0] ?? array(), '— ' . $carrier . 'Wheel &amp; tyre fitting and balancing</mark>'),
    array($items[1] ?? array(), '— ' . $carrier . '4-wheel alignment</mark>'),
    array($items[1]['innerBlocks'][0]['innerBlocks'][0] ?? array(), 'Nested' . $carrier . ' service</mark>'),
) as [$item, $expected]) {
    if ('core/list-item' !== ($item['blockName'] ?? '') || $expected !== ($item['attrs']['content'] ?? '')) {
        throw new RuntimeException('List RichText must replace source comments without joining shaped text runs: ' . json_encode($item));
    }
}
if ('pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')) {
    throw new RuntimeException('List boundary carriers must remain valid native blocks');
}
echo "List comment text run boundaries passed\n";
