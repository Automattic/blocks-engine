<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;

$failures = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures): void {
    if ( $expected === $actual ) {
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true) . PHP_EOL);
};
$assertContains = static function (string $needle, string $actual, string $message) use (&$failures): void {
    if ( str_contains($actual, $needle) ) {
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ' missing=' . var_export($needle, true) . ' actual=' . var_export($actual, true) . PHP_EOL);
};
$assertNotContains = static function (string $needle, string $actual, string $message) use (&$failures): void {
    if ( ! str_contains($actual, $needle) ) {
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ' unexpected=' . var_export($needle, true) . ' actual=' . var_export($actual, true) . PHP_EOL);
};

$factory = new BlockFactory();

$block = $factory->create('core/cover', array(
    'url'           => 'https://example.com/hero.jpg',
    'alt'           => '',
    'dimRatio'      => 0,
    'minHeight'     => 480,
    'minHeightUnit' => 'px',
    'className'     => 'hero',
), array( $factory->create('core/paragraph', array( 'content' => 'Hi' )) ));

$assertSame(
    '<div class="wp-block-cover hero" style="min-height:480px">'
    . '<img class="wp-block-cover__image-background" alt="" src="https://example.com/hero.jpg" data-object-fit="cover"/>'
    . '<span aria-hidden="true" class="wp-block-cover__background has-background-dim-0 has-background-dim"></span>'
    . '<div class="wp-block-cover__inner-container">',
    $block['innerContent'][0],
    'Cover opening matches core save() for the px/no-dim case.'
);
$assertSame(null, $block['innerContent'][1], 'Cover innerContent reserves one inner-block slot.');
$assertSame('</div></div>', $block['innerContent'][2], 'Cover closing.');
$assertSame(array( 'url', 'alt', 'dimRatio', 'minHeight', 'className' ), array_keys($block['attrs']), 'px unit is not serialized; attr order stable.');

$dimmed = $factory->create('core/cover', array(
    'url'                => 'https://example.com/hero.jpg',
    'alt'                => 'Skyline',
    'dimRatio'           => 50,
    'customOverlayColor' => '#000000',
    'focalPoint'         => array( 'x' => 0.25, 'y' => 0.75 ),
), array());
$assertContains('has-background-dim"', $dimmed['innerHTML'], 'dimRatio 50 omits the has-background-dim-50 step class.');
$assertNotContains('has-background-dim-50', $dimmed['innerHTML'], 'dimRatio 50 omits the step class (core dimRatioToClass).');
$assertContains('style="background-color:#000000"', $dimmed['innerHTML'], 'Overlay color rides the span.');
$assertContains('style="object-position:25% 75%" data-object-fit="cover" data-object-position="25% 75%"', $dimmed['innerHTML'], 'Focal point serializes to object-position.');

$designGradient = $factory->create('core/cover', array(
    'url'            => 'https://example.com/hero.jpg',
    'alt'            => '',
    'dimRatio'       => 100,
    'customGradient' => 'linear-gradient(90deg,#ff0000,#0000ff)',
), array());
$assertContains(
    'class="wp-block-cover__background has-background-dim-100 has-background-dim wp-block-cover__gradient-background has-background-gradient"',
    $designGradient['innerHTML'],
    'P3: Design gradient uses full-opacity core class order with the compatibility class.'
);
$assertContains('style="background:linear-gradient(90deg,#ff0000,#0000ff)"', $designGradient['innerHTML'], 'N3: customGradient paints the overlay span.');
$assertSame('linear-gradient(90deg,#ff0000,#0000ff)', $designGradient['attrs']['customGradient'] ?? null, 'N3: customGradient rides comment attrs.');
$assertSame(100, $designGradient['attrs']['dimRatio'] ?? null, 'P3: dimRatio 100 rides comment attrs.');

$gradientWithDim = $factory->create('core/cover', array(
    'url'                => 'https://example.com/hero.jpg',
    'alt'                => '',
    'dimRatio'           => 30,
    'customOverlayColor' => '#112233',
    'customGradient'     => 'linear-gradient(90deg,#ff0000,#0000ff)',
), array());
$assertContains(
    'class="wp-block-cover__background has-background-dim-30 has-background-dim wp-block-cover__gradient-background has-background-gradient"',
    $gradientWithDim['innerHTML'],
    'N3: Nonzero dim with media and gradient uses core compatibility-class order.'
);
$assertContains(
    'style="background-color:#112233;background:linear-gradient(90deg,#ff0000,#0000ff)"',
    $gradientWithDim['innerHTML'],
    'N3: Core bgStyle order keeps custom overlay color before custom gradient.'
);

if ( 0 === $failures ) {
    echo "cover block factory ok\n";
}

exit(0 === $failures ? 0 : 1);
