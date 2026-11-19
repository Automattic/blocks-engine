<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A painted flex-column panel lowers to core/cover (#6736). Its authored box
// must survive: padding that varies by breakpoint stays in the author
// stylesheet (no inline zero overriding it at every width), min-height still
// applies, and the authored gap reaches the wrapper group that actually lays
// out the stacked children inside the cover's flow inner container.

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( $condition ) {
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$transform = static function (string $media): array {
    $html = '<style>.panel{background:#f3f3f1;padding:34px;display:flex;flex-direction:column;gap:18px}'
        . '.panel{background-image:url("bg.jpg")!important;background-size:cover!important;color:#fff!important;min-height:360px}'
        . $media . '</style>'
        . '<div class="panel" style="display:flex;flex-direction:column;justify-content:center;gap:20px;align-items:flex-start">'
        . '<h3>Request a session</h3><p>Choose a session and fill in the request form.</p>'
        . '<a class="btn" href="https://example.com/form">Open request form</a><p>Free request</p></div>';
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));

    return array( $result['blocks'][0] ?? array(), (string) ($result['serialized_blocks'] ?? ''), $css );
};

$gapRule = '.wp-block-cover.blocks-engine-cover-gap>.wp-block-cover__inner-container>.wp-block-group{gap:inherit}';

foreach ( array( 'breakpoint padding' => '@media(max-width:600px){.panel{padding:26px}}', 'static padding' => '' ) as $label => $media ) {
    [$cover, $markup, $css] = $transform($media);
    $assert('core/cover' === ($cover['blockName'] ?? null), $label . ': the painted panel lowers to a cover.');
    $padding = $cover['attrs']['style']['spacing']['padding'] ?? array();
    foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
        $assert('0' !== ($padding[$side] ?? null), $label . ': the padded source keeps no inline zero ' . $side . ' padding.');
    }
    $assert(! preg_match('/<div class="wp-block-cover[^"]*" style="[^"]*padding[^:]*:0[;"]/', $markup), $label . ': the cover wrapper carries no inline zero padding.');
    $assert(360 === ($cover['attrs']['minHeight'] ?? null), $label . ': the cover keeps the authored min-height.');
    $assert('core/group' === ($cover['innerBlocks'][0]['blockName'] ?? null), $label . ': the stacked children are laid out by a wrapper group.');
    $assert(str_contains((string) ($cover['attrs']['className'] ?? ''), 'blocks-engine-cover-gap'), $label . ': the cover marks its authored gap for the wrapper group.');
    $assert(str_contains($css, '.wp-block-cover.blocks-engine-cover-gap>.wp-block-cover__inner-container{gap:inherit}') && str_contains($css, $gapRule), $label . ': the wrapper group inherits the authored gap.');
}

[$cover] = $transform('@media(max-width:600px){.panel{padding:26px}}');
$assert(! isset($cover['attrs']['style']['spacing']['padding']), 'Breakpoint-varying padding stays owned by the author stylesheet.');

// An unpadded source still gets the zero fallback against core's 1em padding.
$unpadded = (new HtmlTransformer())->transform(
    '<style>.hero{display:flex;flex-direction:column;min-height:400px;background-image:url(https://example.com/a.jpg);background-size:cover}</style>'
    . '<section class="hero"><h2>Title</h2><p>Copy</p></section>'
)->toArray()['blocks'][0] ?? array();
$assert(
    array( 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0' ) === ($unpadded['attrs']['style']['spacing']['padding'] ?? null),
    'An unpadded source keeps the zero padding fallback.'
);
$assert(! str_contains((string) ($unpadded['attrs']['className'] ?? ''), 'blocks-engine-cover-gap'), 'A source without a gap adds no gap carrier.');

// #6778: use the reported page through the public transform. The same
// contracts apply at both viewport widths; no measured UA margins are baked
// into attributes (the browser remains their owner).
$issuePage = '<style>body{margin:0;font-family:sans-serif}'
    . '.stack{display:flex;flex-direction:column;gap:20px;padding:34px;background:#eee}'
    . '.painted{display:flex;flex-direction:column;gap:20px;padding:34px;min-height:360px;justify-content:center;align-items:flex-start;background:linear-gradient(90deg,#0009,#0004),url(https://picsum.photos/id/10/800/400) center/cover;color:#fff}'
    . '</style><main><div class="stack"><h3>Plain stack</h3><p>Paragraph with its default margins.</p><a href="https://example.com/">Link</a></div>'
    . '<div class="painted"><h3>Painted stack</h3><p>Paragraph with its default margins.</p><a href="https://example.com/">Link</a></div></main>';
$findCover = static function (array $blocks) use (&$findCover): array {
    foreach ( $blocks as $block ) {
        if ( 'core/cover' === ($block['blockName'] ?? '') ) {
            return $block;
        }
        $found = $findCover($block['innerBlocks'] ?? array());
        if ( array() !== $found ) {
            return $found;
        }
    }
    return array();
};
foreach ( array( 'UA margins' => $issuePage, 'authored margins' => str_replace('</style>', '.painted>h3{margin:12px 7px}.painted>p{margin:9px 5px}</style>', $issuePage) ) as $label => $page ) {
    $result = (new HtmlTransformer())->transform($page)->toArray();
    $cover = $findCover($result['blocks'] ?? array());
    $css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
    $group = $cover['innerBlocks'][0] ?? array();
    $assert('core/cover' === ($cover['blockName'] ?? ''), $label . ': the issue panel lowers to a cover.');
    $assert(str_contains((string) ($group['attrs']['className'] ?? ''), 'blocks-engine-css-owned-flow'), $label . ': cover items use the plain stack margin contract.');
    $assert(str_contains($css, '.blocks-engine-css-owned-flow.is-layout-flex)>:where(') && str_contains($css, '{margin:revert}'), $label . ': physical flex-item margins revert before author CSS.');
    $assert('center left' === ($cover['attrs']['contentPosition'] ?? ''), $label . ': native cover placement projects both source axes.');
    $assert(array('type' => 'flex', 'orientation' => 'vertical', 'flexWrap' => 'nowrap', 'justifyContent' => 'left', 'verticalAlignment' => 'center') === ($group['attrs']['layout'] ?? null), $label . ': the editor-native group projects both source axes.');
    $assert(str_contains((string) ($cover['attrs']['className'] ?? ''), 'blocks-engine-cover-fill')
        && str_contains($css, '.blocks-engine-cover-fill>.wp-block-cover__inner-container{flex:1 1 auto;align-self:stretch;display:flex;flex-direction:column}')
        && str_contains($css, '.blocks-engine-cover-fill>.wp-block-cover__inner-container>.wp-block-group{flex-grow:1}'), $label . ': the column group fills the cover so vertical centring has free space.');
    $assert(str_contains($css, $gapRule), $label . ': gap remains additive to child margins.');
    foreach ( $group['innerBlocks'] ?? array() as $child ) {
        $assert(! isset($child['attrs']['style']['spacing']['margin']), $label . ': no fixed browser margin is synthesized on a child.');
    }
}

// A flow marker without a native flex wrapper must not change unrelated
// document stylesheets (including form diagnostic asset identities).
$flowOnly = (new HtmlTransformer())->transform('<div style="display:flex;flex-direction:column;gap:20px"><h3>Title</h3><p>Copy</p></div>')->toArray();
$flowCss = implode("\n", array_column($flowOnly['assets'] ?? array(), 'content'));
$assert(! str_contains($flowCss, '.blocks-engine-css-owned-flow.is-layout-flex)>:where('), 'A plain CSS-owned stack needs no native flex reset.');

if ( $failures > 0 ) {
    exit(1);
}

echo "cover flex breakpoint padding and gap: ok\n";
