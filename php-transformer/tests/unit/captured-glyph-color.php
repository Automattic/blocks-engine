<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$social = static function (array $result): array {
    $block = $result['blocks'][0] ?? array();
    if ( 'core/group' === ($block['blockName'] ?? '') ) {
        $block = $block['innerBlocks'][0] ?? array();
    }

    return $block;
};
$undecodable = static fn (array $result): array => array_values(array_filter(
    $result['diagnostics'] ?? array(),
    static fn (mixed $diagnostic): bool => is_array($diagnostic) && 'glyph_color_undecodable' === ($diagnostic['code'] ?? null)
));
$cluster = static function (string $src, string $glyphAttribute = ''): string {
    $attribute = '' === $glyphAttribute ? '' : ' data-dla-glyph-color="' . $glyphAttribute . '"';

    return '<ul class="social-links"><li><a href="https://www.youtube.com/example" aria-label="YouTube"><img src="' . $src . '"' . $attribute . ' width="8" height="8" alt=""></a></li>'
        . '<li><a href="https://www.instagram.com/example" aria-label="Instagram"><img src="' . $src . '"' . $attribute . ' width="8" height="8" alt=""></a></li></ul>';
};

// No GD build decodes this AVIF: the assertions below hold on every host.
$garbageAvif = "\x00\x00\x00\x20ftypavif\x00\x00\x00\x00avifmif1" . str_repeat("\x01", 48);
$garbageUri = 'data:image/avif;base64,' . base64_encode($garbageAvif);

$captured = (new HtmlTransformer())->transform($cluster($garbageUri, '#1877F2'))->toArray();
$capturedBlock = $social($captured);
$assert('core/social-links' === ($capturedBlock['blockName'] ?? ''), 'a captured glyph cluster lowers to core/social-links');
$assert('#1877f2' === ($capturedBlock['attrs']['iconColorValue'] ?? null), 'a captured glyph colour becomes the lowercase iconColorValue without decoding the AVIF, got ' . json_encode($capturedBlock['attrs'] ?? null));
$assert(array() === $undecodable($captured), 'a captured glyph colour records no glyph_color_undecodable, got ' . json_encode($undecodable($captured)));
$assert(! str_contains((string) json_encode($captured), 'data-dla-glyph-color'), 'the captured glyph colour attribute never reaches the result');

$absent = (new HtmlTransformer())->transform($cluster($garbageUri))->toArray();
$assert(! isset($social($absent)['attrs']['iconColorValue']), 'without the attribute an undecodable glyph keeps the default icon colour');
$assert(1 === count($undecodable($absent)), 'without the attribute an undecodable glyph still records glyph_color_undecodable');

foreach ( array( '1877f2', '#1877f', '#1877f2ff', 'rgb(24,119,242)', '#18 77f2', ' #1877f2' ) as $invalid ) {
    $fallback = (new HtmlTransformer())->transform($cluster($garbageUri, $invalid))->toArray();
    $assert(! isset($social($fallback)['attrs']['iconColorValue']), 'an invalid captured value ' . json_encode($invalid) . ' is treated as absent');
    $assert(1 === count($undecodable($fallback)), 'an invalid captured value ' . json_encode($invalid) . ' falls back to decoding');
    $assert(! str_contains((string) json_encode($fallback), 'data-dla-glyph-color'), 'an invalid captured value ' . json_encode($invalid) . ' is stripped too');
}

$image = imagecreatetruecolor(8, 8);
imagealphablending($image, false);
imagesavealpha($image, true);
imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
imagefilledrectangle($image, 2, 2, 5, 5, imagecolorallocatealpha($image, 255, 255, 255, 0));
ob_start();
imagepng($image);
$png = (string) ob_get_clean();
$pngUri = 'data:image/png;base64,' . base64_encode($png);
$decoded = (new HtmlTransformer())->transform($cluster($pngUri, 'not-a-colour'))->toArray();
$assert('#ffffff' === ($social($decoded)['attrs']['iconColorValue'] ?? null), 'an invalid captured value falls back to the decoded glyph colour');
$preferred = (new HtmlTransformer())->transform($cluster($pngUri, '#1877f2'))->toArray();
$assert('#1877f2' === ($social($preferred)['attrs']['iconColorValue'] ?? null), 'the captured rendered colour wins over the decoded image colour');

$svg = (new HtmlTransformer())->transform(
    '<ul class="social-links"><li><a href="https://www.youtube.com/example" aria-label="YouTube"><svg data-dla-glyph-color="#1877f2" width="16" height="16" fill="currentColor"><path d="M0 0h16v16z"/></svg></a></li>'
    . '<li><a href="https://www.instagram.com/example" aria-label="Instagram"><svg data-dla-glyph-color="#1877f2" width="16" height="16" fill="currentColor"><path d="M0 0h16v16z"/></svg></a></li></ul>'
)->toArray();
$assert('#1877f2' === ($social($svg)['attrs']['iconColorValue'] ?? null), 'a captured colour on an inline svg glyph becomes iconColorValue');

$background = (new HtmlTransformer())->transform(
    '<ul class="social-links"><li><a href="https://www.youtube.com/example" aria-label="YouTube"><span class="icon" data-dla-glyph-color="#1877f2"></span></a></li>'
    . '<li><a href="https://www.instagram.com/example" aria-label="Instagram"><span class="icon" data-dla-glyph-color="#1877f2"></span></a></li></ul>'
)->toArray();
$assert('#1877f2' === ($social($background)['attrs']['iconColorValue'] ?? null), 'a captured colour on a background-image glyph element becomes iconColorValue');

// Outside a social cluster the attribute is stripped from whatever markup the glyph lowers to.
foreach ( array(
    '<a href="/contact"><picture><source srcset="/i.avif" type="image/avif"><img src="/i.png" data-dla-glyph-color="#1877f2" width="20" height="20" alt="Contact"></picture></a>',
    '<button class="menu"><span class="icon" style="background-image:url(/i.png);width:16px;height:16px;display:inline-block" data-dla-glyph-color="#1877f2"></span>Menu</button>',
    '<a href="/next" class="cta"><svg data-dla-glyph-color="#1877f2" width="16" height="16" viewBox="0 0 16 16"><path d="M0 0h16v16z"/></svg> Next</a>',
    '<div onclick="go()"><a href="/x"><img src="/i.png" data-dla-glyph-color="#1877f2" alt=""></a><canvas></canvas></div>',
) as $html ) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $assert('' !== (string) ($result['serialized_blocks'] ?? ''), 'glyph markup still converts');
    $assert(! str_contains((string) json_encode($result), 'data-dla-glyph-color'), 'the captured glyph colour attribute does not leak from ' . $html);
}

// Artifact compile: a captured reference is never decoded, so no payload read and no finding.
$reference = array(
    'schema' => 'blocks-engine/payload-reference/v1',
    'id'     => 'icon',
    'bytes'  => strlen($garbageAvif),
    'sha256' => hash('sha256', $garbageAvif),
);
$compileReferenced = static function (string $html) use ($reference, $garbageAvif): array {
    $artifact = array(
        'entrypoint' => 'website/index.html',
        'files'      => array(
            array( 'path' => 'website/index.html', 'content' => $html ),
            array( 'path' => 'website/icons/item.avif', 'mime_type' => 'image/avif', 'payload_reference' => $reference ),
        ),
    );
    $reader = new class($garbageAvif) implements PayloadReader {
        public int $reads = 0;
        public function __construct(private string $bytes) {}
        public function read(array $reference): string
        {
            $this->reads++;
            return $this->bytes;
        }
    };
    $compiler = new ArtifactCompiler();
    $shared = $compiler->prepareShared($artifact, $reader);
    $receipt = $compiler->compilePreparedPage($shared, $compiler->preparePage($artifact, $shared, 'website/index.html', $reader), $reader);

    return array( $compiler->compose($shared, array( $receipt ), $reader)->toArray(), $reader->reads );
};
[ $compiled, $reads ] = $compileReferenced($cluster('/icons/item.avif', '#1877f2'));
$assert('#1877f2' === ($social($compiled)['attrs']['iconColorValue'] ?? null), 'a compiled captured AVIF glyph carries iconColorValue #1877f2, got ' . json_encode($social($compiled)['attrs'] ?? null));
$assert(array() === $undecodable($compiled), 'a compiled captured AVIF glyph records no glyph_color_undecodable');
$assert(0 === $reads, 'a glyph asset whose every reference is captured is never read for decoding, got ' . $reads . ' reads');
$assert(! str_contains((string) ($compiled['serialized_blocks'] ?? ''), 'data-dla-glyph-color'), 'compiled block markup carries no captured glyph colour attribute');
[ $uncaptured, $uncapturedReads ] = $compileReferenced($cluster('/icons/item.avif'));
$assert(1 === $uncapturedReads, 'an uncaptured glyph asset is still decoded');
$assert(! isset($social($uncaptured)['attrs']['iconColorValue']) && array() !== $undecodable($uncaptured), 'an uncaptured undecodable glyph keeps today\'s finding');
[ , $mixedReads ] = $compileReferenced($cluster('/icons/item.avif', '#1877f2') . '<p><img src="/icons/item.avif" width="8" height="8" alt=""></p>');
$assert(1 === $mixedReads, 'an asset with one uncaptured reference is still decoded');

// A shared header template part carries the captured colour.
$header = '<header class="site-header">' . $cluster('/media/item.avif', '#1877f2') . '<nav><a href="/">Home</a><a href="/about">About</a></nav></header>';
$page = static fn (string $title): string => '<!doctype html><html><head><title>' . $title . '</title></head><body>' . $header . '<main><h1>' . $title . '</h1></main></body></html>';
$site = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files'      => array(
        'website/index.html'       => $page('Home'),
        'website/about/index.html' => $page('About'),
        'website/shop/index.html'  => $page('Shop'),
        'website/media/item.avif'  => array( 'content_base64' => base64_encode($garbageAvif), 'mime_type' => 'image/avif' ),
    ),
))->toArray();
$headerPart = current(array_filter(
    $site['source_reports']['wordpress_site_plan']['template_parts'] ?? array(),
    static fn (mixed $part): bool => is_array($part) && 'header' === ($part['area'] ?? null)
));
$headerMarkup = is_array($headerPart) ? (string) ($headerPart['canonical_block_markup'] ?? '') : '';
$assert(str_contains($headerMarkup, '"iconColorValue":"#1877f2"'), 'a shared header template part carries the captured glyph colour, got ' . $headerMarkup);
$assert(! str_contains($headerMarkup, 'data-dla-glyph-color'), 'the header template part carries no captured glyph colour attribute');
$assert(array() === $undecodable($site), 'a captured shared header records no glyph_color_undecodable');

echo "Captured glyph color tests passed\n";
