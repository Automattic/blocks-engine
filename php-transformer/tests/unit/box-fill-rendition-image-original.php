<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A builder serves a box-filling image as `<original>/v1/fill/w_W,h_H,al_c,...`
// and re-requests the crop for the box the image has at each viewport. The
// captured 1440x560 strip is only the desktop crop: covering a 768x520 box with
// it zooms into the strip instead of the original. The image must lower the
// original the rendition was cut from, which covers the box at every width.
$original = 'https://media.base44.com/images/public/site/abc_IMG_0601.JPG';
$fill = static fn (string $box, string $align = 'al_c'): string => $original . '/v1/fill/' . $box . ',' . $align . ',q_90,usm_0.66_1.00_0.01,enc_webp,quality_auto/abc_IMG_0601.webp';
$image = static fn (string $src, string $srcset, string $class): string => '<section class="hero"><img src="' . $src . '" srcset="' . $srcset . '" class="' . $class . '" alt="Spread"><h1 class="relative">Menu</h1></section>';
$html = '<!doctype html><html><head><style>'
    . '.hero{position:relative;height:560px}.relative{position:relative}.absolute{position:absolute}.inset-0{inset:0}'
    . '.w-full{width:100%}.h-full{height:100%}.object-cover{object-fit:cover}.object-top{object-position:top}'
    . '</style></head><body><main>'
    . $image($fill('w_1440,h_560'), $fill('w_1440,h_560') . ' 1x, ' . $fill('w_2880,h_1120') . ' 2x', 'w-full h-full inset-0 absolute object-cover')
    // The crop is about the centre, so a focal point the author moved keeps it.
    . $image($fill('w_1440,h_560'), '', 'w-full h-full inset-0 absolute object-cover object-top')
    // Another alignment is a different picture, not the original.
    . $image($fill('w_1440,h_560', 'al_t'), '', 'w-full h-full inset-0 absolute object-cover')
    // An in-flow image with an auto height takes its box from the file.
    . $image($fill('w_1440,h_560'), '', 'w-full object-cover')
    . '</main></body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$markup = $result['source_reports']['compiled_site']['pages'][0]['block_markup'];
preg_match_all('/<img [^>]*>/', $markup, $images);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$assert(4 === count($images[0]), 'Every source image remains a native image.');
$assert(str_contains($images[0][0], 'src="' . $original . '"') && !str_contains($images[0][0], '/v1/fill/'), 'A box-filling centre crop lowers the original it was cut from.');
$assert(!str_contains($markup, 'w_2880,h_1120'), 'The fixed-aspect density family does not survive as the selection.');
$assert(str_contains($images[0][1], 'src="' . $fill('w_1440,h_560') . '"'), 'A moved focal point keeps the captured crop.');
$assert(str_contains($images[0][2], 'src="' . $fill('w_1440,h_560', 'al_t') . '"'), 'An off-centre crop keeps the captured rendition.');
$assert(str_contains($images[0][3], 'src="' . $fill('w_1440,h_560') . '"'), 'An image sized by its file keeps the captured rendition.');

echo "Box fill rendition image original passed.\n";
