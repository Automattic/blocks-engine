<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlLabel;

$cases = array(
    'case-sensitive dangling for' => array(
        '<div class="field"><label for="Name">Name *</label><input type="text" name="name" id="name"></div>',
        array( 'Name *' ),
    ),
    'existing target outside the wrapper' => array(
        '<div id="Name"></div><div class="field"><label for="Name">Name *</label><input type="text" name="name" id="name"></div>',
        array( null ),
    ),
    'existing non-control target inside the wrapper' => array(
        '<div class="field"><span id="Name"></span><label for="Name">Name *</label><input type="text" name="name" id="name"></div>',
        array( null ),
    ),
    'two controls sharing a dangling label' => array(
        '<div class="field"><label for="Name">Name *</label><input type="text" name="name" id="name"><input name="other"></div>',
        array( null, null ),
    ),
    'label without for' => array(
        '<div class="field"><label>Name *</label><input type="text" name="name" id="name"></div>',
        array( 'Name *' ),
    ),
    'explicit association in a shared wrapper' => array(
        '<div class="field"><label for="name">Name *</label><input type="text" name="name" id="name"><input name="other"></div>',
        array( 'Name *', null ),
    ),
);

$failures = 0;
foreach ( $cases as $name => [ $html, $expected ] ) {
    $document = new DOMDocument();
    $document->loadHTML('<!doctype html><html><body>' . $html . '</body></html>');
    $actual = array();
    foreach ( $document->getElementsByTagName('input') as $control ) {
        $label = FormControlLabel::element($control);
        $actual[] = $label instanceof DOMElement ? trim($label->textContent) : null;
    }
    if ( $expected !== $actual ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $name . ' expected ' . json_encode($expected) . ', got ' . json_encode($actual) . PHP_EOL);
    }
}

if ( $failures > 0 ) {
    exit(1);
}
echo "form dangling label for: passed\n";
