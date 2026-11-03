<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = file_get_contents(dirname(__DIR__) . '/fixtures/social-links-captured-svg.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert(!str_contains($result['serialized_blocks'], 'wp:social-link'), 'Service recognition must not replace authored SVG geometry.');
$assert(!str_contains(json_encode($result), 'data-blocks-engine-capture-glyph-color'), 'Capture evidence must not leak.');
$assert(0 === $result['metrics']['fallback_count'], 'Source icon links remain editable without HTML fallback.');
$assert('pass' === $result['source_reports']['wp_block_validity']['status'], 'Source icon links remain WordPress-valid.');
$glyphs = array_values(array_filter($result['assets'], static fn (array $asset): bool => 'image/svg+xml' === ($asset['mime_type'] ?? '')));
$assert(3 === count($glyphs), 'All three source glyphs survive.');
$sourceDom = new DOMDocument();
$sourceDom->loadHTML($source);
foreach ($glyphs as $index => $glyph) {
    $dom = new DOMDocument();
    $dom->loadXML($glyph['content']);
    $assert($sourceDom->getElementsByTagName('path')->item($index)->getAttribute('d') === $dom->getElementsByTagName('path')->item(0)->getAttribute('d'), 'Path geometry must be identical.');
    $assert(str_contains($glyph['content'], '#ffffff'), 'Isolated glyph artwork carries captured white paint.');
}
if (in_array('--json', $argv, true)) {
    echo json_encode(array('source' => $source, 'result' => $result), JSON_THROW_ON_ERROR);
    exit;
}
echo "Captured social SVG parity passed.\n";
