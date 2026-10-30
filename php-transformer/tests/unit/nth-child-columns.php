<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = file_get_contents(dirname(__DIR__) . '/fixtures/nth-child-columns.html');
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray();
$markup = $result['source_reports']['compiled_site']['pages'][0]['block_markup'] ?? '';
$dom = new DOMDocument();
@$dom->loadHTML($markup);
$items = (new DOMXPath($dom))->query('//li[contains(concat(" ", normalize-space(@class), " "), " item ")]');
if (3 !== $items->length) {
    throw new RuntimeException('Expected three compiled li blocks: ' . $markup);
}
foreach ($items as $index => $item) {
    $expected = 2 === $index ? '0' : '30px';
    if (!str_contains($item->getAttribute('class'), 'wp-block-') || !preg_match('/(?:^|;)padding-right:' . preg_quote($expected, '/') . '(?:;|$)/', $item->getAttribute('style'))) {
        throw new RuntimeException('Item ' . ($index + 1) . ' expected padding-right:' . $expected . ', got ' . $item->getAttribute('style'));
    }
}
echo "An+B column padding compile regression passed\n";
