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

// H1: Quoted URL contents cannot inject declarations or hero-size signals.
$quotedUrlStyle = "background:url('a) ;min-height:900px;.jpg') no-repeat";
$assertFalse(array_key_exists('min-height', $resolver->declarations($quotedUrlStyle)), 'Quoted URL semicolons do not inject min-height.');
$assertFalse($resolver->meetsHeroSizeGate($quotedUrlStyle), 'Quoted URL contents do not pass the hero-size gate.');

// H2: background-image wins over background and invalid earlier gradients do not abort scanning.
$assertSameArray(
    array( 'dimRatio' => 50, 'customOverlayColor' => '#000000' ),
    $resolver->dimFromStyle('background:linear-gradient(#000,#fff),url(a.jpg);background-image:linear-gradient(rgba(0,0,0,.5),rgba(0,0,0,.5)),url(b.jpg)'),
    'background-image overlay wins over the background shorthand.'
);

// H3: Common uniform-overlay gradient forms are recognized.
$assertSameArray(
    array( 'dimRatio' => 50, 'customOverlayColor' => '#000000' ),
    $resolver->dimFromStyle('background:linear-gradient(0deg, rgba(0,0,0,.5), rgba(0,0,0,.5)),url(h.jpg)'),
    'Vertical direction prefixes are ignored when comparing overlay stops.'
);
$assertSameArray(
    array( 'dimRatio' => 50, 'customOverlayColor' => '#000000' ),
    $resolver->dimFromStyle('background:linear-gradient(rgba(0,0,0,.5) 0%, rgba(0,0,0,.5) 100%),url(h.jpg)'),
    'Stop positions are ignored when comparing overlay colors.'
);
$assertSameArray(
    array( 'dimRatio' => 50, 'customOverlayColor' => '#000000' ),
    $resolver->dimFromStyle('background:linear-gradient(rgb(0 0 0 / 0.5),rgb(0 0 0 / 0.5)),url(h.jpg)'),
    'Modern rgb alpha syntax maps to dimRatio and overlay color.'
);

// H4: A trailing !important does not alter parsed declaration values.
$assertSameArray(
    array( 'minHeight' => 480, 'minHeightUnit' => 'px' ),
    $resolver->minHeightFromStyle('min-height:480px !important'),
    'Important min-height values parse.'
);
$assertTrue(
    $resolver->meetsHeroSizeGate('background-image:url(h.jpg);background-size:cover !important'),
    'Important background-size cover values pass.'
);

// H5: Repeating tokens in the background shorthand disqualify the image.
$assertTrue($resolver->hasRepeatingBackground('background:url(x.jpg) repeat'), 'Shorthand repeat disqualifies.');

// H6: Near-transparent and near-opaque overlays collapse to the no-overlay default.
$assertSameArray(
    array( 'dimRatio' => 0, 'customOverlayColor' => '' ),
    $resolver->dimFromStyle('background:linear-gradient(rgba(0,0,0,0.04),rgba(0,0,0,0.04)),url(h.jpg)'),
    'An overlay that rounds to zero is omitted.'
);
$assertSameArray(
    array( 'dimRatio' => 0, 'customOverlayColor' => '' ),
    $resolver->dimFromStyle('background:linear-gradient(rgba(0,0,0,0.95),rgba(0,0,0,0.95)),url(h.jpg)'),
    'An overlay that rounds to 100 is omitted.'
);

// H7: Vertical-first and single vertical background positions map correctly.
$assertSameArray(array( 'x' => 0.5, 'y' => 0.0 ), $resolver->focalPointFromStyle('background-position:top'), 'Single top centers the horizontal axis.');
$assertSameArray(array( 'x' => 1.0, 'y' => 0.0 ), $resolver->focalPointFromStyle('background-position:top right'), 'Vertical-first keyword pairs map to axes.');

// H8: Modern viewport units parse and use the viewport hero threshold.
$assertSameArray(array( 'minHeight' => 100, 'minHeightUnit' => 'dvh' ), $resolver->minHeightFromStyle('min-height:100dvh'), 'dvh minHeight parses.');
$assertTrue($resolver->meetsHeroSizeGate('background-image:url(h.jpg);min-height:100dvh'), '100dvh passes the hero-size gate.');

// Required change 9: Oversized scraped style strings are rejected without parsing.
$assertSameArray(array(), $resolver->declarations('color:red;' . str_repeat(' ', 65527)), 'Styles over 65536 bytes are rejected.');

// J5: Residual shorthand, cascade, color-syntax, and important-spacing edges.
$assertTrue($resolver->hasRepeatingBackground('background:url(x.jpg) round'), 'Shorthand round disqualifies.');
$assertTrue($resolver->hasRepeatingBackground('background:url(x.jpg) space'), 'Shorthand space disqualifies.');
$assertFalse($resolver->hasRepeatingBackground('background-repeat:no-repeat'), 'Longhand no-repeat remains non-repeating.');
$assertSameArray(
    array( 'dimRatio' => 0, 'customOverlayColor' => '' ),
    $resolver->dimFromStyle('background-image:linear-gradient(rgba(0,0,0,.5),rgba(0,0,0,.5)),url(b.jpg);background:red'),
    'Later background shorthand resets an earlier background-image overlay.'
);
$assertSameArray(
    array( 'dimRatio' => 50, 'customOverlayColor' => '#000000' ),
    $resolver->dimFromStyle('background:red;background-image:linear-gradient(rgba(0,0,0,.5),rgba(0,0,0,.5)),url(b.jpg)'),
    'Later background-image overlay wins over an earlier background shorthand.'
);
$assertSameArray(
    array( 'dimRatio' => 50, 'customOverlayColor' => '#000000' ),
    $resolver->dimFromStyle('background:linear-gradient(rgba(0 0 0 / 0.5),rgba(0 0 0 / 0.5)),url(h.jpg)'),
    'Modern rgba slash syntax maps to dimRatio and overlay color.'
);
$assertSameArray(
    array( 'minHeight' => 480, 'minHeightUnit' => 'px' ),
    $resolver->minHeightFromStyle('min-height:480px ! important'),
    'Spaced important min-height values parse.'
);

echo "cover style resolver ok\n";

exit(0 === $failures ? 0 : 1);
