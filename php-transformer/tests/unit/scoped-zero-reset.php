<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = file_get_contents(dirname(__DIR__) . '/fixtures/scoped-zero-reset.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = (string) $result['serialized_blocks'];
if (4 !== preg_match_all('/<li\b[^>]*>/', $markup, $items)) throw new RuntimeException('The reduced list must retain all four items.');
foreach (array_slice($items[0], 0, 3) as $item) {
    if (preg_match('/margin-(?:top|bottom):0/', $item)) throw new RuntimeException('Losing reset margins must not become inline item spacing: ' . $item);
}
if (!str_contains($markup, 'wp-block-button__link')) throw new RuntimeException('The scoped link must convert to core/button.');
$css = implode("\n", array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $result['assets'] ?? array()));
if (in_array('--json', $argv, true)) {
    echo json_encode(array('source' => $source, 'candidate' => $markup, 'css' => $css), JSON_THROW_ON_ERROR);
} else {
    fwrite(STDOUT, "scoped zero reset contract passed\n");
}
