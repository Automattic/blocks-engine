<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$source = file_get_contents(dirname(__DIR__) . '/fixtures/social-links-raster-artwork.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = $result['serialized_blocks'];
$sourceDom = new DOMDocument();
$sourceDom->loadHTML($source);
$dom = new DOMDocument();
$dom->loadHTML($markup);
$assert(3 === $dom->getElementsByTagName('img')->length, 'All raster artwork survives instead of becoming service glyphs.');
foreach ($sourceDom->getElementsByTagName('a') as $index => $anchor) {
    $actual = $dom->getElementsByTagName('a')->item($index);
    $assert($actual instanceof DOMElement && $anchor->getAttribute('href') === $actual->getAttribute('href'), 'Artwork keeps its link destination.');
    $image = $actual->getElementsByTagName('img')->item(0);
    $expected = $anchor->getElementsByTagName('img')->item(0);
    $assert($image instanceof DOMElement && $expected->getAttribute('src') === $image->getAttribute('src'), 'Source image URLs survive.');
    $assert($expected->getAttribute('alt') === $image->getAttribute('alt'), 'Source alternative text survives.');
}
$css = implode('\n', array_column($result['assets'] ?? array(), 'content'));
$assert(str_contains($css, 'width:40px') && str_contains($css, 'height:40px'), 'Source selectors retain the exact 40px image dimensions.');
$assert(!str_contains($markup, 'wp:social-link'), 'Raster artwork must not become monochrome or generic chain glyphs.');
$assert(!str_contains($markup, 'wp:html') && 0 === $result['metrics']['fallback_count'], 'Linked artwork stays editable without Custom HTML.');
$assert('pass' === $result['source_reports']['wp_block_validity']['status'], 'Linked artwork remains WordPress-valid.');
