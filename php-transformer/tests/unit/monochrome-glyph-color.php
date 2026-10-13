<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\MonochromeGlyphColor;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

// Detection is pure GD: the library carries no platform-specific decoder
// (no shelling out to sips or any other host tool).
$source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/HtmlToBlocks/Support/MonochromeGlyphColor.php');
foreach ( token_get_all($source) as $token ) {
    if ( is_array($token) && T_STRING === $token[0] ) {
        $assert(! in_array(strtolower($token[1]), array( 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'tempnam' ), true), 'MonochromeGlyphColor does not call ' . $token[1]);
    }
}
$assert(! str_contains($source, 'sips'), 'MonochromeGlyphColor has no macOS sips fallback');

$image = imagecreatetruecolor(8, 8);
imagealphablending($image, false);
imagesavealpha($image, true);
imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
imagefilledrectangle($image, 2, 2, 5, 5, imagecolorallocatealpha($image, 255, 255, 255, 0));
ob_start();
imagepng($image);
$png = (string) ob_get_clean();

$assert(array( 'color' => '#ffffff', 'undecodable' => false ) === MonochromeGlyphColor::inspect($png), 'a monochrome PNG glyph yields its color');
$gif = (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
$assert(array( 'color' => '', 'undecodable' => false ) === MonochromeGlyphColor::inspect($gif), 'a decodable image that is not a glyph is not undecodable');
$assert(array( 'color' => '', 'undecodable' => false ) === MonochromeGlyphColor::inspect(''), 'an empty payload is not undecodable');
$assert(array( 'color' => '', 'undecodable' => false ) === MonochromeGlyphColor::inspect(str_repeat('x', 65537)), 'an oversized payload is skipped, not undecodable');

// An AVIF container header the host cannot decode: garbage after the ftyp
// box is undecodable on every GD, with or without libavif.
$garbageAvif = "\x00\x00\x00\x20ftypavif\x00\x00\x00\x00avifmif1miaf" . str_repeat("\xff", 64);
$assert(array( 'color' => '', 'undecodable' => true ) === MonochromeGlyphColor::inspect($garbageAvif), 'an undecodable AVIF payload returns the default color and is flagged undecodable');
$assert(array( 'color' => '', 'undecodable' => true ) === MonochromeGlyphColor::inspect('not an image'), 'an undecodable non-image payload returns the default color and is flagged undecodable');

$avif = (string) base64_decode('AAAAIGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZk1BMUIAAAD5bWV0YQAAAAAAAAAvaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAAAFBpY3R1cmVIYW5kbGVyAAAAAA5waXRtAAAAAAABAAAAHmlsb2MAAAAARAAAAQABAAAAAQAAASEAAAAdAAAAKGlpbmYAAAAAAAEAAAAaaW5mZQIAAAAAAQAAYXYwMUNvbG9yAAAAAGppcHJwAAAAS2lwY28AAAAUaXNwZQAAAAAAAAAIAAAACAAAABBwaXhpAAAAAAMICAgAAAAMYXYxQ4EADAAAAAATY29scm5jbHgAAgACAAIAAAAAF2lwbWEAAAAAAAAAAQABBAECgwQAAAAlbWRhdAoKAgAABUi/Gr5AEDIPEACXgBBAggAAEAD665Ot', true);
$hostDecodesAvif = function_exists('imagetypes') && defined('IMG_AVIF') && 0 !== (imagetypes() & IMG_AVIF);
$assert(
    ( $hostDecodesAvif ? array( 'color' => '#ffffff', 'undecodable' => false ) : array( 'color' => '', 'undecodable' => true ) ) === MonochromeGlyphColor::inspect($avif),
    'a valid AVIF glyph follows host GD capability (avif=' . ( $hostDecodesAvif ? 'yes' : 'no' ) . ')'
);

$findings = static function (array $result): array {
    return array_values(array_filter(
        $result['diagnostics'] ?? array(),
        static fn (mixed $diagnostic): bool => is_array($diagnostic) && 'glyph_color_undecodable' === ($diagnostic['code'] ?? null)
    ));
};
$cluster = static function (string $src): string {
    return '<ul class="social-links"><li><a href="https://www.youtube.com/example" aria-label="YouTube"><img src="' . $src . '" width="8" height="8" alt=""></a></li>'
        . '<li><a href="https://www.instagram.com/example" aria-label="Instagram"><img src="' . $src . '" width="8" height="8" alt=""></a></li></ul>';
};

// Inline data URI: the loss is reported once, naming the format and page.
$inline = (new HtmlTransformer())->transform($cluster('data:image/avif;base64,' . base64_encode($garbageAvif)), array( 'source' => 'website/index.html' ))->toArray();
$inlineFindings = $findings($inline);
$assert(1 === count($inlineFindings), 'an undecodable inline glyph records one glyph_color_undecodable finding, got ' . json_encode($inlineFindings));
$assert('warning' === ($inlineFindings[0]['severity'] ?? null), 'glyph_color_undecodable is a warning');
$assert('image/avif' === ($inlineFindings[0]['mime_type'] ?? null), 'glyph_color_undecodable names the image format');
$assert('website/index.html' === ($inlineFindings[0]['source_path'] ?? null), 'glyph_color_undecodable names the source page');
$assert(! str_contains((string) json_encode($inlineFindings), base64_encode($garbageAvif)), 'glyph_color_undecodable does not embed the image payload');

// Materialized asset: the finding names the asset path.
$compiled = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        'website/index.html' => $cluster('/icons/item.avif'),
        'website/icons/item.avif' => array( 'content_base64' => base64_encode($garbageAvif), 'mime_type' => 'image/avif' ),
    ),
))->toArray();
$compiledFindings = $findings($compiled);
$assert(1 === count($compiledFindings), 'an undecodable glyph asset records one glyph_color_undecodable finding, got ' . json_encode($compiledFindings));
$assert('image/avif' === ($compiledFindings[0]['mime_type'] ?? null) && 'website/icons/item.avif' === ($compiledFindings[0]['src'] ?? null), 'the asset finding names the format and asset path, got ' . json_encode($compiledFindings));

// Decodable glyphs, monochrome or not, record nothing.
$assert(array() === $findings((new HtmlTransformer())->transform($cluster('data:image/png;base64,' . base64_encode($png)))->toArray()), 'a decodable monochrome glyph records no finding');
$assert(array() === $findings((new HtmlTransformer())->transform($cluster('data:image/gif;base64,' . base64_encode($gif)))->toArray()), 'a decodable non-monochrome glyph records no finding');

echo "Monochrome glyph color tests passed\n";
