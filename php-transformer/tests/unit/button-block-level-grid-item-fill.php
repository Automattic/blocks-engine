<?php
declare(strict_types=1);

/**
 * A block-level flex anchor in an equal-column grid fills its cell. The
 * core/buttons wrapper that replaces it must not shrink to the label.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
    }
};

$cssOf = static function (string $html): string {
    $out = ( new HtmlTransformer() )->transform($html)->toArray();
    $css = '';
    foreach ( $out['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }
    return $css;
};

$style = '<style>.bar{display:grid;grid-template-columns:1fr 1fr}'
    . '.bar a,.pri{display:flex;align-items:center;justify-content:center;padding:14px 0}'
    . '.pri{background:#497969;color:#fff}</style>';

// 1. Grid item: the cell sizes it, so no intrinsic width may be projected.
$css = $cssOf($style . '<main><div class="bar"><a href="/call">Call</a><a class="pri" href="/book">Book</a></div></main>');
$assert(str_contains($css, 'wp-block-button'), '1: pillar CTA becomes a native button', $css);
$assert(! str_contains($css, 'max-content'), '1: grid-cell button must not shrink to max-content', $css);

// 2. Block flow: a display:flex anchor spans its line as well.
$css = $cssOf($style . '<main><div><a class="pri" href="/book">Book</a></div></main>');
$assert(str_contains($css, 'wp-block-button'), '2: block-flow CTA becomes a native button', $css);
$assert(! str_contains($css, 'max-content'), '2: block-level flex button in flow must not shrink to max-content', $css);

// 3. Inline-level and flex-row items still size to content.
$css = $cssOf('<style>.row{display:flex}.pri{display:inline-flex;padding:14px 20px;background:#497969;color:#fff}</style><main><div class="row"><a class="pri" href="/book">Book</a></div></main>');
$assert(str_contains($css, 'wp-block-button') && str_contains($css, 'max-content'), '3: inline-flex button keeps intrinsic width', $css);
$css = $cssOf('<style>.row{display:flex}.pri{display:flex;padding:14px 20px;background:#497969;color:#fff}</style><main><div class="row"><a class="pri" href="/book">Book</a></div></main>');
$assert(str_contains($css, 'wp-block-button') && str_contains($css, 'max-content'), '4: flex item in a flex row keeps intrinsic width', $css);

if ( $failures > 0 ) {
    exit(1);
}
echo "OK\n";
