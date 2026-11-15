<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// The same shared ancestry must not apply a local semantic-region budget
// to ordinary div chains. Exercise normalization through the public API.
foreach (array('section', 'div') as $tag) {
    $html = '<' . $tag . ' id="region"><p>First</p><p>Second</p></' . $tag . '>';
    for ($i = 0; $i < 3; ++$i) {
        $html = '<div id="local-' . $i . '">' . $html . '</div>';
    }
    for ($i = 0; $i < 5; ++$i) {
        $html = '<div><p>Sibling</p>' . $html . '</div>';
    }
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $cursor = $result['blocks'][0];
    for ($i = 0; $i < 5; ++$i) {
        $cursor = $cursor['innerBlocks'][1];
    }
    if ('section' === $tag) {
        if (!str_ends_with($cursor['blockName'] ?? '', '/layout-shell')
            || array('div', 'div', 'div') !== array_column($cursor['attrs']['wrappers'] ?? array(), 'tagName')
            || 'section' !== ($cursor['innerBlocks'][0]['attrs']['tagName'] ?? '')) {
            throw new RuntimeException('Mixed region ancestry must compress containers without losing the native section.');
        }
    } else {
        if (!str_ends_with($cursor['blockName'] ?? '', '/layout-shell')
            || array('div', 'div', 'div') !== array_column($cursor['attrs']['wrappers'] ?? array(), 'tagName')) {
            throw new RuntimeException('Group-heavy div ancestry must share the List View depth budget.');
        }
    }
}
echo "Mixed region wrapper depth passed\n";
