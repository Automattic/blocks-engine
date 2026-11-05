<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = file_get_contents(dirname(__DIR__) . '/fixtures/svg-overflow.html');
$check = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
};
$convert = static function (string $html): array {
    $out = (new HtmlTransformer())->transform($html)->toArray();
    $svg = $css = '';
    foreach ($out['assets'] ?? array() as $asset) {
        if (($asset['kind'] ?? '') === 'svg') $svg .= $asset['content'];
        if (($asset['kind'] ?? '') === 'css') $css .= $asset['content'];
    }
    return array($out['serialized_blocks'], $svg, $css);
};
[$blocks, $svg, $css] = $convert($source);
$check(str_contains($blocks, 'wp:image'), 'keeps editable core/image');
$check(str_contains($svg, 'viewBox="-32 -12 264 64"'), 'asset covers painted bounds: ' . $svg);
$check(str_contains($css, 'width:264px;height:64px;left:-32px;top:-12px'), 'paint offset and size: ' . $css);
$check(str_contains($css, 'width:200px;height:40px;overflow:visible'), 'original layout box retained');
[, $hidden] = $convert(str_replace('overflow:visible', 'overflow:hidden', $source));
$check(!str_contains($hidden, 'viewBox="-32'), 'hidden overflow unchanged');
[, $inside, $insideCss] = $convert(preg_replace('/d="M0,-12[^"]+"/', 'd="M0 0H200V40H0Z"', $source));
$check(!str_contains($inside, 'viewBox="-32') && !str_contains($insideCss, 'left:-32px'), 'inside artwork unchanged');
foreach (array('<rect x="-32" y="-12" width="264" height="64"/>', '<ellipse cx="100" cy="20" rx="132" ry="32"/>', '<polygon points="-32,-12 232,-12 232,52 -32,52"/>') as $shape) {
    [, $asset] = $convert(preg_replace('/<path[^>]+\/>/', $shape, $source));
    $check(str_contains($asset, 'viewBox="-32 -12 264 64"'), 'shape coordinate bounds');
}
[, $relative] = $convert(preg_replace('/d="M0,-12[^"]+"/', 'd="M0 -12h200c20 0 32 16 32 32s-12 32 -32 32H0c-20 0 -32 -16 -32 -32s12 -32 32 -32z"', $source));
$check(str_contains($relative, 'viewBox="-32 -12 264 64"'), 'relative and reflected path controls');
[, $scaled, $scaledCss] = $convert('<div><svg width="200" height="40" viewBox="0 0 100 20" overflow="visible"><rect x="-16" y="-6" width="132" height="32"/></svg></div>');
$check(str_contains($scaled, 'viewBox="-16 -6 132 32"'), 'existing viewBox user units');
$check(str_contains($scaledCss, 'width:264px;height:64px;left:-32px;top:-12px'), 'viewBox scales painted CSS geometry');
echo "SVG visible overflow tests passed.\n";
