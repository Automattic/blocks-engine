<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

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
// Exercise the production multi-file path: author CSS is linked, and the
// percentage-sized SVG reaches a pixel box through two unsized wrappers.
$files = array();
foreach (array('index.html', '_assets/style.css') as $path) {
    $files[$path] = file_get_contents(dirname(__DIR__) . '/fixtures/svg-overflow-linked/' . $path);
}
$out = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$svg = $css = '';
foreach ($out['assets'] ?? array() as $asset) {
    if (($asset['kind'] ?? '') === 'svg') $svg .= $asset['content'];
    if (($asset['kind'] ?? '') === 'css') $css .= $asset['content'];
}
$check(preg_match('/viewBox="([^"]+)"/', $svg, $match) === 1, 'linked stylesheet overflow adds a viewBox: ' . $svg);
$bounds = array_map('floatval', explode(' ', $match[1]));
foreach (array(-25.48008, -13.92505, 248.444535, 65.48008) as $i => $expected) {
    $check(isset($bounds[$i]) && abs($bounds[$i] - $expected) < 0.0001, 'linked SVG painted bounds axis ' . $i);
}
$check(str_contains($css, 'width:248.4445px;height:65.4801px;left:-25.4801px;top:-13.9251px'), 'linked SVG painted size and offset');
$check(str_contains($css, 'width:197.48px;height:37.63px;overflow:visible'), 'linked SVG retains ancestor-sized layout box');
$check(str_contains(json_encode($out), 'wp:image') && str_contains(json_encode($out), 'Get in touch'), 'linked SVG remains editable beside its label');
// Resolve the served cascade, with engine support before the linked author
// sheet. Merely finding expanded dimensions in the carrier misses a later
// width:100%;height:100%;max-width:100% shrinking the painted image again.
$dom = new DOMDocument();
$dom->loadHTML($out['serialized_blocks']);
$figure = $dom->getElementsByTagName('figure')->item(0);
$image = $figure->getElementsByTagName('img')->item(0);
$cascade = new StaticCssCascade($dom, $css);
$box = $cascade->resolve($figure, array('position', 'width', 'height'), array());
$check($box['position'] === 'relative' && $box['width'] === '197.48px' && $box['height'] === '37.63px', 'figure retains original containing box');
// Check both core/image save forms: direct media and linked media.
foreach (array(false, true) as $linked) {
    if ($linked) {
        $anchor = $dom->createElement('a');
        $anchor->setAttribute('href', '/contact');
        $figure->replaceChild($anchor, $image);
        $anchor->appendChild($image);
    }
    $effective = (new StaticCssCascade($dom, $css))->resolve($image, array('position', 'width', 'height', 'max-width', 'max-height', 'left', 'top', 'margin'), array());
    $check(($effective['position'] ?? '') === 'absolute', 'painted image is positioned relative to figure');
    foreach (array('width' => 248.4445, 'height' => 65.4801, 'left' => -25.4801, 'top' => -13.9251) as $property => $expected) {
        $value = $effective[$property] ?? '';
        $check(str_ends_with($value, 'px') && abs((float) $value - $expected) < 0.0001, 'effective ' . ($linked ? 'linked ' : '') . 'image ' . $property . ': expected ' . $expected . 'px, got ' . $value);
    }
    $check($effective['max-width'] === 'none' && $effective['max-height'] === 'none' && $effective['margin'] === '0', 'painted image is not clamped or displaced by margins');
}
$files['_assets/style.css'] = str_replace('overflow:visible', 'overflow:hidden', $files['_assets/style.css']);
$hiddenOut = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
foreach ($hiddenOut['assets'] ?? array() as $asset) {
    if (($asset['kind'] ?? '') === 'svg') {
        $check(!str_contains($asset['content'], 'viewBox='), 'linked stylesheet hidden overflow is not expanded');
    }
}
echo "SVG visible overflow tests passed.\n";
