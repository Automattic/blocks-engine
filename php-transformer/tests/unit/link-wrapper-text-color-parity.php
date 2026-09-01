<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

/**
 * A content-wrapping `<a>` is converted to a group and its link is pushed down
 * onto the RichText blocks inside it. That rebuilds the anchor between the text
 * and the element that painted it, so the browser's link colour would replace
 * the source's. The carrier class plus its projected rule keep the source paint.
 */
$carrier = 'blocks-engine-propagated-link-color';

$transform = static function (string $css, string $body): array {
    return ( new HtmlTransformer() )->transform('<html><head><style>' . $css . '</style></head><body>' . $body . '</body></html>')->toArray();
};
$afterAuthorCss = static function (array $result): string {
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'after-author' === (string) ($asset['stylesheet_placement'] ?? '') ) {
            $css .= (string) ($asset['content'] ?? '');
        }
    }

    return $css;
};

$card = '<div><ul><li class="item"><a class="wrap" href="/services"><div class="mid"><div><p class="label">SERVICES</p></div></div></a></li></ul></div>';

// A painted inner element loses its place in the paint chain, so the rebuilt
// anchor must inherit.
$painted = $transform('.label{color:rgb(65,65,65)}.wrap{text-decoration:none}', $card);
$paintedMarkup = (string) ($painted['serialized_blocks'] ?? '');
if ( ! str_contains($paintedMarkup, '<p class="label ' . $carrier . '"') || ! str_contains($paintedMarkup, '>SERVICES</a>') ) {
    throw new RuntimeException('A pushed-down link inside painted source text must mark its host block as inheriting the paint.');
}
if ( ! str_contains($afterAuthorCss($painted), ':root :where(.' . $carrier . ')>a{color:inherit}') ) {
    throw new RuntimeException('The carrier class must project a colour-inheritance rule after the author cascade.');
}
if ( 'pass' !== ( ( new BlockValidityValidator() )->validateBlocks($painted['blocks'] ?? array())['status'] ?? '' ) ) {
    throw new RuntimeException('Carrying the paint must leave the blocks Gutenberg-valid.');
}

// Nothing inside the wrapper paints the text: the source rendered it with the
// browser's link colour, and so must the import.
$unpainted = $transform('.wrap{text-decoration:none}', $card);
if ( str_contains((string) ($unpainted['serialized_blocks'] ?? ''), $carrier) ) {
    throw new RuntimeException('Text the source left to the browser link colour must not be forced to inherit.');
}

// The wrapper's own colour has no painting element inside the anchor to lose.
$anchorOnly = $transform('.wrap{color:rgb(9,9,9)}', $card);
if ( str_contains((string) ($anchorOnly['serialized_blocks'] ?? ''), $carrier) ) {
    throw new RuntimeException('A colour resolved on the wrapper itself must not trigger the carrier.');
}

// A colour resolved anywhere between the text and the wrapper counts, since the
// intervening element is the one the rebuilt anchor now sits inside.
$intermediate = $transform('.mid{color:rgb(12,34,56)}', $card);
if ( ! str_contains((string) ($intermediate['serialized_blocks'] ?? ''), $carrier) ) {
    throw new RuntimeException('A colour painted between the text and the wrapper must trigger the carrier.');
}

// A colour keyword that paints nothing of its own leaves the chain unpainted.
foreach ( array( 'inherit', 'initial', 'unset', 'currentColor' ) as $keyword ) {
    if ( str_contains((string) ($transform('.label{color:' . $keyword . '}', $card)['serialized_blocks'] ?? ''), $carrier) ) {
        throw new RuntimeException('A non-painting colour keyword must not trigger the carrier: ' . $keyword);
    }
}

// Text sitting directly in the wrapper has no inner painter, so the wrapper is
// left exactly as it is without the carrier.
$directText = $transform('.label{color:rgb(65,65,65)}', '<div><a class="wrap" href="/services">Shop <div><p class="label">SERVICES</p></div></a></div>');
if ( str_contains((string) ($directText['serialized_blocks'] ?? ''), $carrier) ) {
    throw new RuntimeException('A wrapper holding unpainted direct text must not be forced to inherit.');
}

// Determinism.
$repeat = $transform('.label{color:rgb(65,65,65)}.wrap{text-decoration:none}', $card);
if ( ($repeat['serialized_blocks'] ?? null) !== ($painted['serialized_blocks'] ?? null) || $afterAuthorCss($repeat) !== $afterAuthorCss($painted) ) {
    throw new RuntimeException('Link-wrapper colour carrying must be deterministic.');
}

fwrite(STDOUT, "link wrapper text colour parity contract passed\n");
