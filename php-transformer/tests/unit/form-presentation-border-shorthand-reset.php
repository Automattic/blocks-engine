<?php
declare(strict_types=1);

/**
 * A winning `border` shorthand resets weaker border longhands on the same
 * element, as it does for background, font, margin and padding. A theme's
 * `.button{border-style:solid;border-width:1px}` must not survive a more
 * specific `.stepper .button{border:none}`, or consumers paint a border the
 * source never shows.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

$border = static function (string $css): array {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body><form method="post"><div class="stepper"><button class="button" type="button">-</button><input type="number" name="quantity"><button class="button" type="button">+</button></div><button type="submit">Buy</button></form></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $graph = (new FormPresentationGraphBuilder())->build($document->getElementsByTagName('form')->item(0), array(), $css);
    FormPresentationGraphBuilder::assertValid($graph);
    foreach ($graph['controls'] as $control) {
        if (0 === $control['index']) return array_filter($control['control']['styles'], static fn (string $key): bool => str_starts_with($key, 'border') && 'border_radius' !== $key, ARRAY_FILTER_USE_KEY);
    }
    return array();
};

$cases = array(
    'stronger shorthand resets weaker longhands' => array('.button { border-style:solid; border-width:1px; border-color:#f37321 } .stepper .button { border:none }', array('border' => 'none')),
    'stronger longhand survives a weaker shorthand' => array('.button { border:none } .stepper .button { border-style:solid }', array('border' => 'none', 'border_style' => 'solid')),
    'same-rule longhand after the shorthand wins' => array('.button { border:none; border-color:#f37321 }', array('border' => 'none', 'border_color' => '#f37321')),
    'stronger border-width resets weaker side widths' => array('.button { border-top-width:3px } .stepper .button { border-width:0 }', array('border_width' => '0')),
);
$failures = 0;
foreach ($cases as $name => list($css, $expected)) {
    $actual = $border($css);
    ksort($actual); ksort($expected);
    if ($actual !== $expected) {
        ++$failures;
        fwrite(STDERR, "FAIL {$name}: " . json_encode($actual) . PHP_EOL);
    }
}
if ($failures) exit(1);
echo "Border shorthand resets weaker longhands.\n";
