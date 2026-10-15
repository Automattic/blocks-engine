<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;

$out = ( new HtmlTransformer() )->transform(
    '<style>.section-background img{object-fit:cover;width:100%;height:100%}</style>'
    . '<main><div class="section-background">'
    . '<img alt="" src="hero.jpg" width="1536" height="1024" style="display:block;object-position:50% 50%">'
    . '</div></main>'
)->toArray();
$markup = (string) ( $out['serialized_blocks'] ?? '' );
if ( ! str_contains($markup, 'object-fit:cover') ) {
    fwrite(STDERR, "FAIL: section background images must keep stylesheet object-fit:cover\n" . $markup . "\n");
    exit(1);
}

$transform = static function (string $html): array {
    $result = ( new HtmlTransformer() )->transform($html)->toArray();
    $css = implode("\n", array_map(
        static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
        $result['assets'] ?? array()
    ));
    return array( (string) ($result['serialized_blocks'] ?? ''), $css );
};
$fillClass = SourceBlockAttributeProjector::SYNTHETIC_FILL_IMAGE_FIGURE_CLASS;
$fillRule = ':root :where(figure.' . $fillClass . '){width:100%}';

// A source image explicitly fills both axes of its parent. The aspect ratio
// belongs to the image, while the extra core/image figure needs the parent's
// full inline extent rather than an intrinsic/shrink-to-fit width.
list( $fillMarkup, $fillCss ) = $transform(
    '<style>.media-frame{height:562px}.media-frame img{width:100%;height:100%;aspect-ratio:1440/562;object-fit:cover}</style>'
    . '<main><div class="media-frame"><img src="landscape.jpg" alt="Landscape" width="1440" height="562"></div></main>'
);
if ( ! preg_match('/<figure[^>]*\b' . preg_quote($fillClass, '/') . '\b[^>]*><img[^>]*style="[^"]*width:100%;height:100%/', $fillMarkup)
    || ! str_contains($fillMarkup, '"aspectRatio":"1440/562"')
    || ! str_contains($fillCss, $fillRule)
) {
    fwrite(STDERR, "FAIL: source-owned fill image needs a full-width synthetic figure without altering native image height\n" . $fillMarkup . "\n" . $fillCss . "\n");
    exit(1);
}

foreach ( array(
    '<style>.media-frame img{width:100%;object-fit:cover}</style><main><div class="media-frame"><img src="wide.jpg" alt="Wide"></div></main>',
    '<style>.media-frame img{width:100%;height:100%;object-fit:cover}</style><main><figure class="media-frame"><img src="authored.jpg" alt="Authored"></figure></main>',
    '<main><div><img src="ordinary.jpg" alt="Ordinary" width="800" height="400"></div></main>',
) as $source ) {
    list( $otherMarkup, $otherCss ) = $transform($source);
    if ( str_contains($otherMarkup, $fillClass) || str_contains($otherCss, $fillRule) ) {
        fwrite(STDERR, "FAIL: full-width figure sizing must require a synthetic figure and source-owned two-axis fill\n" . $otherMarkup . "\n" . $otherCss . "\n");
        exit(1);
    }
}

// An absolutely positioned fill image inside a flex parent is out of flow, not
// a flex item. Its figure carries the positioned geometry, so it must keep a
// real box instead of the transparent display:contents flex-item treatment.
$flexFigureClass = SourceBlockAttributeProjector::SYNTHETIC_FLEX_IMAGE_FIGURE_CLASS;
foreach ( array(
    'inline' => '<main><section class="hero" style="position:relative;display:flex;align-items:flex-end;min-height:600px">'
        . '<img src="hero.jpg" alt="" style="position:absolute;height:100%;width:100%;left:0;top:0;right:0;bottom:0;object-fit:cover">'
        . '<div style="position:absolute;inset:0;background:linear-gradient(#000,transparent)"></div>'
        . '<div class="copy"><h1>Title</h1><p>Lead copy.</p></div></section></main>',
    'stylesheet' => '<style>.hero{position:relative;display:flex;min-height:600px}.hero-photo{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.shade{position:absolute;inset:0;background:#0008}</style>'
        . '<main><section class="hero"><img class="hero-photo" src="hero.jpg" alt="">'
        . '<div class="shade"></div>'
        . '<div class="copy"><h1>Title</h1><p>Lead copy.</p></div></section></main>',
) as $label => $source ) {
    list( $positionedMarkup, $positionedCss ) = $transform($source);
    if ( ! preg_match('/<figure[^>]*\b' . preg_quote($fillClass, '/') . '\b/', $positionedMarkup)
        || str_contains($positionedMarkup, $flexFigureClass)
        || str_contains($positionedCss, $flexFigureClass)
    ) {
        fwrite(STDERR, "FAIL: positioned fill image in a flex parent ({$label}) must keep a real figure box\n" . $positionedMarkup . "\n" . $positionedCss . "\n");
        exit(1);
    }
}

// An in-flow image in a flex parent still gets the transparent figure.
list( $flowMarkup ) = $transform(
    '<main><div style="display:flex"><img src="avatar.jpg" alt="" width="48" height="48"><p>Name</p></div></main>'
);
if ( ! str_contains($flowMarkup, $flexFigureClass) ) {
    fwrite(STDERR, "FAIL: in-flow flex item image keeps the transparent synthetic figure\n" . $flowMarkup . "\n");
    exit(1);
}

fwrite(STDOUT, "section background object-fit tests: passed\n");
