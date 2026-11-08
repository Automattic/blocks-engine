<?php
declare(strict_types=1);

/**
 * Layout participation is resolved once. Synthesized core/buttons wrappers
 * follow that result instead of per-tag special cases.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutParticipation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;

$failures = 0;
$passes   = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$cssOf = static function (array $result): string {
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }

    return $css;
};

$neutralButtons = SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTONS_CLASS;
$neutralButton  = SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTON_CLASS;

$inline = ( new HtmlTransformer() )->transform(
    '<div class="cta-host"><a class="cta" href="/contact" style="padding:12px 24px;font-size:14px;line-height:20px;background:#111;color:#fff">Start Your Project</a></div>'
)->toArray();
$inlineMarkup = (string) ( $inline['serialized_blocks'] ?? '' );
$inlineCss = $cssOf($inline);
$assert(
    str_contains($inlineMarkup, $neutralButtons) && str_contains($inlineMarkup, $neutralButton),
    '1: an inline-participating control does not acquire a block-level buttons box',
    $inlineMarkup
);
$assert(
    str_contains($inlineCss, ':where(.' . $neutralButton . ')>.wp-block-button__link{' . LayoutParticipation::transferredItemDeclarations() . '}'),
    '2: neutralizing the inner wrapper transfers inline participation onto the link',
    $inlineCss
);

$flex = ( new HtmlTransformer() )->transform(
    '<style>.row{display:flex;flex-wrap:wrap;gap:12px}.chip{padding:8px 20px;font-size:14px;line-height:20px}</style>'
    . '<div class="row"><button class="chip" type="button">All</button><button class="chip" type="button">Kitchens</button></div>'
)->toArray();
$flexMarkup = (string) ( $flex['serialized_blocks'] ?? '' );
$flexCss = $cssOf($flex);
$assert(
    str_contains($flexMarkup, $neutralButtons) && str_contains($flexMarkup, $neutralButton),
    '3: a flex-item control uses the same layout-neutral wrappers',
    $flexMarkup
);
$assert(
    str_contains($flexCss, ':where(.' . $neutralButton . ')>.wp-block-button__link{' . LayoutParticipation::transferredItemDeclarations() . '}'),
    '4: neutralizing both wrappers transfers shrink-to-fit onto the link, not the contents box',
    $flexCss
);
$assert(
    ! str_contains($flexCss, ':where(.' . $neutralButtons . ')>.wp-block-button{width:fit-content}'),
    '5: shrink-to-fit is not left on a neutralized inner wrapper',
    $flexCss
);

$positioned = ( new HtmlTransformer() )->transform(
    '<div class="stage" style="position:relative;width:400px;height:400px">'
    . '<button type="button" style="position:absolute;left:50%;top:6%">North</button>'
    . '</div>'
)->toArray();
$positionedMarkup = (string) ( $positioned['serialized_blocks'] ?? '' );
$assert(
    str_contains($positionedMarkup, $neutralButtons) && ! str_contains($positionedMarkup, $neutralButton),
    '6: a positioned control keeps the inner button box as the containing-block child',
    $positionedMarkup
);
$positionedCss = $cssOf($positioned);
$assert(
    str_contains($positionedCss, ':where(.' . $neutralButtons . ')>.wp-block-button:not(.' . $neutralButton . '){' . LayoutParticipation::retainedWrapperBoxDeclarations() . '}'),
    '6a: the retained button box keeps shrink-to-fit as its own box declaration',
    $positionedCss
);
$assert(
    str_contains($positionedCss, ':where(.' . $neutralButtons . ')>.wp-block-button:not(.' . $neutralButton . ')>.wp-block-button__link{' . LayoutParticipation::retainedWrapperLinkDeclarations() . '}'),
    '6b: the link inside a retained button box keeps ordinary button chrome, not the transferred-item declarations',
    $positionedCss
);

$assert(
    'width:fit-content' === LayoutParticipation::retainedWrapperBoxDeclarations(),
    '8: retainedWrapperBoxDeclarations() is the single source EngineSupportCss projects for a positioned control\'s retained box'
);
$assert(
    'display:block;word-break:normal' === LayoutParticipation::retainedWrapperLinkDeclarations(),
    '9: retainedWrapperLinkDeclarations() is the single source EngineSupportCss projects for the link inside a retained box'
);
$assert(
    str_starts_with(LayoutParticipation::transferredFlexContainerDeclarations(), 'display:flex!important;')
        && str_contains(LayoutParticipation::transferredFlexContainerDeclarations(), 'padding:inherit!important'),
    '10: transferredFlexContainerDeclarations() restores a source flex container onto the link, inheriting wrapper padding'
);

$block = ( new HtmlTransformer() )->transform(
    '<style>.cta{display:block;width:100%;padding:8px 16px;background:#111;color:#fff}</style>'
    . '<main><a class="cta" href="/go">Go</a></main>'
)->toArray();
$blockMarkup = (string) ( $block['serialized_blocks'] ?? '' );
$assert(
    ! str_contains($blockMarkup, $neutralButtons) && ! str_contains($blockMarkup, $neutralButton) && str_contains($blockMarkup, 'wp-block-buttons'),
    '7: a block-level in-flow control keeps a normal synthesized buttons wrapper',
    $blockMarkup
);

$inlineFlexPair = ( new HtmlTransformer() )->transform(
    '<style>.btn{display:inline-flex;padding:14px 24px;background:#ddd}</style>'
    . '<div><h2>Get started.</h2><a href="/signup" class="btn">Sign up</a><a href="/demo" class="btn">Book a demo</a></div>'
)->toArray();
$inlineFlexPairMarkup = (string) ( $inlineFlexPair['serialized_blocks'] ?? '' );
$assert(
    2 === preg_match_all('/class="wp-block-buttons[^"]*\b' . preg_quote($neutralButtons, '/') . '\b/', $inlineFlexPairMarkup)
        && 2 === preg_match_all('/class="wp-block-button [^"]*\b' . preg_quote($neutralButton, '/') . '-display-inline-flex\b/', $inlineFlexPairMarkup),
    '11: adjacent authored inline-flex anchors in block flow share a line instead of each getting a block-level buttons box',
    $inlineFlexPairMarkup
);

foreach ( array( 'inline-block', 'inline-flex', 'inline-grid', 'inline-table' ) as $display ) {
    $result = ( new HtmlTransformer() )->transform(
        '<style>.btn{display:' . $display . ';padding:8px;background:#ddd}</style>'
        . '<main><h2>Actions</h2><a class="btn" href="/one">One</a><a class="btn" href="/two">Two</a></main>'
    )->toArray();
    $markup = (string) $result['serialized_blocks'];
    $assert(
        2 === preg_match_all('/class="wp-block-button [^"]*\\b' . preg_quote($neutralButton . '-display-' . $display, '/') . '\\b/', $markup)
            && str_contains($cssOf($result), '>.wp-block-button__link{display:' . $display . '!important}'),
        'authored ' . $display . ' controls retain their display on the participating link',
        $markup
    );
}

// In-flow positioning must not blockify authored inline-level controls (#5007).
foreach ( array( 'relative', 'sticky' ) as $position ) {
    $result = ( new HtmlTransformer() )->transform(
        '<style>.btn{display:inline-flex;position:' . $position . ';padding:8px;background:#ddd}</style>'
        . '<main><a class="btn" href="/one">One</a><a class="btn" href="/two">Two</a></main>'
    )->toArray();
    $markup = (string) $result['serialized_blocks'];
    $assert(
        2 === preg_match_all('/class="wp-block-button [^"]*\\b' . preg_quote($neutralButton . '-display-inline-flex', '/') . '\\b/', $markup),
        'position:' . $position . ' controls still share a line',
        $markup
    );
}

foreach ( array( 'float:left', 'float:right', 'position:absolute', 'position:fixed', 'width:100%', 'width:100.0%' ) as $exception ) {
    $result = ( new HtmlTransformer() )->transform(
        '<style>.btn{display:inline-flex;padding:8px;background:#ddd;' . $exception . '}</style>'
        . '<main><a class="btn" href="/go">Go</a></main>'
    )->toArray();
    $assert(
        ! str_contains((string) $result['serialized_blocks'], $neutralButton . '-display-inline-flex'),
        'blockified or full-line controls retain their carrier: ' . $exception,
        (string) $result['serialized_blocks']
    );
}

if ( $failures ) {
    fwrite(STDERR, $failures . " layout-participation test(s) failed\n");
    exit(1);
}

echo 'Layout-participation tests: ' . $passes . " passed\n";
