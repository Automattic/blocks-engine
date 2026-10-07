<?php
declare(strict_types=1);

/**
 * The inset-pinned parent fill from #2494, when the source states it only
 * inside a media query.
 *
 * A responsive capture (Data Liberation Agent) links the desktop stylesheet
 * with `media="(min-width:768px)"`, which puts the Wix logo SVG's
 * `width:var(--svg-calculated-width,100%)` and its wrapper's
 * `position:absolute; inset:0` under `@media (min-width:768px)`. The resting
 * cascade then sees neither, so the logo took the inline path and rendered at
 * 0x0. The fill must hold under the media condition that states it, and only
 * there.
 *
 * @see https://github.com/Automattic/blocks-engine/issues/2490
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

/**
 * @return array{blocks: string, carrier: string, css: string}
 */
$transform = static function (string $css): array {
    $svg = '<svg preserveAspectRatio="xMidYMid meet" viewBox="0 0 67.38 34.36" role="img" aria-label="Homepage">'
        . '<path d="M20.42 0v12.64H19V0c-1.44 1.53-4.64 5.41-4.64 10 0 5.9 5.34 10.68 5.34 10.68z" fill="#231f20"/></svg>';
    $html = '<style>' . $css . '</style><header><div id="comp-logo"><a href="https://example.com/" class="IT88M3"><div class="iL7Pq5">'
        . $svg . '</div></a></div></header>';
    $out = ( new HtmlTransformer() )->transform($html)->toArray();
    $generated = '';
    foreach ( $out['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
            $generated .= (string) ( $asset['content'] ?? '' ) . "\n";
        }
    }
    $blocks = (string) ( $out['serialized_blocks'] ?? '' );
    $figureClass = preg_match('/<figure class="([^"]*)"/', $blocks, $match) ? $match[1] : '';
    $carrier = preg_match('/\bbe-inline-geometry-[a-f0-9-]+/', $figureClass, $match) ? $match[0] : '';
    $css = '';
    foreach ( explode("\n", $generated) as $line ) {
        if ( '' !== $carrier && str_contains($line, $carrier) ) {
            $css .= $line;
        }
    }

    return array( 'blocks' => $blocks, 'carrier' => $carrier, 'css' => $css );
};

$wix = '.iL7Pq5 svg{width:var(--svg-calculated-width,100%);height:var(--svg-calculated-height,100%);margin:auto;position:absolute;inset:0}'
    . '.iL7Pq5{position:absolute;inset:0}.IT88M3{position:absolute;inset:0}'
    . '#comp-logo{width:70px;height:36px;position:relative}';
$desktop = static fn (string $css): string => '@media (min-width:768px){' . $css . '}';

/** CSS outside any media block, with every `@media (...){...}` block removed. */
$unconditioned = static function (string $css): string {
    $out = '';
    $depth = 0;
    $length = strlen($css);
    for ( $i = 0; $i < $length; ++$i ) {
        if ( 0 === $depth && str_starts_with(substr($css, $i, 6), '@media') ) {
            $open = strpos($css, '{', $i);
            if ( false === $open ) {
                break;
            }
            $depth = 1;
            for ( $i = $open + 1; $i < $length && $depth > 0; ++$i ) {
                $depth += '{' === $css[$i] ? 1 : ( '}' === $css[$i] ? -1 : 0 );
            }
            --$i;
            continue;
        }
        $out .= $css[$i];
    }

    return $out;
};

// 1. The responsive capture shape: everything inside @media (min-width:768px).
$scoped = $transform($desktop($wix));
$c = $scoped['carrier'];
$assert('' !== $c, '1a: the logo figure has a geometry carrier', $scoped['blocks']);
$assert(str_contains($scoped['blocks'], '<a href="https://example.com/"><img'), '1b: the logo keeps its link', $scoped['blocks']);
$assert(
    1 === preg_match('/@media \(min-width:768px\)\{[^@]*\.wp-block-image\.' . preg_quote($c, '/') . '>a>img\{width:100%;height:100%;-o-object-fit:contain;object-fit:contain\}/', $scoped['css']),
    '1c: the linked img fills the figure under the desktop media condition',
    $scoped['css']
);
$assert(
    1 === preg_match('/@media \(min-width:768px\)\{[^@]*\.' . preg_quote($c, '/') . '>a\{display:block;width:100%;height:100%\}/', $scoped['css']),
    '1d: the link fills the figure under the desktop media condition',
    $scoped['css']
);
$assert(
    1 === preg_match('/@media \(min-width:768px\)\{[^@]*\.' . preg_quote($c, '/') . '\{margin:0;width:100%;height:100%;line-height:0\}/', $scoped['css']),
    '1e: the figure fills the pinned wrapper under the desktop media condition',
    $scoped['css']
);
$assert(
    ! str_contains($unconditioned($scoped['css']), 'width:100%;height:100%'),
    '1f: no fill outside the media condition that states it',
    $unconditioned($scoped['css'])
);

// 2. Mobile places the wrapper statically: only desktop fills.
$mobileStatic = $transform($desktop($wix) . '@media (max-width:767px){.iL7Pq5{position:static}.iL7Pq5 svg{width:40px;height:20px;position:static}}');
$assert(str_contains($mobileStatic['css'], '@media (min-width:768px){.' . $mobileStatic['carrier'] . '{margin:0;width:100%'), '2a: desktop still fills', $mobileStatic['css']);
$assert(
    ! preg_match('/@media \(max-width:767px\)\{[^@]*width:100%;height:100%/', $mobileStatic['css']),
    '2b: mobile is not forced to fill',
    $mobileStatic['css']
);

// 3. The custom property resolves to a fixed size under the same condition.
$fixed = $transform($desktop($wix . '.iL7Pq5{--svg-calculated-width:40px;--svg-calculated-height:20px}'));
$assert(! str_contains($fixed['css'], 'object-fit:contain'), '3: a resolved fixed size is not a parent fill', $fixed['css']);

// 4. The wrapper is pinned only in another condition than the SVG's size.
$split = $transform(
    '@media (min-width:768px){.iL7Pq5 svg{width:var(--svg-calculated-width,100%);height:var(--svg-calculated-height,100%)}#comp-logo{width:70px;height:36px;position:relative}}'
    . '@media print{.iL7Pq5{position:absolute;inset:0}}'
);
$assert(! str_contains($split['css'], 'object-fit:contain'), '4: a pin stated under a different condition is not a fill', $split['css']);

// 5. The resting fill from #2494 is unchanged.
$resting = $transform($wix);
$assert(
    str_contains($unconditioned($resting['css']), '.wp-block-image.' . $resting['carrier'] . '>a>img{width:100%;height:100%;'),
    '5: the unconditioned Wix logo keeps its resting fill',
    $resting['css']
);

// 6. A resting fill keeps a media-only size the fill does not hold under.
$restingMobile = $transform($wix . '@media (max-width:767px){.iL7Pq5 svg{width:40px;height:20px}}');
$assert(
    1 === preg_match('/@media \(max-width:767px\)\{[^@]*\.wp-block-image\.' . preg_quote($restingMobile['carrier'], '/') . '>a>img\{[^}]*width:40px[^}]*height:20px/', $restingMobile['css'])
        || 1 === preg_match('/@media \(max-width:767px\)\{[^@]*\.wp-block-image\.' . preg_quote($restingMobile['carrier'], '/') . '>a>img\{[^}]*height:20px[^}]*width:40px/', $restingMobile['css']),
    '6: the media-scoped size wins over the resting fill under its condition',
    $restingMobile['css']
);

if ( $failures > 0 ) {
    fwrite(STDERR, sprintf('%d failure(s), %d pass(es)' . PHP_EOL, $failures, $passes));
    exit(1);
}

echo sprintf('Media-scoped inset parent-fill SVG tests passed (%d assertions).' . PHP_EOL, $passes);
