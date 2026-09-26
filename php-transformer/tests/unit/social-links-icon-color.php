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
    $blocks = $result['blocks'] ?? array();
    $block = $blocks[0] ?? array();
    if ( 'core/group' === ($block['blockName'] ?? '') ) {
        $block = $block['innerBlocks'][0] ?? array();
    }

    return $block;
};

$assertColor = static function (array $block, string $serialized, string $color) use ($assert): void {
    $assert('core/social-links' === ($block['blockName'] ?? ''), 'social icon cluster lowers to core/social-links');
    $assert($color === ($block['attrs']['iconColorValue'] ?? ''), 'core/social-links carries the authored icon color as iconColorValue, got ' . json_encode($block['attrs']['iconColorValue'] ?? null));
    $assert($color === ($block['attrs']['customIconColor'] ?? ''), 'custom icon color is stored beside iconColorValue');
    $assert(str_contains((string) ($block['attrs']['className'] ?? ''), 'is-style-logos-only'), 'icon-only clusters keep the logos-only style');
    $assert(str_contains($serialized, '"iconColorValue":"' . $color . '"'), 'serialized social-links comment carries iconColorValue so core renders color:' . $color);
    $assert(str_contains($serialized, 'has-icon-color'), 'saved social-links markup includes the has-icon-color class Gutenberg emits for iconColorValue');
};

$styled = (new HtmlTransformer())->transform(
    '<style>.social-links a{color:#ffffff}</style>'
    . '<ul class="social-links"><li><a href="https://www.youtube.com/example" aria-label="YouTube"><svg width="16" height="16"></svg></a></li>'
    . '<li><a href="https://www.instagram.com/example" aria-label="Instagram"><svg width="16" height="16"></svg></a></li></ul>'
)->toArray();
$assertColor($social($styled), (string) ($styled['serialized_blocks'] ?? ''), '#ffffff');
$assert('pass' === ($styled['source_reports']['wp_block_validity']['status'] ?? ''), 'colored social-links remain WordPress-valid');

$image = imagecreatetruecolor(8, 8);
imagealphablending($image, false);
imagesavealpha($image, true);
$clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
$white = imagecolorallocatealpha($image, 255, 255, 255, 0);
imagefill($image, 0, 0, $clear);
imagefilledrectangle($image, 2, 2, 5, 5, $white);
ob_start();
imagepng($image);
$png = (string) ob_get_clean();
$dataUri = 'data:image/png;base64,' . base64_encode($png);
$glyphs = (new HtmlTransformer())->transform(
    '<ul class="social-links"><li><a href="https://www.youtube.com/example" aria-label="YouTube"><img src="' . $dataUri . '" width="8" height="8" alt=""></a></li>'
    . '<li><a href="https://www.instagram.com/example" aria-label="Instagram"><img src="' . $dataUri . '" width="8" height="8" alt=""></a></li></ul>'
)->toArray();
$assertColor($social($glyphs), (string) ($glyphs['serialized_blocks'] ?? ''), '#ffffff');

$avif = 'data:image/avif;base64,AAAAIGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZk1BMUIAAAD5bWV0YQAAAAAAAAAvaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAAAFBpY3R1cmVIYW5kbGVyAAAAAA5waXRtAAAAAAABAAAAHmlsb2MAAAAARAAAAQABAAAAAQAAASEAAAAdAAAAKGlpbmYAAAAAAAEAAAAaaW5mZQIAAAAAAQAAYXYwMUNvbG9yAAAAAGppcHJwAAAAS2lwY28AAAAUaXNwZQAAAAAAAAAIAAAACAAAABBwaXhpAAAAAAMICAgAAAAMYXYxQ4EADAAAAAATY29scm5jbHgAAgACAAIAAAAAF2lwbWEAAAAAAAAAAQABBAECgwQAAAAlbWRhdAoKAgAABUi/Gr5AEDIPEACXgBBAggAAEAD665Ot';
$avifGlyphs = (new HtmlTransformer())->transform(
    '<ul class="social-links"><li><a href="https://www.youtube.com/example" aria-label="YouTube"><img src="' . $avif . '" width="8" height="8" alt=""></a></li>'
    . '<li><a href="https://www.instagram.com/example" aria-label="Instagram"><img src="' . $avif . '" width="8" height="8" alt=""></a></li></ul>'
)->toArray();
$assertColor($social($avifGlyphs), (string) ($avifGlyphs['serialized_blocks'] ?? ''), '#ffffff');

$compiled = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        'website/index.html' => '<ul class="social-links"><li><a href="https://www.youtube.com/example" aria-label="YouTube"><img src="/icons/item.png" width="8" height="8" alt=""></a></li><li><a href="https://www.instagram.com/example" aria-label="Instagram"><img src="/icons/item.png" width="8" height="8" alt=""></a></li></ul>',
        'website/icons/item.png' => array( 'content_base64' => base64_encode($png), 'mime_type' => 'image/png' ),
    ),
))->toArray();
$compiledBlock = $social($compiled);
$assert('#ffffff' === ($compiledBlock['attrs']['iconColorValue'] ?? ''), 'a referenced monochrome glyph image carries its color onto core/social-links, got ' . json_encode($compiledBlock['attrs'] ?? null));

$iconReference = array(
    'schema' => 'blocks-engine/payload-reference/v1',
    'id' => 'icon',
    'bytes' => strlen($png),
    'sha256' => hash('sha256', $png),
);
$referencedHtml = '<ul class="social-links"><li><a href="https://www.youtube.com/example" aria-label="YouTube"><img src="/icons/item.png" width="8" height="8" alt=""></a></li><li><a href="https://www.instagram.com/example" aria-label="Instagram"><img src="/icons/item.png" width="8" height="8" alt=""></a></li></ul>';
$referencedArtifact = array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        array( 'path' => 'website/index.html', 'content' => $referencedHtml ),
        array( 'path' => 'website/icons/item.png', 'mime_type' => 'image/png', 'payload_reference' => $iconReference ),
    ),
);
$referencedReader = new class($png) implements PayloadReader {
    public function __construct(private string $png) {}
    public function read(array $reference): string { return $this->png; }
};
$referencedCompiler = new ArtifactCompiler();
$referencedShared = $referencedCompiler->prepareShared($referencedArtifact, $referencedReader);
$referencedPage = $referencedCompiler->preparePage($referencedArtifact, $referencedShared, 'website/index.html', $referencedReader);
$referencedReceipt = $referencedCompiler->compilePreparedPage($referencedShared, $referencedPage, $referencedReader);
$referenced = $referencedCompiler->compose($referencedShared, array( $referencedReceipt ), $referencedReader)->toArray();
$referencedBlock = $social($referenced);
$assert('#ffffff' === ($referencedBlock['attrs']['iconColorValue'] ?? ''), 'a payload-referenced monochrome glyph carries its color, got ' . json_encode($referencedBlock['attrs'] ?? null));

$mixed = (new HtmlTransformer())->transform(
    '<ul class="social-links"><li><a href="https://www.youtube.com/example" aria-label="YouTube"><svg width="16" height="16" fill="#ff0000"></svg></a></li>'
    . '<li><a href="https://www.instagram.com/example" aria-label="Instagram"><svg width="16" height="16" fill="#0000ff"></svg></a></li></ul>'
)->toArray();
$mixedBlock = $social($mixed);
$assert(! isset($mixedBlock['attrs']['iconColorValue']), 'icons that do not share one color keep core service colors');

$headerPart = static function (array $result): array {
    $parts = $result['source_reports']['wordpress_site_plan']['template_parts'] ?? array();
    foreach ( $parts as $part ) {
        if ( is_array($part) && 'header' === ($part['area'] ?? null) ) {
            return $part;
        }
    }

    return array();
};
$sharedHeader = static function (string $icons): string {
    return '<header class="site-header">' . $icons . '<nav><a href="/">Home</a><a href="/about">About</a></nav></header>';
};
$iconCluster = static function (string $src): string {
    return '<ul class="social-links"><li><a href="https://www.youtube.com/example" aria-label="YouTube"><img src="' . $src . '" width="8" height="8" alt=""></a></li>'
        . '<li><a href="https://www.instagram.com/example" aria-label="Instagram"><img src="' . $src . '" width="8" height="8" alt=""></a></li></ul>';
};
$page = static function (string $title, string $header): string {
    return '<!doctype html><html><head><title>' . $title . '</title></head><body>' . $header . '<main><h1>' . $title . '</h1></main></body></html>';
};
$assertPartColor = static function (array $result, string $message) use ($assert, $headerPart): void {
    $part = $headerPart($result);
    $markup = (string) ($part['canonical_block_markup'] ?? '');
    $assert('header' === ($part['slug'] ?? null), 'a repeated header extracts to one template part');
    $assert(str_contains($markup, '"iconColorValue":"#ffffff"'), $message . ', got ' . $markup);
};

$sharedIcons = $iconCluster('/media/item.png');
$sharedPages = array(
    'website/index.html' => $page('Home', $sharedHeader($sharedIcons)),
    'website/about/index.html' => $page('About', $sharedHeader($sharedIcons)),
    'website/shop/index.html' => $page('Shop', $sharedHeader($sharedIcons)),
);
$sharedEmbedded = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => $sharedPages + array(
        'website/media/item.png' => array( 'content_base64' => base64_encode($png), 'mime_type' => 'image/png' ),
    ),
))->toArray();
$assertPartColor($sharedEmbedded, 'a shared header template part carries the root-relative glyph color');

$placeholder = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
$placeholderHeader = $sharedHeader($iconCluster($placeholder));
$placeholderPage = static function (string $title) use ($placeholderHeader, $iconCluster): string {
    return '<!doctype html><html><head><title>' . $title . '</title></head><body>' . $placeholderHeader
        . '<main><h1>' . $title . '</h1>' . $iconCluster('/media/item.png') . '</main></body></html>';
};
$placeholderResult = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        'website/index.html' => $placeholderPage('Home'),
        'website/about/index.html' => $placeholderPage('About'),
        'website/shop/index.html' => $placeholderPage('Shop'),
        'website/media/item.png' => array( 'content_base64' => base64_encode($png), 'mime_type' => 'image/png' ),
    ),
))->toArray();
$assertPartColor($placeholderResult, 'a shared header of placeholder icons carries the same destination glyph color');

$referencedArtifact = array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        array( 'path' => 'website/index.html', 'content' => $sharedPages['website/index.html'] ),
        array( 'path' => 'website/about/index.html', 'content' => $sharedPages['website/about/index.html'] ),
        array( 'path' => 'website/shop/index.html', 'content' => $sharedPages['website/shop/index.html'] ),
        array( 'path' => 'website/media/item.png', 'mime_type' => 'image/png', 'payload_reference' => $iconReference ),
    ),
);
$shellCompiler = new ArtifactCompiler();
$shellShared = $shellCompiler->prepareShared($referencedArtifact, $referencedReader);
$shellPlans = array();
foreach ( array_keys($sharedPages) as $pageId ) {
    $shellPlans[] = $shellCompiler->compilePreparedPage($shellShared, $shellCompiler->preparePage($referencedArtifact, $shellShared, $pageId, $referencedReader), $referencedReader);
}
$shellReferenced = $shellCompiler->compose($shellShared, $shellPlans, $referencedReader)->toArray();
$assertPartColor($shellReferenced, 'a payload-referenced shared header carries the glyph color onto the template part');
$inlineShell = current(array_filter(
    $shellReferenced['source_reports']['compiled_site']['inline_shell_artifacts'] ?? array(),
    static fn (array $artifact): bool => 'header' === ($artifact['area'] ?? null)
));
$inlineMarkup = is_array($inlineShell) ? (string) ($inlineShell['template_part_block_markup'] ?? $inlineShell['block_markup'] ?? '') : '';
$assert(str_contains($inlineMarkup, '"iconColorValue":"#ffffff"'), 'isolated shared-shell compilation carries the referenced glyph color, got ' . $inlineMarkup);

echo "Social-links icon color tests passed\n";
