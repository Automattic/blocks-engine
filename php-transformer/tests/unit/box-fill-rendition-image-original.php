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
$image = static fn (string $src, string $srcset, string $class): string => '<section class="hero"><img src="' . $src . '"' . ('' === $srcset ? '' : ' srcset="' . $srcset . '"') . ' class="' . $class . '" alt="Spread"><h1 class="relative">Menu</h1></section>';
$style = '<style>.hero{position:relative;height:560px}.relative{position:relative}.absolute{position:absolute}.inset-0{inset:0}'
    . '.w-full{width:100%}.h-full{height:100%}.object-cover{object-fit:cover}.object-top{object-position:top}'
    . '.card{aspect-ratio:16/10}.fill{display:inline-block;position:relative;width:100%;height:100%}</style>';
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$images = static function (array $result): array {
    preg_match_all('/<img [^>]*>/', $result['source_reports']['compiled_site']['pages'][0]['block_markup'], $matches);
    return $matches[0];
};

// Unlocalized references carry the rendition in their own URL.
$html = '<!doctype html><html><head>' . $style . '</head><body><main>'
    . $image($fill('w_1440,h_560'), $fill('w_1440,h_560') . ' 1x, ' . $fill('w_2880,h_1120') . ' 2x', 'w-full h-full inset-0 absolute object-cover')
    // The crop is about the centre, so a focal point the author moved keeps it.
    . $image($fill('w_1440,h_560'), '', 'w-full h-full inset-0 absolute object-cover object-top')
    // Another alignment is a different picture, not the original.
    . $image($fill('w_1440,h_560', 'al_t'), '', 'w-full h-full inset-0 absolute object-cover')
    // An in-flow image with an auto height takes its box from the file.
    . $image($fill('w_1440,h_560'), '', 'w-full object-cover')
    . '</main></body></html>';
$remote = $images((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray());
$assert(4 === count($remote), 'Every source image remains a native image.');
$assert(str_contains($remote[0], 'src="' . $original . '"') && !str_contains($remote[0], '/v1/fill/'), 'A box-filling centre crop lowers the original it was cut from.');
$assert(str_contains($remote[1], 'src="' . $fill('w_1440,h_560') . '"'), 'A moved focal point keeps the captured crop.');
$assert(str_contains($remote[2], 'src="' . $fill('w_1440,h_560', 'al_t') . '"'), 'An off-centre crop keeps the captured rendition.');
$assert(str_contains($remote[3], 'src="' . $fill('w_1440,h_560') . '"'), 'An image sized by its file keeps the captured rendition.');

// A capture localizes every reference: the page names `/media/<file>` and only
// the receipt's asset records keep the source URL each file was fetched from.
$webp = base64_encode('RIFF....WEBPVP8 ');
$jpeg = base64_encode("\xFF\xD8\xFF\xE0JFIF");
$receipt = array(
    'schema' => 'blocks-engine-capture/capture-receipt/v1',
    'routes' => array(),
    'assets' => array(
        array('path' => 'website/media/abc_IMG_0601.JPG', 'source_url' => $original),
        array('path' => 'website/media/abc_IMG_0601.webp', 'source_url' => $fill('w_1440,h_560')),
        array('path' => 'website/media/abc_IMG_0601-2.webp', 'source_url' => $fill('w_2880,h_1120')),
        array('path' => 'website/media/abc_IMG_0601-3.webp', 'source_url' => $fill('w_1440,h_560', 'al_t')),
    ),
);
$localHtml = '<!doctype html><html><head>' . $style . '</head><body><main>'
    . $image('/media/abc_IMG_0601.webp', '/media/abc_IMG_0601.webp 1x, /media/abc_IMG_0601-2.webp 2x', 'w-full h-full inset-0 absolute object-cover')
    . $image('/media/abc_IMG_0601-3.webp', '', 'w-full h-full inset-0 absolute object-cover')
    . $image('/media/abc_IMG_0601.webp', '', 'w-full object-cover')
    // A box whose shape the author fixed takes the same centre crop at every
    // width, so the captured rendition already is that picture.
    . '<div class="card"><span class="fill"><img src="/media/abc_IMG_0601.webp" class="w-full h-full inset-0 absolute object-cover" alt="Card"></span></div>'
    . '</main></body></html>';
$local = $images((new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        'website/index.html' => $localHtml,
        'website/media/abc_IMG_0601.JPG' => array('content_base64' => $jpeg, 'mime_type' => 'image/jpeg'),
        'website/media/abc_IMG_0601.webp' => array('content_base64' => $webp, 'mime_type' => 'image/webp'),
        'website/media/abc_IMG_0601-2.webp' => array('content_base64' => $webp, 'mime_type' => 'image/webp'),
        'website/media/abc_IMG_0601-3.webp' => array('content_base64' => $webp, 'mime_type' => 'image/webp'),
        'capture-receipt.json' => json_encode($receipt, JSON_UNESCAPED_SLASHES),
    ),
))->toArray());
$assert(4 === count($local), 'Every captured image remains a native image.');
$assert(str_contains($local[0], 'abc_IMG_0601.JPG"') && !str_contains($local[0], '.webp'), 'A captured box-filling centre crop lowers the captured original, got ' . $local[0]);
$assert(str_contains($local[1], 'abc_IMG_0601-3.webp"'), 'A captured off-centre crop keeps its rendition, got ' . $local[1]);
$assert(str_contains($local[2], 'abc_IMG_0601.webp"') && !str_contains($local[2], '.JPG'), 'A captured image sized by its file keeps its rendition, got ' . $local[2]);
$assert(str_contains($local[3], 'abc_IMG_0601.webp"') && !str_contains($local[3], '.JPG'), 'A box of fixed shape keeps its rendition, got ' . $local[3]);

echo "Box fill rendition image original passed.\n";
