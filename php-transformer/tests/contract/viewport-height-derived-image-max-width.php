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

echo "viewport height-derived image max-width ok\n";
