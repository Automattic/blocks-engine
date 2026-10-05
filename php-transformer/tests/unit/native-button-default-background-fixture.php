<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = '<style>body{color:#f8fff9}.brand{display:flex;align-items:center;gap:8px}</style><a class="brand" href="/" role="button">Brand</a>';
$output = (new HtmlTransformer())->transform($source)->toArray();
$css = implode("\n", array_map(
	static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
	$output['assets'] ?? array()
));
echo json_encode(array(
	'source' => $source,
		'candidate' => $output['serialized_blocks'] ?? '',
	'css' => $css,
	'blocks' => $output['blocks'] ?? array(),
), JSON_THROW_ON_ERROR);
