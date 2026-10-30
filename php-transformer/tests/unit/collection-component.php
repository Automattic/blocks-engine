<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedCollectionProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
$files = require __DIR__ . '/../fixtures/collection-component.php';
$assert = static function($ok, $message) { if (!$ok) throw new RuntimeException($message); };
$result = (new CapturedCollectionProjector())->project($files);
$assert($result['projected_count'] === 1, 'complete collection must materialize');
$dom = new DOMDocument(); @$dom->loadHTML($result['files'][0]['content']);
$payload = json_decode($dom->getElementsByTagName('section')->item(0)->getAttribute('data-blocks-engine-collection-root'), true);
$assert(count($payload['items']) === 16, 'all sixteen records');
foreach ($payload['items'] as $i => $item) {
    $assert($item['categories'] === array(0, 1, 2 + $i % 3), 'exact reset and category membership');
    $assert(str_ends_with($item['marker'], '-' . $i), 'first observed order');
}
$transformed = (new HtmlTransformer())->transform($result['files'][0]['content'])->toArray();
$assert(substr_count($transformed['serialized_blocks'], 'Editable record ') === 16, 'editable content union');
$assert(str_contains($transformed['serialized_blocks'], 'wp:custom/collection-filter'), 'shared editable runtime');
$assert(($transformed['source_reports']['wp_block_validity']['status'] ?? '') === 'pass', 'valid editor blocks');
$files[2]['content'] = str_replace('"coverage":"complete"', '"coverage":"partial"', $files[2]['content']);
$assert((new CapturedCollectionProjector())->project($files)['projected_count'] === 0, 'partial coverage cannot become live');
echo "Collection component passed\n";
