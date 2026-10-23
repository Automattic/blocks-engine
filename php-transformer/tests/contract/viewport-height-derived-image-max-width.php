<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * #3336: reduced from the supplied source's grid -> grid item -> sizing
 * wrapper -> mobile wrapper -> image. In Chromium at 390 and 768, the real
 * source itself has narrow (~69px) ancestors: width:100% does not make them
 * wide. Adding only max-width:100% changes the image from 82x82 to 69x82.
 * Removing whole CSS rules/media groups leaves the grid track, definite
 * wrapper height, percentage image height and auto width as the relevant
 * geometry. This neutral fixture retains those relations and the viewport
 * custom-property cascade, not the original page's content or selectors.
 *
 * The contract checks the emitted CSS responsible for that geometry, including
 * controls where neutralizing the clamp would contradict the author cascade.
 */
$compile = static function (string $extra = '', string $mobileWidth = 'auto', string $mobileHeight = '100%'): array {
    $css = '.canvas{display:grid;grid-template-columns:69px 1fr;grid-template-rows:82px}'
        . '.item{display:grid;grid-column:1;grid-row:1}'
        . '.frame{--box-height:82px;--mobile-width:' . $mobileWidth . ';--mobile-height:' . $mobileHeight . '}'
        . '.picture{display:block;width:auto;height:auto;aspect-ratio:82/82;object-fit:cover}'
        . '@media(max-width:920px){.frame{width:100%;height:100%;max-height:var(--box-height)}'
        . '.mobile{width:100%;height:100%}.picture{width:var(--mobile-width);height:var(--mobile-height)}}'
        . $extra;
    return (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => '<!doctype html><html><head><title>Icon</title><style>' . $css
            . '</style></head><body><main><div class="canvas"><div class="item"><div class="frame">'
            . '<div class="mobile"><img class="picture" src="icon.png" width="82" height="82" alt="Icon">'
            . '</div></div></div></div><p>Text</p></main></body></html>'),
    ))->toArray();
};
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$rules = static function (array $result) use ($assert): string {
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $assert(1 === preg_match('/<!-- wp:image (\{.*?\}) -->(.*?)<!-- \/wp:image -->/s', $markup, $match), 'Emits a native image.');
    $attrs = json_decode($match[1], true);
    $assert(empty($attrs['width']) && empty($attrs['height']), 'Viewport dimensions must not be pinned natively.');
    preg_match_all('/be-inline-geometry-[0-9a-f]+/', $match[2], $classes);
    $css = '';
    foreach ($result['assets'] ?? array() as $asset) {
        $css .= (string) ($asset['content'] ?? '');
    }
    $rules = '';
    foreach (array_unique($classes[0]) as $class) {
        if (preg_match('/\.' . $class . ' img\{[^}]*\}/', $css, $rule)) {
            $rules .= $rule[0];
        }
    }
    return $rules;
};
$result = $compile();
$box = $rules($result);
$assert(str_contains($box, 'max-width:none!important'), 'Responsive height-derived width must escape core max-width.');
$assert(! preg_match('/(?:\{|;)height:[^;]*!important/', $box), 'Do not pin the mobile height over desktop CSS.');
$assert(! preg_match('/(?:\{|;)width:[^;]*!important/', $box), 'Do not pin the mobile width over desktop CSS.');

foreach (array(
    array('.picture{max-width:100%}', 'auto', '100%'),
    array('@media(max-width:920px){.picture{max-width:100%}}', 'auto', '100%'),
    array('', '100%', '100%'),
    array('', 'auto', 'auto'),
) as [$extra, $width, $height]) {
    $assert(! str_contains($rules($compile($extra, $width, $height)), 'max-width:none!important'), 'Keep core clamp when author clamps or neither viewport derives width from height.');
}

// The real icon uses responsive-media, not core/image. Serving the declared
// source at 390px (with and without JS) gives a 68.89px item spanning 4/16,
// not a 374px item: the source's percentage tracks really are narrow. The
// unclamped icon overflows at 82px. Removing unrelated markup, transitions,
// desktop rules and the other grid tracks leaves these relations. Chromium:
// source 82x82 -> add core max-width:100% 69x82 -> max-width:none 82x82;
// all three ancestors remain 69px throughout. Widening tracks would change
// the authored layout rather than fix it. Keep mobile custom properties,
// multi-track placement, absolute positioning and the definite wrapper.
$compileResponsive = static function (string $extra = '', string $width = 'auto', string $height = '100%') : array {
    $css = '.block-layout{display:grid;width:360px;grid-template-columns:var(--grid-template-columns);grid-template-rows:82px}'
        . '.layout-element{display:grid;grid-column:var(--grid-column);grid-row:1}'
        . '.image-wrapper{position:relative;grid-area:1/1/-1/-1;width:100%;height:100%;max-height:82px}'
        . '.image-wrapper--mobile{position:relative;width:100%;height:100%}'
        . '.picture{position:absolute;top:0;left:0;width:var(--desktop-width);height:var(--desktop-height);aspect-ratio:1;object-fit:cover}'
        . '@media(max-width:920px){.block-layout{grid-template-columns:var(--m-grid-template-columns)}'
        . '.layout-element{grid-column:var(--m-grid-column)}'
        . '.picture{width:var(--mobile-width);height:var(--mobile-height)}}' . $extra;
    $html = '<!doctype html><html><head><style>' . $css . '</style></head><body>'
        . '<div class="block-layout" style="--grid-template-columns:repeat(16,1fr);--m-grid-template-columns:repeat(3,2%) repeat(12,1.6%) 74.8%">'
        . '<div class="layout-element" style="--grid-column:4/15;--m-grid-column:4/16"><div class="image-wrapper">'
        . '<div class="image-wrapper--mobile" style="--desktop-width:100%;--desktop-height:auto;--mobile-width:' . $width . ';--mobile-height:' . $height . '">'
        . '<img class="picture wp-image-123" src="icon.png" srcset="icon.png 82w, icon-large.png 164w" sizes="82px" width="82" height="82" alt="">'
        . '</div></div></div></div></body></html>';
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
};
$responsiveRules = static function (array $result) use ($assert): string {
    $markup = $result['serialized_blocks'];
    $assert(1 === preg_match('/<!-- wp:custom\/responsive-media (\{.*?\}) \/-->/s', $markup, $match), 'Real structure emits unwrapped responsive media.');
    $content = json_decode($match[1], true)['content'];
    $assert(! str_contains($content, '<figure'), 'No artificial image carrier.');
    preg_match_all('/be-inline-geometry-[0-9a-f]+/', $content, $classes);
    $css = implode('', array_column($result['assets'], 'content'));
    $rules = '';
    foreach (array_unique($classes[0]) as $class) {
        if (preg_match('/img\.' . $class . '\{[^}]*\}/', $css, $rule)) {
            $rules .= $rule[0];
        }
    }
    $assert(str_contains($css, 'grid-column:var(--m-grid-column)'), 'Keep mobile multi-track placement.');
    $assert(str_contains($css, 'grid-template-columns:var(--m-grid-template-columns)'), 'Keep mobile track sizing.');
    $assert(str_contains($css, 'position:absolute'), 'Keep absolute image positioning.');
    return $rules;
};
$overflow = $responsiveRules($compileResponsive());
$assert(str_contains($overflow, 'max-width:none!important'), 'Unwrapped image escapes the wp-image core clamp.');
$assert(! preg_match('/(?:\{|;)(?:width|height):/', $overflow), 'Overflow protection must not pin responsive axes.');
foreach (array(
    array('.picture{max-width:100%}', 'auto', '100%'),
    array('@media(max-width:920px){.picture{max-width:70px}}', 'auto', '100%'),
    array('', '100%', '100%'),
    array('', 'auto', 'auto'),
) as [$extra, $width, $height]) {
    $assert(! str_contains($responsiveRules($compileResponsive($extra, $width, $height)), 'max-width:none!important'), 'Do not override author clamps or non-derived widths on responsive media.');
}

echo "viewport height-derived image max-width ok\n";
