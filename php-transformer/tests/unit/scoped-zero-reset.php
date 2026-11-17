<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = file_get_contents(dirname(__DIR__) . '/fixtures/scoped-zero-reset.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = (string) $result['serialized_blocks'];
if (4 !== preg_match_all('/<li\b[^>]*>/', $markup, $items)) throw new RuntimeException('The reduced list must retain all four items.');
foreach ($items[0] as $item) {
    if (str_contains($item, 'style=')) throw new RuntimeException('Shared zero resets must not become inline item spacing: ' . $item);
}
$css = implode("\n", array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $result['assets'] ?? array()));
if (in_array('--json', $argv, true)) {
    echo json_encode(array('source' => $source, 'candidate' => $markup, 'css' => $css), JSON_THROW_ON_ERROR);
} else {
    fwrite(STDOUT, "scoped zero reset contract passed\n");
}
