<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

$cases = array(
    'earlier important color' => array('color:red!important;color:blue', 'color', 'red'),
    'later important color' => array('color:red;color:blue!important', 'color', 'blue'),
    'equal important source order' => array('color:red!important;color:blue!important', 'color', 'blue'),
    'normal source order' => array('color:red;color:blue', 'color', 'blue'),
    'important font size' => array('font-size:32px!important;font-size:12px', 'font-size', '32px'),
    'spaced important token' => array('font-size:32px ! IMPORTANT;font-size:12px', 'font-size', '32px'),
);
$failures = 0;
foreach ($cases as $name => [$style, $property, $expected]) {
    $result = (new HtmlTransformer())->transform('<p style="' . $style . '">Authored text</p>')->toArray();
    $document = new DOMDocument();
    $document->loadHTML('<html><body>' . $result['serialized_blocks'] . '</body></html>');
    $css = implode("\n", array_column($result['assets'], 'content'));
    $values = (new StaticCssCascade($document, $css))->resolve($document->getElementsByTagName('p')->item(0), array($property), array());
    if (CssValueInspector::withoutImportant($values[$property] ?? '') !== $expected) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $name . ' expected ' . $expected . ', got ' . ($values[$property] ?? 'missing') . "\n");
    }
}
echo 'Inline declaration priority: ' . (count($cases) - $failures) . ' passed, ' . $failures . " failed\n";
exit($failures ? 1 : 0);
