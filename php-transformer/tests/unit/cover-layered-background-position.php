<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Reduced public-transform regression for blocks-engine#7017. Position lists
// repeat to the number of image layers, including non-url layers such as none.
$gradient = 'linear-gradient(90deg,#000e,#0002)';
$layered = $gradient . ',url(hero.jpg) center/cover';
$expected = array( 'x' => 0.0, 'y' => 0.42 );
$rows = array(
    'single value with gradient' => array( $layered, 'background-position:0% 42%', '', $expected ),
    'per-layer list' => array( $layered, 'background-position:center, 0% 42%', '', $expected ),
    'important winner' => array( $layered, 'background-position:center 42%', '.banner{background-position:center, 0% 42% !important}.banner{background-position:right bottom}', $expected ),
    'image only' => array( 'url(hero.jpg) center/cover', 'background-position:0% 42%', '', $expected ),
    'specificity winner' => array( $layered, 'background-position:right bottom', 'section.banner{background-position:center, 0% 42%}.banner{background-position:left top}', $expected ),
    'inactive breakpoint' => array( $layered, 'background-position:center, 0% 42%', '@media(max-width:600px){.banner{background-position:right bottom}}', $expected ),
    'image first' => array( 'url(hero.jpg) center/cover,' . $gradient, 'background-position:0% 42%, right bottom', '', $expected ),
    'third layer' => array( $gradient . ',none,url(hero.jpg) center/cover', 'background-position:center, right bottom, 0% 42%', '', $expected ),
    'repeated position list' => array( $gradient . ',none,url(hero.jpg) center/cover', 'background-position:0% 42%, right bottom', '', $expected ),
    'default image position' => array( $layered, 'background-position:left top, center', '', null ),
    'unsupported image position' => array( $layered, 'background-position:left top, 10px 20px', '', null ),
);

$failures = 0;
foreach ( $rows as $label => [$background, $position, $rules, $focalPoint] ) {
    $html = '<style>.banner{min-height:390px;display:flex;align-items:center;padding:70px 6%;background:'
        . $background . ';' . $position . '}' . $rules . '</style>'
        . '<section class="banner"><div><h2>Train with purpose.</h2></div></section>';
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $cover = $result['blocks'][0] ?? array();
    if ( 'core/cover' !== ($cover['blockName'] ?? '') || $focalPoint !== ($cover['attrs']['focalPoint'] ?? null) ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $label . ': ' . json_encode($cover['attrs']['focalPoint'] ?? null) . PHP_EOL);
    }
}

exit($failures > 0 ? 1 : 0);
