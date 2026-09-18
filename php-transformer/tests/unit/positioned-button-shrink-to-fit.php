<?php
declare(strict_types=1);

/**
 * An absolutely positioned converted button whose natural width is below its
 * max-width must shrink-to-fit against its containing block, not collapse to
 * min-content. Class-owned position lands on the wp-block-button box because
 * the synthesized buttons wrapper is layout-neutral (display:contents).
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

$neutralClass = SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTONS_CLASS;

$positioned = ( new HtmlTransformer() )->transform(
    '<style>'
    . '.stage{position:relative;width:540px;height:540px}'
    . '.absolute{position:absolute}'
    . '.center{translate:-50% -50%}'
    . '.label{display:block;max-width:7.5rem;padding:8px 12px;text-align:center;font-size:9.6px;letter-spacing:1.536px;line-height:13.2px;text-transform:uppercase;white-space:normal}'
    . '</style>'
    . '<div class="stage">'
    . '<div class="absolute center" style="left:50%;top:50%;width:80px;height:80px">Hub</div>'
    . '<button type="button" class="absolute center" style="left:88%;top:28%"><span class="label">Advisors &amp; Distributors</span></button>'
    . '<button type="button" class="absolute center" style="left:88%;top:72%"><span class="label">Platforms &amp; Fintech</span></button>'
    . '</div>'
)->toArray();
$positionedMarkup = (string) ( $positioned['serialized_blocks'] ?? '' );
$positionedCss = $cssOf($positioned);

$assert(
    str_contains($positionedMarkup, $neutralClass) && str_contains($positionedMarkup, '<!-- wp:button'),
    '1: positioned source buttons convert to layout-neutral core/button',
    $positionedMarkup
);
$assert(
    (bool) preg_match('/:where\(\.wp-block-button\)\{[^}]*position:\s*absolute/', $positionedCss),
    '2: class-owned position lands on the wp-block-button box that generates a containing-block child',
    $positionedCss
);
$assert(
    str_contains($positionedCss, ':where(.' . $neutralClass . ')>.wp-block-button:not(.' . SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTON_CLASS . '){width:fit-content}')
        && str_contains($positionedCss, ':where(.' . $neutralClass . ')>.wp-block-button:not(.' . SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTON_CLASS . ')>.wp-block-button__link{display:block;word-break:normal}'),
    '3: the positioned button box shrink-to-fits and the inner link is a block with normal wrapping',
    $positionedCss
);
$assert(
    (bool) preg_match('/:where\(\.wp-block-button\)\{[^}]*translate:-50% -50%/', $positionedCss)
        && ! preg_match('/wp-block-button__link\)\{[^}]*translate:/', $positionedCss),
    '4: authored translate stays on the positioning box only',
    $positionedCss
);
$assert(
    (bool) preg_match('/max-width:\s*7\.5rem/', $positionedCss),
    '5: the inner max-width that caps wrapping is still carried',
    $positionedCss
);
$assert(
    'pass' === ( $positioned['source_reports']['wp_block_validity']['status'] ?? '' ),
    '6: positioned shrink-to-fit buttons remain valid native blocks',
    (string) json_encode($positioned['source_reports']['wp_block_validity'] ?? array())
);

$inFlow = ( new HtmlTransformer() )->transform(
    '<style>.cta{padding:8px 16px;background:#135e96;color:#fff}</style>'
    . '<main><a class="cta" href="/go">Go</a></main>'
)->toArray();
$inFlowCss = $cssOf($inFlow);
$inFlowMarkup = (string) ( $inFlow['serialized_blocks'] ?? '' );
$assert(
    str_contains($inFlowMarkup, $neutralClass) && str_contains($inFlowMarkup, SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTON_CLASS),
    '7: an inline in-flow control is layout-neutral so it does not acquire a block-level box',
    $inFlowMarkup
);
$assert(
    str_contains($inFlowCss, ':where(.' . SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTON_CLASS . ')>.wp-block-button__link{' . LayoutParticipation::transferredItemDeclarations() . '}'),
    '8: inline in-flow buttons transfer participation onto the link, not the positioned shrink-to-fit rule',
    $inFlowCss
);

if ( $failures ) {
    fwrite(STDERR, $failures . " positioned-button shrink-to-fit test(s) failed\n");
    exit(1);
}

echo 'Positioned-button shrink-to-fit tests: ' . $passes . " passed\n";
