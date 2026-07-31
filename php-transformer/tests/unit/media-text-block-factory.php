<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\WordPress\CanonicalSaveShapeValidator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

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
$paragraph = $factory->create('core/paragraph', array( 'content' => 'Story' ));

$left = $factory->create('core/media-text', array(
    'mediaPosition'      => 'left',
    'mediaType'          => 'image',
    'mediaUrl'           => 'https://example.com/photo.jpg',
    'mediaAlt'           => '',
    'mediaWidth'         => 50,
    'isStackedOnMobile'  => true,
), array( $paragraph ));
$assertSame(
    '<div class="wp-block-media-text is-stacked-on-mobile"><figure class="wp-block-media-text__media"><img src="https://example.com/photo.jpg" alt=""/></figure><div class="wp-block-media-text__content">',
    $left['innerContent'][0],
    'Default media-left opening matches core save shape.'
);
$assertSame(null, $left['innerContent'][1], 'Media-left innerContent reserves child slot inside content wrapper.');
$assertSame('</div></div>', $left['innerContent'][2], 'Media-left closes content then wrapper.');
$assertSame(
    array( 'mediaType', 'mediaUrl', 'mediaAlt' ),
    array_keys($left['attrs']),
    'Core defaults stay omitted from comment attrs.'
);
$assertNotContains('grid-template-columns', $left['innerHTML'], 'Default 50 percent width emits no inline grid style.');
$assertNotContains('wp-image-', $left['innerHTML'], 'Image without mediaId emits no wp-image class.');
$assertNotContains('size-', $left['innerHTML'], 'Image without mediaId emits no size class.');

$right = $factory->create('core/media-text', array(
    'mediaPosition'     => 'right',
    'mediaType'         => 'image',
    'mediaUrl'          => 'https://example.com/photo?a=1&b=2',
    'mediaAlt'          => 'A "quoted" alt',
    'mediaWidth'        => 35,
    'verticalAlignment' => 'center',
    'href'              => 'https://example.com/full?a=1&b=2',
    'linkTarget'        => '_blank',
    'rel'               => 'noopener noreferrer',
    'linkClass'         => 'media-link',
    'anchor'            => 'feature',
    'className'         => 'promo',
    'style'             => array( 'spacing' => array( 'blockGap' => '2rem', 'padding' => array( 'top' => '2rem' ) ) ),
), array( $paragraph ));
$assertSame(
    '<div id="feature" class="wp-block-media-text has-media-on-the-right is-stacked-on-mobile is-vertically-aligned-center promo" style="padding-top:2rem;grid-template-columns:auto 35%"><div class="wp-block-media-text__content">',
    $right['innerContent'][0],
    'Media-right opening carries position, stack, vertical, support, and width attributes.'
);
$assertSame(
    '</div><figure class="wp-block-media-text__media"><a class="media-link" href="https://example.com/full?a=1&amp;b=2" target="_blank" rel="noopener noreferrer"><img src="https://example.com/photo?a=1&amp;b=2" alt="A &quot;quoted&quot; alt"/></a></figure></div>',
    $right['innerContent'][2],
    'Media-right closes content before linked figure and escapes attributes.'
);
$assertContains('grid-template-columns:auto 35%', $right['innerHTML'], 'Right media width targets second grid track.');
$assertContains('is-vertically-aligned-center', $right['innerHTML'], 'Vertical alignment class matches core save shape.');
$assertSame(array( 'padding' => array( 'top' => '2rem' ) ), $right['attrs']['style']['spacing'] ?? null, 'Unsupported blockGap is removed while supported spacing remains.');
$assertNotContains('gap:', $right['innerHTML'], 'Unsupported blockGap emits no wrapper CSS.');

$leftNarrow = $factory->create('core/media-text', array(
    'mediaType'  => 'image',
    'mediaUrl'   => 'https://example.com/narrow.jpg',
    'mediaWidth' => 40,
), array());
$assertContains('style="grid-template-columns:40% auto"', $leftNarrow['innerHTML'], 'Left media width targets first grid track.');

$video = $factory->create('core/media-text', array(
    'mediaType'         => 'video',
    'mediaUrl'          => 'https://example.com/demo.mp4',
    'isStackedOnMobile' => false,
    'href'              => 'https://example.com/ignored-for-video',
), array());
$assertContains('<figure class="wp-block-media-text__media"><video controls src="https://example.com/demo.mp4"></video></figure>', $video['innerHTML'], 'Video emits controls and src without image link wrapper.');
$assertNotContains('is-stacked-on-mobile', $video['innerHTML'], 'Explicit false omits stacked class.');
$assertNotContains('<a', $video['innerHTML'], 'Video does not use image-only link attributes.');

$validity = ( new Runtime() )->validateBlockSerialization(array( $right ));
$assertSame('pass', $validity['status'] ?? null, 'Media-text block passes serialization validators.');
$assertSame(0, $validity['summary']['finding_count'] ?? null, 'Media-text block has no serialization findings.');

$missingBase = $right;
$missingBase['innerHTML'] = str_replace('wp-block-media-text ', '', $right['innerHTML']);
$missingBase['innerContent'][0] = str_replace('wp-block-media-text ', '', $right['innerContent'][0]);
$missingBaseFindings = ( new CanonicalSaveShapeValidator() )->findings(array( $missingBase ));
$assertSame('missing_generated_class', $missingBaseFindings[0]['details']['reason'] ?? null, 'Canonical validator requires media-text generated wrapper class.');

if ( 0 === $failures ) {
    echo "media-text block factory ok\n";
}

exit(0 === $failures ? 0 : 1);
