<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
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

/**
 * A standalone `<video>` with no surrounding two-pane layout converts to a
 * native `core/video` block. Without its intrinsic width/height the video
 * collapses to the browser's 300x150 default intrinsic size before it can
 * load (worse still when `preload="none"` — the browser never even fetches
 * metadata, so it never learns the real aspect ratio), shifting every
 * section that follows it. core's own `save()` reads dimensions, poster,
 * and native playback state straight out of block attributes, so they must
 * survive conversion as real attrs, not just markup.
 */
$sized = ( new HtmlTransformer() )->transform(
    '<main><video src="https://example.com/clip.mp4" poster="https://example.com/clip.jpg" playsinline preload="none" width="1280" height="720" autoplay loop muted controls></video></main>'
)->toArray();
$sizedBlock = $sized['blocks'][0] ?? array();
$assertSame('core/video', $sizedBlock['blockName'] ?? null, 'A standalone sized video converts to core/video.');
$assertSame('https://example.com/clip.mp4', $sizedBlock['attrs']['src'] ?? null, 'src survives as a block attribute.');
$assertSame('https://example.com/clip.jpg', $sizedBlock['attrs']['poster'] ?? null, 'poster survives as a block attribute.');
$assertSame('1280', $sizedBlock['attrs']['width'] ?? null, 'width survives as a block attribute.');
$assertSame('720', $sizedBlock['attrs']['height'] ?? null, 'height survives as a block attribute.');
$assertSame('none', $sizedBlock['attrs']['preload'] ?? null, 'preload survives as a block attribute.');
$assertSame(true, $sizedBlock['attrs']['controls'] ?? null, 'controls survives as a block attribute.');
$assertSame(true, $sizedBlock['attrs']['autoplay'] ?? null, 'autoplay survives as a block attribute.');
$assertSame(true, $sizedBlock['attrs']['loop'] ?? null, 'loop survives as a block attribute.');
$assertSame(true, $sizedBlock['attrs']['muted'] ?? null, 'muted survives as a block attribute.');
$assertSame(true, $sizedBlock['attrs']['playsInline'] ?? null, 'playsinline survives as the playsInline block attribute.');
$assertContains(
    '<video src="https://example.com/clip.mp4" poster="https://example.com/clip.jpg" preload="none" width="1280" height="720" controls="controls" autoplay="autoplay" loop="loop" muted="muted" playsinline="playsinline">',
    (string) ($sizedBlock['innerHTML'] ?? ''),
    'All dimension, poster, and native playback attributes reach the saved <video> markup.'
);
$sizedValidity = ( new Runtime() )->validateBlockSerialization($sized['blocks']);
$assertSame('pass', $sizedValidity['status'] ?? null, 'A fully-attributed core/video block passes serialization validators.');
$sizedSerialized = ( new Runtime() )->serializeBlocks($sized['blocks']);
foreach ( array( 'autoplay' => true, 'controls' => true, 'loop' => true, 'muted' => true, 'playsInline' => true, 'poster' => 'https://example.com/clip.jpg', 'preload' => 'none' ) as $attribute => $value ) {
    $assertContains('"' . $attribute . '":' . json_encode($value, JSON_UNESCAPED_SLASHES), $sizedSerialized, 'Serialized core/video delimiter retains ' . $attribute . '.');
}
$sizedFindings = ( new CanonicalSaveShapeValidator() )->findings($sized['blocks']);
$assertSame(array(), $sizedFindings, 'A fully-attributed core/video block passes the canonical save-shape validator.');

/**
 * Regression: a video with no dimensions, poster, or native playback
 * attributes still converts cleanly — no attribute is fabricated.
 */
$plain = ( new HtmlTransformer() )->transform(
    '<main><video src="https://example.com/plain.mp4"></video></main>'
)->toArray();
$plainBlock = $plain['blocks'][0] ?? array();
$assertSame('core/video', $plainBlock['blockName'] ?? null, 'A dimensionless video still converts to core/video.');
foreach ( array( 'poster', 'width', 'height', 'preload', 'autoplay', 'loop', 'muted', 'playsInline' ) as $attribute ) {
    $assertSame(false, array_key_exists($attribute, $plainBlock['attrs'] ?? array()), 'A dimensionless/posterless video fabricates no ' . $attribute . ' attribute.');
}
$assertSame(false, $plainBlock['attrs']['controls'] ?? null, 'A video without a controls attribute explicitly disables controls for Jetpack schemas.');
$assertSame('<video src="https://example.com/plain.mp4"></video>', (string) preg_replace('/^<figure class="wp-block-video">|<\/figure>$/', '', (string) ($plainBlock['innerHTML'] ?? '')), 'A dimensionless/posterless video keeps emitting the original plain <video src> markup.');
$assertNotContains('width=', (string) ($plainBlock['innerHTML'] ?? ''), 'No fabricated width attribute leaks into the saved markup.');

/**
 * #864's own reproduction: native playback attributes on a `<video>` whose
 * src comes from a child `<source>` rather than a `src` attribute.
 */
$sourceChild = ( new HtmlTransformer() )->transform(
    '<main><video autoplay loop muted playsinline><source src="https://example.com/hero.mp4" type="video/mp4"></video></main>'
)->toArray();
$sourceChildBlock = $sourceChild['blocks'][0] ?? array();
$assertSame('core/video', $sourceChildBlock['blockName'] ?? null, 'A <source>-child video still converts to core/video.');
$assertSame('https://example.com/hero.mp4', $sourceChildBlock['attrs']['src'] ?? null, 'src resolves from the child <source> element.');
$assertSame(true, $sourceChildBlock['attrs']['autoplay'] ?? null, 'autoplay survives when src comes from a child <source>.');
$assertSame(true, $sourceChildBlock['attrs']['loop'] ?? null, 'loop survives when src comes from a child <source>.');
$assertSame(true, $sourceChildBlock['attrs']['muted'] ?? null, 'muted survives when src comes from a child <source>.');
$assertSame(true, $sourceChildBlock['attrs']['playsInline'] ?? null, 'playsinline survives when src comes from a child <source>.');
$sourceChildValidity = ( new Runtime() )->validateBlockSerialization($sourceChild['blocks']);
$assertSame('pass', $sourceChildValidity['status'] ?? null, 'A <source>-child video with playback attrs passes serialization validators.');

// This trimmed excerpt is taken from the mandatory SOS Calvaires capture's
// website/index.html background video, rather than a synthetic reproduction.
$realCaptureVideo = file_get_contents(dirname(__DIR__) . '/fixtures/soscalvaires-home-background-video.html');
$realCapture = ( new HtmlTransformer() )->transform((string) $realCaptureVideo)->toArray();
$findVideo = static function (array $blocks) use (&$findVideo): array {
    foreach ( $blocks as $block ) {
        if ( 'core/video' === ($block['blockName'] ?? null) ) {
            return $block;
        }
        $found = $findVideo(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array());
        if ( array() !== $found ) {
            return $found;
        }
    }
    return array();
};
$realVideo = $findVideo($realCapture['blocks'] ?? array());
$assertSame('core/video', $realVideo['blockName'] ?? null, 'The real-capture background video lowers to core/video.');
foreach ( array( 'autoplay', 'loop', 'muted', 'playsInline' ) as $attribute ) {
    $assertSame(true, $realVideo['attrs'][$attribute] ?? null, 'The real-capture video retains ' . $attribute . ' in block attributes.');
}
$assertSame(false, $realVideo['attrs']['controls'] ?? null, 'The real-capture video explicitly disables Jetpack-defaulted controls.');
$realSerialized = ( new Runtime() )->serializeBlocks(array($realVideo));
$assertContains('"controls":false', $realSerialized, 'The real-capture delimiter explicitly stores controls=false.');
$assertContains('"autoplay":true', $realSerialized, 'The real-capture delimiter stores autoplay.');
$assertContains('"loop":true', $realSerialized, 'The real-capture delimiter stores loop.');
$assertContains('"muted":true', $realSerialized, 'The real-capture delimiter stores muted.');
$assertContains('playsinline="playsinline"', $realSerialized, 'The real-capture saved HTML retains playsinline.');

if ( 0 === $failures ) {
    echo "video element conversion ok\n";
}

exit(0 === $failures ? 0 : 1);
