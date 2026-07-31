<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CoverStyleResolver;

$failures = 0;
$assertSameArray = static function (array $expected, $actual, string $message) use (&$failures): void {
    if ( $expected === $actual ) {
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true) . PHP_EOL);
};
$assertNull = static function ($actual, string $message) use (&$failures): void {
    if ( null === $actual ) {
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ' expected=NULL actual=' . var_export($actual, true) . PHP_EOL);
};
$assertTrue = static function (bool $actual, string $message) use (&$failures): void {
    if ( true === $actual ) {
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ' expected=true actual=false' . PHP_EOL);
};
$assertFalse = static function (bool $actual, string $message) use (&$failures): void {
    if ( false === $actual ) {
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ' expected=false actual=true' . PHP_EOL);
};

$resolver = new CoverStyleResolver();

$assertSameArray(
    array( 'dimRatio' => 50, 'customOverlayColor' => '#000000' ),
    $resolver->dimFromStyle('background:linear-gradient(rgba(0,0,0,0.5),rgba(0,0,0,0.5)),url("hero.jpg") center/cover'),
    'Uniform rgba gradient above the image maps to dimRatio + overlay color.'
);
$assertSameArray(
    array( 'dimRatio' => 50, 'customOverlayColor' => '#000000' ),
    $resolver->dimFromStyle('background-image:linear-gradient(#00000080,#00000080),url(hero.jpg)'),
    '#rrggbbaa alpha 0x80 rounds to dimRatio 50.'
);
$assertSameArray(
    array( 'dimRatio' => 0, 'customOverlayColor' => '' ),
    $resolver->dimFromStyle('background:linear-gradient(#000,#fff),url(hero.jpg)'),
    'Two-color gradients are design gradients, not dim overlays.'
);

$assertSameArray(array( 'x' => 1.0, 'y' => 0.0 ), $resolver->focalPointFromStyle('background-position:right top'), 'Keyword pair maps to corners.');
$assertSameArray(array( 'x' => 0.25, 'y' => 0.75 ), $resolver->focalPointFromStyle('background-position:25% 75%'), 'Percentages map to 0-1.');
$assertNull($resolver->focalPointFromStyle('background-position:center center'), 'Center is the default; omitted.');
$assertNull($resolver->focalPointFromStyle('background-position:10px 20px'), 'Length positions are not derivable.');

$assertSameArray(array( 'minHeight' => 480, 'minHeightUnit' => 'px' ), $resolver->minHeightFromStyle('min-height:480px'), 'px minHeight parses.');
$assertSameArray(array( 'minHeight' => 80, 'minHeightUnit' => 'vh' ), $resolver->minHeightFromStyle('height:80vh'), 'height falls back when min-height absent.');
$assertNull($resolver->minHeightFromStyle('min-height:50%'), 'Percentage heights are not derivable.');

$assertTrue($resolver->meetsHeroSizeGate('background:url(h.jpg) center/cover'), 'Shorthand size cover passes.');
$assertTrue($resolver->meetsHeroSizeGate('background-image:url(h.jpg);min-height:30vh'), '30vh threshold passes.');
$assertFalse($resolver->meetsHeroSizeGate('background-image:url(h.jpg);min-height:120px'), 'Sub-threshold height fails.');
$assertFalse($resolver->meetsHeroSizeGate('background-image:url(h.jpg)'), 'No size signal fails.');

$assertTrue($resolver->hasRepeatingBackground('background-repeat:repeat'), 'repeat disqualifies.');
$assertFalse($resolver->hasRepeatingBackground('background-repeat:no-repeat'), 'no-repeat does not.');

echo "cover style resolver ok\n";

exit(0 === $failures ? 0 : 1);
