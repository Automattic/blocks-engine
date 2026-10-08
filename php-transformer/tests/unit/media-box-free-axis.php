<?php
declare(strict_types=1);

/**
 * A carried media box that only limits one axis leaves the other to intrinsic
 * sizing. The block carries no width/height when CSS owns the box, but the
 * editor's image block writes the asset's natural dimensions onto its `<img>`,
 * and those presentation attributes give the free axis a definite size. The
 * limited axis then shrinks while the free one stays at its natural pixel
 * count and the artwork stretches. The carried rule has to say `auto` for the
 * axes the box does not size.
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
        if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }

    return array( 'blocks' => (string) ( $out['serialized_blocks'] ?? '' ), 'css' => $css );
};

$mediaRule = static function (string $css): string {
    return preg_match('/\.be-inline-geometry-[0-9a-f]+>img\{[^{}]*\}/', $css, $match) ? $match[0] : '';
};

// gameover.ai: `<svg style="max-height:50vh" viewBox="0 0 260 511.59">`. Only the
// height is limited, so the editor's injected `width="260"` pins the width and
// the logo renders 260 wide at a 385.5 height instead of ~196 wide.
$heightLimited = $transform(
    '<main><svg xmlns="http://www.w3.org/2000/svg" style="max-height:50vh" viewBox="0 0 260 511.59">'
    . '<path d="M10 10H90V90H10z"></path></svg></main>'
);
$heightLimitedRule = $mediaRule($heightLimited['css']);

$assert('' !== $heightLimitedRule, '1: the height-limited SVG carries its media box into a generated rule', $heightLimited['css']);
$assert(str_contains($heightLimitedRule, 'max-height:50vh'), '2: the carried limit is preserved', $heightLimitedRule);
$assert(str_contains($heightLimitedRule, 'width:auto'), '3: the axis the box does not size is freed', $heightLimitedRule);
$assert(
    ! preg_match('/<img[^>]*\b(width|height)=/', $heightLimited['blocks'])
        && ! str_contains($heightLimited['blocks'], '"width":"260px"'),
    '4: the block still carries no intrinsic dimensions of its own',
    $heightLimited['blocks']
);

// A responsive width with no height: the editor's injected `height` pins the
// height, and `aspect-ratio` cannot resolve it because a replaced element
// applies a ratio only while an axis is `auto`.
$widthSized = $transform(
    '<style>.map-art{width:100%;aspect-ratio:2}</style>'
    . '<main><div><svg class="map-art" viewBox="0 0 440 280"><rect width="440" height="280" fill="#111"/></svg></div></main>'
);
$widthSizedRule = $mediaRule($widthSized['css']);

$assert(str_contains($widthSizedRule, 'width:100%'), '5: the authored responsive width is preserved', $widthSizedRule);
$assert(str_contains($widthSizedRule, 'height:auto'), '6: the free axis is freed on the other side too', $widthSizedRule);
$assert(! str_contains($widthSizedRule, 'width:auto'), '7: the sized axis keeps its author value', $widthSizedRule);

// A definite size on both axes is a complete media box. Nothing is added: the
// source pinned both axes itself, so the injected attributes change nothing.
$definite = $transform(
    '<style>.badge{width:120px;height:60px}</style>'
    . '<main><div><svg class="badge" viewBox="0 0 260 511.59"><path d="M10 10H90V90H10z"></path></svg></div></main>'
);
$definiteRule = $mediaRule($definite['css']);

$assert(str_contains($definiteRule, 'width:120px') && str_contains($definiteRule, 'height:60px'), '8: a definite media box is carried unchanged', $definiteRule);
$assert(! str_contains($definiteRule, ':auto'), '9: a definite media box gains no auto axis', $definiteRule);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "media box free axis: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "media box free axis: {$passes} passed" . PHP_EOL);
