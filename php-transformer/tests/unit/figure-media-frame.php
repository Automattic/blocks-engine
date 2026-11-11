<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(require dirname(__DIR__) . '/fixtures/figure-media-frame.php')->toArray();
$images = $result['blocks'][0]['innerBlocks'] ?? array();
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === $asset['kind']), 'content'));
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};
$assert(3 === count($images), 'one native image per figure, without wrapper blocks');
foreach ($images as $index => $image) {
    $assert('core/image' === $image['blockName'] && empty($image['innerBlocks']), 'native core/image leaf');
    $assert(! empty($image['attrs']['caption']), 'native caption attribute');
    $assert(! isset($image['attrs']['height']), 'folded frame owns height, not a native image attribute');
    $assert(1 === preg_match('/be-inline-geometry-[a-f0-9]+/', $image['attrs']['className'], $marker), 'existing geometry carrier');
    $assert(1 === preg_match('/\.' . $marker[0] . ' img\{([^}]+)\}/', $css, $rule), 'geometry targets the image, not the caption');
    foreach (array('aspect-ratio:4/3', 'height:auto', 'overflow:hidden') as $declaration) {
        $assert(str_contains($rule[1], $declaration), 'media frame survives: ' . $declaration);
    }
    if (2 === $index) {
        foreach (array('background:#fff', 'padding-left:12px', 'padding-top:12px', 'box-sizing:border-box') as $declaration) {
            $assert(str_contains($rule[1], $declaration), 'padded contain frame survives: ' . $declaration);
        }
        $assert('contain' === $image['attrs']['scale'], 'native contain paints inside the padded frame');
    }
}
$dom = new DOMDocument();
@$dom->loadHTML($result['serialized_blocks']);
foreach ($dom->getElementsByTagName('img') as $image) {
    $assert(! $image->hasAttribute('height'), 'no rendered height attribute competes with the frame');
    $assert(! preg_match('/(?:^|;)\s*height\s*:(?!\s*auto(?:\s*;|\s*$))/i', $image->getAttribute('style')), 'no fixed inline height competes with the frame');
}

$paddingFixture = require dirname(__DIR__) . '/fixtures/padding-ratio-media-frame.php';
$paddingResult = (new HtmlTransformer())->transform($paddingFixture)->toArray();
$paddingCss = implode("\n", array_column($paddingResult['assets'], 'content'));
$assert(str_contains($paddingCss, 'aspect-ratio:100 / 27.2109'), 'padding ratio uses the existing image geometry carrier');
$assert(str_contains($paddingCss, 'position:static !important'), 'folded absolute image returns to flow');
$assert(! str_contains($paddingResult['serialized_blocks'], 'height:100%'), 'folded ratio owns image height');
foreach (array(
    'padding-bottom: 0%',
    'padding-bottom: 120px',
    'padding-bottom: 27.2109%;position:static',
) as $replacement) {
    $guard = (new HtmlTransformer())->transform(str_replace('padding-bottom: 27.2109%', $replacement, $paddingFixture))->toArray();
    $assert(! str_contains(implode("\n", array_column($guard['assets'], 'content')), 'aspect-ratio:100 /'), 'only positive percentage positioned ratio frames are converted');
}

echo "Figure media frame passed.\n";
