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
    array( 'mediaType', 'mediaUrl' ),
    array_keys($left['attrs']),
    'Core defaults and empty mediaAlt stay omitted from comment attrs.'
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
    'backgroundColor'   => 'accent',
    'style'             => array(
        'color'      => array( 'text' => '#123456' ),
        'border'     => array( 'radius' => '8px' ),
        'dimensions' => array( 'maxWidth' => '900px', 'minHeight' => '30rem' ),
        'elements'   => array( 'link' => array( 'color' => array( 'text' => '#654321' ) ) ),
        'position'   => array( 'type' => 'sticky', 'top' => '0px' ),
        'spacing'    => array( 'blockGap' => '2rem', 'padding' => array( 'top' => '2rem' ) ),
        'typography' => array( 'lineHeight' => '1.4' ),
        '--media-ratio' => '1',
    ),
    'inlineGeometryStyle' => 'min-height:30rem;aspect-ratio:16/9;max-width:900px;--media-ratio:1',
), array( $paragraph ));
$assertSame(
    '<div id="feature" class="wp-block-media-text has-media-on-the-right is-stacked-on-mobile is-vertically-aligned-center has-link-color has-accent-background-color has-background has-text-color promo" style="color:#123456;border-radius:8px;padding-top:2rem;line-height:1.4;grid-template-columns:auto 35%"><div class="wp-block-media-text__content">',
    $right['innerContent'][0],
    'Media-right opening carries position, stack, vertical, and width attributes.'
);
$assertSame(
    '</div><figure class="wp-block-media-text__media"><a class="media-link" href="https://example.com/full?a=1&amp;b=2" target="_blank" rel="noopener noreferrer"><img src="https://example.com/photo?a=1&amp;b=2" alt="A &quot;quoted&quot; alt"/></a></figure></div>',
    $right['innerContent'][2],
    'Media-right closes content before linked figure and escapes attributes.'
);
$assertContains('grid-template-columns:auto 35%', $right['innerHTML'], 'Right media width targets second grid track.');
$assertContains('is-vertically-aligned-center', $right['innerHTML'], 'Vertical alignment class matches core save shape.');
$assertContains('has-accent-background-color has-background', $right['innerHTML'], 'Top-level preset support classes survive media-text style filtering.');
$assertContains('has-text-color', $right['innerHTML'], 'Supported style-derived class survives media-text style filtering.');
$assertContains('has-link-color', $right['innerHTML'], 'Supported link-element class survives media-text style filtering.');
$assertSame('accent', $right['attrs']['backgroundColor'] ?? null, 'Top-level preset attr survives media-text style filtering.');
$assertSame(
    array(
        'color'      => array( 'text' => '#123456' ),
        'border'     => array( 'radius' => '8px' ),
        'elements'   => array( 'link' => array( 'color' => array( 'text' => '#654321' ) ) ),
        'spacing'    => array( 'padding' => array( 'top' => '2rem' ) ),
        'typography' => array( 'lineHeight' => '1.4' ),
    ),
    $right['attrs']['style'] ?? null,
    'Media-text comment attrs keep only supported style groups.'
);
$assertNotContains('gap:', $right['innerHTML'], 'Unsupported blockGap emits no wrapper CSS.');
$assertContains('padding-top:2rem', $right['innerHTML'], 'Supported spacing regenerates in media-text wrapper style.');
$assertContains('border-radius:8px', $right['innerHTML'], 'Supported border regenerates in media-text wrapper style.');
$assertContains('line-height:1.4', $right['innerHTML'], 'Supported typography regenerates in media-text wrapper style.');
$assertNotContains('min-height:', $right['innerHTML'], 'Source min-height does not leak into media-text wrapper style.');
$assertNotContains('aspect-ratio:', $right['innerHTML'], 'Source aspect-ratio does not leak into media-text wrapper style.');
$assertNotContains('max-width:', $right['innerHTML'], 'Source max-width does not leak into media-text wrapper style.');
$assertNotContains('--media-ratio:', $right['innerHTML'], 'Source custom property does not leak into media-text wrapper style.');
$assertContains('color:#123456', $right['innerHTML'], 'Supported color regenerates in media-text wrapper style.');

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

// core/media-text has no block attribute for a video pane's intrinsic
// dimensions, poster, or native playback state — its save() renders a fixed
// `<video controls src={mediaUrl} />`. MediaTextPattern still captures those
// facts under internal-only `mediaVideo*` keys (dropping them collapses the
// video to the browser's 300x150 default intrinsic size before it can load,
// shifting every section below it); BlockFactory projects them onto the
// saved `<video>` tag here and strips the carrier keys from the comment.
$sizedVideo = $factory->create('core/media-text', array(
    'mediaType'             => 'video',
    'mediaUrl'              => 'https://example.com/clip.mp4',
    'mediaVideoWidth'       => '1280',
    'mediaVideoHeight'      => '720',
    'mediaVideoPoster'      => 'https://example.com/clip.jpg',
    'mediaVideoPreload'     => 'none',
    'mediaVideoAutoplay'    => true,
    'mediaVideoLoop'        => true,
    'mediaVideoMuted'       => true,
    'mediaVideoPlaysInline' => true,
), array());
$assertContains(
    '<figure class="wp-block-media-text__media"><video controls src="https://example.com/clip.mp4" poster="https://example.com/clip.jpg" preload="none" width="1280" height="720" autoplay="autoplay" loop="loop" muted="muted" playsinline="playsinline"></video></figure>',
    $sizedVideo['innerHTML'],
    'Video pane emits dimensions, poster, and native playback attributes onto the saved <video> tag.'
);
foreach ( array( 'mediaVideoWidth', 'mediaVideoHeight', 'mediaVideoPoster', 'mediaVideoPreload', 'mediaVideoAutoplay', 'mediaVideoLoop', 'mediaVideoMuted', 'mediaVideoPlaysInline' ) as $internalKey ) {
    $assertSame(false, array_key_exists($internalKey, $sizedVideo['attrs'] ?? array()), $internalKey . ' never reaches the serialized block comment attrs.');
}
$sizedVideoValidity = ( new Runtime() )->validateBlockSerialization(array( $sizedVideo ));
$assertSame('pass', $sizedVideoValidity['status'] ?? null, 'Video dimension/poster/playback carrier attrs do not trip serialization validators.');
$sizedVideoFindings = ( new CanonicalSaveShapeValidator() )->findings(array( $sizedVideo ));
$assertSame(array(), $sizedVideoFindings, 'Video dimension/poster/playback carrier attrs do not trip the canonical save-shape validator.');

// A source `<figure>` that wraps the media pane has nowhere else to put its
// classes: core/media-text's save() only reads `className` onto the outer
// wrapper `<div>`, never onto the generated `<figure
// class="wp-block-media-text__media">`. `mediaFigureClassName` is the
// internal-only carrier MediaTextPattern uses for that case; BlockFactory
// merges it into the media pane's own class list and never serializes it.
$revealVideo = $factory->create('core/media-text', array(
    'className'             => 'vid',
    'mediaType'             => 'video',
    'mediaUrl'              => 'https://example.com/reveal.mp4',
    'mediaFigureClassName'  => 'in',
), array());
$assertContains(
    '<figure class="wp-block-media-text__media in"><video controls src="https://example.com/reveal.mp4"></video></figure>',
    $revealVideo['innerHTML'],
    'Source figure class merges onto the generated media pane figure, alongside the base block class.'
);
$assertContains('wp-block-media-text is-stacked-on-mobile vid', $revealVideo['innerHTML'], 'Wrapper className is unaffected by the media pane class carrier.');
$assertSame(
    false,
    array_key_exists('mediaFigureClassName', $revealVideo['attrs'] ?? array()),
    'mediaFigureClassName never reaches the serialized block comment attrs.'
);
$revealVideoValidity = ( new Runtime() )->validateBlockSerialization(array( $revealVideo ));
$assertSame('pass', $revealVideoValidity['status'] ?? null, 'Media pane class carrier does not trip serialization validators.');
$revealVideoFindings = ( new CanonicalSaveShapeValidator() )->findings(array( $revealVideo ));
$assertSame(array(), $revealVideoFindings, 'Media pane class carrier does not trip the canonical save-shape validator.');

$revealImage = $factory->create('core/media-text', array(
    'mediaType'            => 'image',
    'mediaUrl'             => 'https://example.com/reveal.jpg',
    'mediaAlt'             => '',
    'mediaFigureClassName' => 'in',
), array());
$assertContains(
    '<figure class="wp-block-media-text__media in">',
    $revealImage['innerHTML'],
    'Source figure class merges onto the generated media pane figure for image media too.'
);

$noFigureVideo = $factory->create('core/media-text', array(
    'mediaType' => 'video',
    'mediaUrl'  => 'https://example.com/plain.mp4',
), array());
$assertContains(
    '<figure class="wp-block-media-text__media"><video',
    $noFigureVideo['innerHTML'],
    'Absent mediaFigureClassName leaves the base media pane class unchanged.'
);

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
