<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Native button font</title><style>
body{margin:0}
.card{font-family:Georgia,serif;font-size:18px;padding:24px}
.watch{background:#5d1ff2;color:#fff;border:0;padding:8px 16px;cursor:pointer}
</style></head><body><main><div class="card"><p>Latest episode</p><button class="watch" type="button">Watch now</button></div></main></body></html>
HTML;
if ( isset($argv[1]) ) {
    $source = str_replace('.watch{', '.watch{' . $argv[1] . ';', $source);
}
$output = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $source),
))->toArray();
echo json_encode(array(
    'source' => $source,
    'candidate' => $output['serialized_blocks'],
    'css' => implode("\n", array_map(
        static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
        $output['assets'] ?? array()
    )),
), JSON_THROW_ON_ERROR);
