<?php
declare(strict_types=1);

/**
 * A sliding-door button paints its cap on the control and its body on the
 * caption carrier: `<a class="b"><span class="i">Buy</span></a>`. The graph
 * reports the control's background longhands through the cascade (a more
 * specific `background-image` beats an earlier shorthand) and the carrier's
 * paint as the control's caption layer, so a consumer projecting one native
 * button can draw both. A breakpoint that repaints the carrier is a caption
 * variant of that layer.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

$css = '.b { display: inline-block; height: 41px; padding: 0 10px 0 0; background: url(/media/large.png) no-repeat 100% -126px; color: #fff; }'
    . ' .b.hl { background-image: url(/media/large-hl.png); }'
    . ' .i { display: block; height: 41px; line-height: 41px; padding: 0 10px 0 20px; background: url(/media/large.png) no-repeat 0 0; }'
    . ' .b.hl .i { background-image: url(/media/large-hl.png); }'
    . ' .plain { background: #123; background-color: #456; }'
    . ' .reset { background-image: url(/media/x.png); } .b.reset { background: #789; }'
    . ' @media (max-width:750px) { .b.hl .i { background: none !important; } }';

$row = static function (string $button) use ($css): array {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body><form method="post" action="/cart"><input type="number" name="quantity" value="1">' . $button . '</form></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    // A recognized cart control may be a non-native role=button link; callers pass it in.
    $anchor = $document->getElementsByTagName('a')->item(0);
    $graph = (new FormPresentationGraphBuilder(additionalControls: $anchor instanceof DOMElement ? array($anchor) : array()))->build($document->getElementsByTagName('form')->item(0), array(), $css);
    FormPresentationGraphBuilder::assertValid($graph);
    $captionVariants = array_values(array_map(
        static fn (array $variant): array => array( $variant['condition'], $variant['style_patch'] ),
        array_filter($graph['variants'], static fn (array $variant): bool => 'caption' === $variant['role'] && 1 === $variant['index'])
    ));
    foreach ( $graph['controls'] as $candidate ) if ( 1 === $candidate['index'] ) return $candidate + array( 'caption_variants' => $captionVariants );
    return array( 'caption_variants' => $captionVariants );
};

$cases = array(
    'sliding door' => array(
        '<a class="b hl" role="button" href="#"><span class="i">Buy</span></a>',
        array( 'background' => 'url(/media/large.png) no-repeat 100% -126px', 'background_image' => 'url(/media/large-hl.png)', 'color' => '#fff' ),
        array( 'background' => 'url(/media/large.png) no-repeat 0 0', 'background_image' => 'url(/media/large-hl.png)', 'height' => '41px', 'padding' => '0 10px 0 20px' ),
        array( array( array( 'kind' => 'media', 'query' => '(max-width:750px)' ), array( 'background' => 'none' ) ) ),
    ),
    'later shorthand resets an earlier longhand' => array(
        '<button class="b reset" type="submit">Buy</button>',
        array( 'background' => '#789', 'color' => '#fff' ),
        null,
        array(),
    ),
    'same-rule longhand after the shorthand wins' => array(
        '<button class="plain" type="submit">Buy</button>',
        array( 'background' => '#123', 'background_color' => '#456' ),
        null,
        array(),
    ),
);

$failures = 0;
foreach ( $cases as $name => list( $button, $control, $caption, $captionVariants ) ) {
    $actual = $row($button);
    $styles = $actual['control']['styles'] ?? array();
    $paint = array_intersect_key($styles, array_flip(array( 'background', 'background_color', 'background_image', 'color' )));
    $layer = isset($actual['caption']) ? array_intersect_key($actual['caption']['styles'], $caption ?? array()) : null;
    if ( $paint != $control ) {
        ++$failures;
        fwrite(STDERR, "FAIL {$name}: control paint " . json_encode($paint) . PHP_EOL);
    }
    if ( null === $caption ? isset($actual['caption']) : $layer != $caption ) {
        ++$failures;
        fwrite(STDERR, "FAIL {$name}: caption layer " . json_encode($actual['caption'] ?? null) . PHP_EOL);
    }
    if ( $actual['caption_variants'] != $captionVariants ) {
        ++$failures;
        fwrite(STDERR, "FAIL {$name}: caption variants " . json_encode($actual['caption_variants']) . PHP_EOL);
    }
}

if ( $failures > 0 ) {
    exit(1);
}
echo "ok\n";
