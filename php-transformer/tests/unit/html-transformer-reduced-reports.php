<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$html = '<main><h1>Heading</h1><p>Content</p></main>';
$transformer = new HtmlTransformer();
$full = $transformer->transform($html)->toArray();
$reduced = $transformer->transform($html, array('reports' => 'reduced'))->toArray();

foreach (array('status', 'blocks', 'serialized_blocks', 'assets', 'fallbacks', 'provenance', 'metrics') as $key) {
    $assert(array_key_exists($key, $reduced), sprintf('Reduced reports result must contain %s.', $key));
}
$assert($full['serialized_blocks'] === $reduced['serialized_blocks'], 'Reduced reports must not change serialized block output.');
$assert($full['blocks'] === $reduced['blocks'], 'Reduced reports must not change block output.');
$assert(array('conversion_report') === array_keys($reduced['source_reports']), 'Reduced reports must retain only conversion_report.');
$assert(array() === $reduced['coverage'], 'Reduced reports must omit coverage evidence.');

fwrite(STDOUT, "HTML transformer reduced reports test passed.\n");
