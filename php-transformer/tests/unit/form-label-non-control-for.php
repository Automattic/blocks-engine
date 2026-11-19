<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$select = '<select name="y"><option value="2025">2025</option></select>';
$cases = array(
    'wrapper around select' => array('<label for="w">Since:</label><div id="w">' . $select . '</div>', 'for="w"'),
    'wrapper around input' => array('<label for="w">Since:</label><div id="w"><input name="q"></div>', 'for="w"'),
    'missing target' => array('<label for="missing">Since:</label>' . $select, 'for="missing"'),
    'control target' => array('<label for="y">Since:</label><div id="w"><select id="y" name="y"><option value="2025">2025</option></select></div>', ''),
    'no for' => array('<label>Since:</label><div>' . $select . '</div>', ''),
    'hidden wrapper label' => array('<label class="element-invisible" for="w">Since:</label><div id="w">' . $select . '</div>', 'class="element-invisible"'),
    'hidden missing label' => array('<label class="element-invisible" for="missing">Since:</label>' . $select, 'class="element-invisible"'),
    'multiple controls' => array('<label for="w">Since:</label><div id="w">' . $select . '<input name="q"></div>', 'for="w"'),
    'empty target' => array('<label for="w">Since:</label><div id="w"></div>' . $select, 'for="w"'),
);

foreach ($cases as $name => [$children, $attribute]) {
    foreach (array(false, true) as $wholeDocument) {
        $html = '<form action="/s" method="get">' . $children . '<input type="submit" value="Apply"></form>';
        if ($wholeDocument) {
            $html = '<!doctype html><html><head><title>Filter</title></head><body>' . $html . '</body></html>';
        }
        $result = (new HtmlTransformer())->transform($html)->toArray();
        $markup = $result['serialized_blocks'] ?? '';
        if (! str_contains($markup, 'Since:') || ('' !== $attribute && ! str_contains($markup, $attribute))) {
            throw new RuntimeException($name . ': label copy and attributes must survive');
        }
        foreach (array_merge($result['fallbacks'] ?? array(), $result['diagnostics'] ?? array()) as $finding) {
            if ('html_content_dropped' === ($finding['diagnostic_code'] ?? $finding['code'] ?? null)) {
                throw new RuntimeException($name . ': label must not report content loss');
            }
        }
        if ('custom/authored-native-form' !== ($result['blocks'][0]['blockName'] ?? '')) {
            throw new RuntimeException($name . ': form must stay native');
        }
    }
}

echo "form label non-control for: passed\n";
