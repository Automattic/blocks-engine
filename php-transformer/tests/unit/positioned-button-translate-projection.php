<?php
declare(strict_types=1);

/**
 * Authored placement belongs on the box that owns the source positioning
 * context. Projecting translate/transform onto the inner core/button link
 * displaces the visible control by its own size while the wrapper stays put.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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

$placementOn = static function (string $css, string $property) : array {
    $onWrapper = false;
    $onLink = false;
    foreach ( preg_split('/(?<=\})/', $css) ?: array() as $rule ) {
        if ( 1 !== preg_match('/(?:^|[;{])\s*' . preg_quote($property, '/') . '\s*:/', $rule) ) {
            continue;
        }
        $brace = strpos($rule, '{');
        if ( false === $brace ) {
            continue;
        }
        $selector = substr($rule, 0, $brace);
        if ( str_contains($selector, 'wp-block-button__link') ) {
            $onLink = true;
        } else {
            $onWrapper = true;
        }
    }

    return array( $onWrapper, $onLink );
};

$positioned = ( new HtmlTransformer() )->transform(
    '<style>'
    . '.stage{position:relative;width:400px;height:400px}'
    . '.place{position:absolute;translate:-50% -50%}'
    . '.pin{padding:8px 16px;background:#111;color:#fff;border:0}'
    . '</style>'
    . '<div class="stage">'
    . '<div class="place" style="left:50%;top:50%">Hub</div>'
    . '<button class="pin place" type="button" style="left:50%;top:6%">North</button>'
    . '</div>'
)->toArray();
$positionedCss = $cssOf($positioned);
$positionedMarkup = (string) ( $positioned['serialized_blocks'] ?? '' );
[ $translateOnWrapper, $translateOnLink ] = $placementOn($positionedCss, 'translate');

$assert(
    str_contains($positionedMarkup, '<!-- wp:button'),
    '1: a positioned source button still converts to core/button',
    $positionedMarkup
);
$assert(
    $translateOnWrapper && (bool) preg_match('/:where\(\.wp-block-button\)\{[^}]*translate:-50% -50%/', $positionedCss),
    '2: authored translate stays on the wp-block-button box that owns positioning',
    $positionedCss
);
$assert(
    ! $translateOnLink,
    '3: authored translate is not duplicated onto the inner link',
    $positionedCss
);
$assert(
    (bool) preg_match('/wp-block-button__link\)\{[^}]*padding:8px 16px/', $positionedCss)
        && (bool) preg_match('/wp-block-button__link\)\{[^}]*background:#111/', $positionedCss)
        && (bool) preg_match('/wp-block-button__link\)\{[^}]*color:#fff/', $positionedCss),
    '4: colour, padding, and fill still project onto the inner link',
    $positionedCss
);
$assert(
    'pass' === ( $positioned['source_reports']['wp_block_validity']['status'] ?? '' ),
    '5: positioned translated buttons remain valid native blocks',
    (string) json_encode($positioned['source_reports']['wp_block_validity'] ?? array())
);

$transform = ( new HtmlTransformer() )->transform(
    '<style>'
    . '.stage{position:relative;width:400px;height:400px}'
    . '.place{position:absolute;transform:translate(-50%,-50%)}'
    . '.pin{padding:8px 16px;background:#111;color:#fff}'
    . '</style>'
    . '<div class="stage">'
    . '<div class="place" style="left:50%;top:50%">Hub</div>'
    . '<a class="pin place" href="#n" style="left:50%;top:6%">North</a>'
    . '</div>'
)->toArray();
$transformCss = $cssOf($transform);
[ $transformOnWrapper, $transformOnLink ] = $placementOn($transformCss, 'transform');
$assert(
    $transformOnWrapper && ! $transformOnLink,
    '6: authored transform placement stays off the inner link',
    $transformCss
);

$custom = ( new HtmlTransformer() )->transform(
    '<style>'
    . '.stage{position:relative;width:400px;height:400px}'
    . '.place{position:absolute;--shift-x:-50%;--shift-y:-50%;translate:var(--shift-x) var(--shift-y)}'
    . '.pin{padding:8px 16px;background:#111;color:#fff}'
    . '</style>'
    . '<div class="stage">'
    . '<div class="place" style="left:50%;top:50%">Hub</div>'
    . '<button class="pin place" type="button" style="left:50%;top:6%">North</button>'
    . '</div>'
)->toArray();
$customCss = $cssOf($custom);
[ $customTranslateOnWrapper, $customTranslateOnLink ] = $placementOn($customCss, 'translate');
[ $shiftOnWrapper, $shiftOnLink ] = $placementOn($customCss, '--shift-x');
$assert(
    $customTranslateOnWrapper && ! $customTranslateOnLink,
    '7: var()-backed translate stays off the inner link',
    $customCss
);
$assert(
    $shiftOnWrapper && ! $shiftOnLink,
    '8: custom properties consumed by translate travel with it',
    $customCss
);

$ordinary = ( new HtmlTransformer() )->transform(
    '<style>.cta{padding:8px 16px;background:#135e96;color:#fff;border-radius:6px;font-weight:700}</style>'
    . '<main><a class="cta" href="/go">Go</a></main>'
)->toArray();
$ordinaryCss = $cssOf($ordinary);
$assert(
    (bool) preg_match('/wp-block-button__link\)\{[^}]*padding:8px 16px/', $ordinaryCss)
        && (bool) preg_match('/wp-block-button__link\)\{[^}]*background:#135e96/', $ordinaryCss)
        && (bool) preg_match('/wp-block-button__link\)\{[^}]*color:#fff/', $ordinaryCss)
        && (bool) preg_match('/wp-block-button__link\)\{[^}]*font-weight:700/', $ordinaryCss),
    '9: an ordinary in-flow button still projects chrome onto its link',
    $ordinaryCss
);

if ( $failures ) {
    fwrite(STDERR, $failures . " positioned-button translate projection test(s) failed\n");
    exit(1);
}

echo 'Positioned-button translate projection tests: ' . $passes . " passed\n";
