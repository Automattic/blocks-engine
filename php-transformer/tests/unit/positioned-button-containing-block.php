<?php
declare(strict_types=1);

/**
 * A synthesized core/buttons wrapper must not become the containing block for
 * positioned children. Percentage offsets resolve against the authored parent.
 * Authored button groups keep their layout, gap, and alignment.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
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
    '<style>.stage{position:relative;width:400px;height:400px}.pin{position:absolute}</style>'
    . '<div class="stage" style="position:relative;width:400px;height:400px">'
    . '<button class="pin" type="button" style="position:absolute;left:50%;top:6%">North</button>'
    . '<button class="pin" type="button" style="position:absolute;left:88%;top:28%">East</button>'
    . '<button class="pin" type="button" style="position:absolute;left:12%;top:72%">West</button>'
    . '</div>'
)->toArray();
$positionedMarkup = (string) ( $positioned['serialized_blocks'] ?? '' );
$positionedCss = $cssOf($positioned);

$assert(
    3 === preg_match_all('/class="wp-block-buttons[^"]*' . preg_quote($neutralClass, '/') . '/', $positionedMarkup),
    'each synthesized wrapper around a positioned button is marked layout-neutral',
    $positionedMarkup
);
$assert(
    str_contains($positionedCss, ':where(.' . $neutralClass . '){display:contents!important}'),
    'layout-neutral wrappers do not generate a box',
    $positionedCss
);
$assert(
    str_contains($positionedCss, ':where(.' . $neutralClass . ')>.wp-block-button:not(.' . SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTON_CLASS . '){width:fit-content}')
        && str_contains($positionedCss, ':where(.' . $neutralClass . ')>.wp-block-button:not(.' . SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTON_CLASS . ')>.wp-block-button__link{display:block;word-break:normal}'),
    'a positioned converted button shrink-to-fits against its containing block instead of collapsing to min-content',
    $positionedCss
);
$assert(
    str_contains($positionedCss, 'left:50%') && str_contains($positionedCss, 'top:6%')
        && str_contains($positionedCss, 'left:88%') && str_contains($positionedCss, 'top:28%')
        && str_contains($positionedCss, 'left:12%') && str_contains($positionedCss, 'top:72%'),
    'child-owned percentage offsets are still carried',
    $positionedCss
);
$assert(
    'pass' === ( $positioned['source_reports']['wp_block_validity']['status'] ?? '' ),
    'positioned buttons remain valid native blocks',
    (string) json_encode($positioned['source_reports']['wp_block_validity'] ?? array())
);

$group = ( new HtmlTransformer() )->transform(
    '<style>.actions{display:flex;gap:16px;justify-content:center}.btn{padding:8px 16px;background:#111;color:#fff}</style>'
    . '<div class="actions">'
    . '<a class="btn" href="/one">One</a>'
    . '<a class="btn" href="/two">Two</a>'
    . '</div>'
)->toArray();
$groupMarkup = (string) ( $group['serialized_blocks'] ?? '' );
$groupCss = $cssOf($group);

$assert(
    ! str_contains($groupMarkup, $neutralClass),
    'in-flow button wrappers are not marked layout-neutral',
    $groupMarkup
);
$assert(
    2 === preg_match_all('/class="wp-block-buttons /', $groupMarkup),
    'in-flow controls keep ordinary synthesized buttons wrappers',
    $groupMarkup
);
$assert(
    ! str_contains($groupCss, ':where(.' . $neutralClass . '){display:contents!important}'),
    'in-flow button wrappers are not flattened',
    $groupCss
);
$assert(
    str_contains($groupCss, 'gap:16px') && str_contains($groupCss, 'justify-content:center'),
    'the authored flex group keeps gap and alignment',
    $groupCss
);

$inFlow = ( new HtmlTransformer() )->transform(
    '<main><a class="btn" href="/go" style="padding:8px 16px;background:#135e96;color:#fff">Go</a></main>'
)->toArray();
$inFlowMarkup = (string) ( $inFlow['serialized_blocks'] ?? '' );
$assert(
    str_contains($inFlowMarkup, $neutralClass) && str_contains($inFlowMarkup, 'wp-block-buttons'),
    'an inline in-flow control is layout-neutral so it does not acquire a block-level box',
    $inFlowMarkup
);

if ( $failures ) {
    fwrite(STDERR, $failures . " positioned-button containing-block test(s) failed\n");
    exit(1);
}

echo 'Positioned-button containing-block tests: ' . $passes . " passed\n";
