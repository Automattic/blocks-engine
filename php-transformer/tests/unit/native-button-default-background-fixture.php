<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = '<link rel="stylesheet" href="site.css"><div id="root"><section class="shell"><header><a href="#" role="button" style="display:flex;align-items:center;gap:8px">Brand</a><a href="#filled" role="button" style="display:flex;align-items:center;gap:8px;background-color:#c00">Filled</a><a href="#responsive" role="button" class="responsive" style="display:flex;align-items:center;gap:8px">Responsive</a></header><aside class="unsupported-shell">aside content</aside></section></div>';
$output = (new ArtifactCompiler())->compile(array(
	'entrypoint' => 'index.html',
	'files' => array(
		'index.html' => $source,
		'site.css' => '.responsive:hover{background-color:rgb(0,170,0)}@media(max-width:600px){.responsive{background-color:rgb(0,102,204)}}',
	),
))->toArray();
$sourceCss = '.responsive:hover{background-color:rgb(0,170,0)}@media(max-width:600px){.responsive{background-color:rgb(0,102,204)}}';
$css = implode("\n", array_map(
	static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
	$output['assets'] ?? array()
));
	echo json_encode(array(
	'source' => $source,
	'sourceCss' => $sourceCss,
	'candidate' => $output['serialized_blocks'] ?? '',
	'css' => $css,
	'blocks' => $output['blocks'] ?? array(),
), JSON_THROW_ON_ERROR);
