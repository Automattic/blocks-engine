<?php
declare(strict_types=1);

/**
 * Stacked inline icons (Font Awesome's `fa-stack`) overlay two SVGs inside a
 * relatively positioned wrapper: each layer is `position:absolute` with zero
 * insets. Each SVG becomes a core/image whose synthesized inline figure carries the
 * layer classes but is `display:contents` on the front end, so it positions
 * nothing. The image must take over the figure's position and insets, or the
 * inner glyph falls below the circle instead of sitting on it (#6378).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<style>'
    . '.fa-stack{display:inline-block;vertical-align:middle;height:2em;position:relative;width:2.5em}'
    . '.fa-stack-1x,.fa-stack-2x{bottom:0;left:0;margin:auto;position:absolute;right:0;top:0;z-index:var(--fa-stack-z-index,auto)}'
    . '.svg-inline--fa.fa-stack-1x{height:1em;width:1.25em}'
    . '.svg-inline--fa.fa-stack-2x{height:2em;width:2.5em}'
    . '</style><main><section><div class="row text-center"><div class="col-md-4">'
    . '<span class="fa-stack fa-4x">'
    . '<svg class="svg-inline--fa fa-circle fa-stack-2x" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path d="M256 0a256 256 0 1 1 0 512A256 256 0 1 1 256 0z" fill="currentColor"/></svg>'
    . '<svg class="svg-inline--fa fa-laptop fa-stack-1x" viewBox="0 0 640 512" xmlns="http://www.w3.org/2000/svg"><path d="M128 32h384v256H128z" fill="currentColor"/></svg>'
    . '</span><h4>Responsive Design</h4></div></div></section></main>';

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ($asset['kind'] ?? null) ) {
        $css .= (string) ($asset['content'] ?? '');
    }
}

// Placement does not make the authored display bridge redundant. Keep the
// existing projection contract even when a rule also declares position:absolute:
// later responsive rules can return that same image to normal flow.
if ( ! str_contains($css, ':is(.fa-stack-1x.wp-block-image:where(.blocks-engine-synthetic-image-figure-inline),.fa-stack-2x.wp-block-image:where(.blocks-engine-synthetic-image-figure-inline)) > a > img{display:inline}') ) {
    fwrite(STDERR, "FAIL: positioned layers must retain the inline display bridge\n" . $css . "\n");
    exit(1);
}

$inline = 'blocks-engine-synthetic-image-figure-inline';
if ( 2 !== preg_match_all('/<figure class="([^"]*fa-stack-[12]x[^"]*)"/', $markup, $figures) ) {
    fwrite(STDERR, "FAIL: each stacked SVG layer must become an image figure\n" . $markup . "\n");
    exit(1);
}
$layers = array_filter($figures[1], static fn (string $classes): bool => in_array($inline, preg_split('/\s+/', $classes), true));
if ( array() === $layers ) {
    fwrite(STDERR, "FAIL: the stacked layers sit in an inline-block wrapper and keep inline figures\n" . $markup . "\n");
    exit(1);
}
foreach ( $layers as $classes ) {
    // The figure is display:contents, so the image must take over the
    // figure's out-of-flow position and insets, or the inner glyph falls
    // below the circle instead of sitting on it.
    if ( ! str_contains($css, '.' . $inline . '{display:contents}') ) {
        fwrite(STDERR, "FAIL: expected the transparent inline figure\n" . $css . "\n");
        exit(1);
    }
    $leaf = null;
    if ( preg_match_all('/([^{}]*\.' . preg_quote($inline, '/') . '[^{}]*>\s*img)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER) ) {
        foreach ( $rules as $rule ) {
            if ( str_contains($rule[2], 'position:inherit') && str_contains($rule[2], 'inset:inherit') ) {
                $leaf = $rule;
            }
        }
    }
    if ( null === $leaf ) {
        fwrite(STDERR, "FAIL: the image under a box-less stacked layer figure must take the layer's position and insets\n" . $css . "\n");
        exit(1);
    }
    // Zero weight: an author rule that positions the image itself still wins.
    if ( ! str_starts_with(trim($leaf[1]), ':where(') ) {
        fwrite(STDERR, "FAIL: the inherited placement must carry no specificity\n" . $leaf[0] . "\n");
        exit(1);
    }
}

echo "OK: stacked inline SVG image position\n";
