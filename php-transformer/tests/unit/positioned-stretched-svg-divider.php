<?php
declare(strict_types=1);

/**
 * An absolutely positioned inline SVG divider stretched by author CSS (a
 * 1x1 viewBox with preserveAspectRatio="none" and a percentage height) is
 * materialized as a standalone core/image. Its synthetic figure must become
 * the out-of-flow box with the authored size, and the image must fill it.
 * Before, a type subject (`.layer svg`) left the figure static, so the image
 * fell into flow at the viewBox's 1:1 ratio (a full-width square), and a
 * class subject resolved the percentage height against the figure's auto
 * height. Browser evidence: tools/visual-parity/tests/positioned-stretched-svg-divider.mjs.
 *
 * @see https://github.com/Automattic/blocks-engine/issues/7220
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

$transform = static function (string $html): array {
    $out = ( new HtmlTransformer() )->transform($html)->toArray();
    $css = '';
    foreach ( $out['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) && ! str_contains((string) ( $asset['path'] ?? '' ), 'engine-support') ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }
    return array( 'blocks' => (string) ( $out['serialized_blocks'] ?? '' ), 'css' => $css );
};

// 1. The neutral fixture: a type subject inside a positioned divider layer.
$fixture = $transform((string) file_get_contents(dirname(__DIR__) . '/fixtures/positioned-stretched-svg-divider.html'));
$assert(1 === preg_match('/<figure class="wp-block-image divider-stroke[^"]*"><img src="assets\/materialized-svg\/inline-svg-/', $fixture['blocks']), '1a: the divider is a materialized-SVG core/image', $fixture['blocks']);
$assert(
    str_contains($fixture['css'], '.divider-layer :where(figure):not(be-t-0).wp-block-image{position:absolute;left:0;bottom:0;width:100%;height:77.5%}'),
    '1b: the figure is the out-of-flow box with the authored size',
    $fixture['css']
);
$assert(
    str_contains($fixture['css'], '.divider-layer :where(figure):not(be-t-0).wp-block-image > a > img{display:block;width:100%;height:100%;object-fit:inherit;object-position:inherit;border-radius:inherit}'),
    '1c: the image fills the figure without restating the percentage height',
    $fixture['css']
);

// 2. A class subject keeps its out-of-flow box on the figure too.
$svg = '<svg class="divider-stroke" viewBox="0 0 1 1" preserveAspectRatio="none" aria-hidden="true"><path d="M0 0 L1 1" fill="none" stroke="#000"/></svg>';
$classSubject = $transform('<style>.band{position:relative}.layer{position:absolute;inset:0}.layer .divider-stroke{position:absolute;left:0;bottom:0;width:100%;height:77.5%}</style><main><section class="band"><p>Copy</p><div class="layer">' . $svg . '</div></section></main>');
$assert(
    str_contains($classSubject['css'], '.layer .divider-stroke{position:absolute;left:0;bottom:0;width:100%;height:77.5%}'),
    '2a: the class subject\'s figure keeps the authored size',
    $classSubject['css']
);
$assert(
    ! str_contains($classSubject['css'], 'border-radius:inherit;width:100%;height:77.5%'),
    '2b: the image leaf does not resolve the percentage height against the figure',
    $classSubject['css']
);

// 3. An in-flow SVG keeps its leaf-owned replaced box.
$inFlow = $transform('<style>.layer .divider-stroke{display:block;width:100%;height:120px}</style><main><section><p>Copy</p><div class="layer">' . $svg . '</div></section></main>');
$assert(
    str_contains($inFlow['css'], 'border-radius:inherit;display:block;width:100%;height:120px}'),
    '3: an in-flow SVG image leaf still owns its size',
    $inFlow['css']
);

if ( $failures > 0 ) {
    fwrite(STDERR, sprintf('%d failure(s), %d pass(es)' . PHP_EOL, $failures, $passes));
    exit(1);
}

echo sprintf('Positioned stretched SVG divider tests passed (%d assertions).' . PHP_EOL, $passes);
