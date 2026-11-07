<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Choice buttons</title><style>
body{font-family:Arial,sans-serif}.wrap{max-width:600px}
.choices{display:grid;gap:10px}
.choice{border:1px solid #ccc;background:white;padding:18px 20px;display:flex;align-items:center;justify-content:space-between;text-align:left;gap:15px;border-radius:4px}
.choice strong{display:block;font-family:Georgia,serif;font-size:25px;font-weight:600;color:#123;line-height:1.2}
.choice small{display:block;font-size:11px;color:#777;margin-top:4px}
</style></head><body><main><div class="wrap"><h1>Pick one</h1><div class="choices">
<button type="button" class="choice"><span><strong>Group session</strong><small>60 min</small></span><span>39 €</span></button>
<button type="button" class="choice"><span><strong>Private session</strong><small>60 min</small></span><span>95 €</span></button>
</div></div></main></body></html>
HTML;
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'kind' => 'html', 'content' => $source),
)))->toArray();
$blocks = (string) ($result['serialized_blocks'] ?? '');
$assets = array_values(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && 'editor' !== ($asset['stylesheet_target'] ?? '')));
// Importers can enqueue marker support after per-control CSS. Source-derived
// fill must survive that order too, not depend on which stylesheet loads last.
usort($assets, static fn (array $a, array $b): int => ('engine-support' === ($a['source'] ?? '')) <=> ('engine-support' === ($b['source'] ?? '')));
$css = implode("\n", array_column($assets, 'content'));
$css .= "\n" . implode("\n", (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EngineSupportCss())->beforeAuthorCss($blocks));

echo json_encode(array('source' => $source, 'candidate' => $blocks, 'css' => $css), JSON_THROW_ON_ERROR);
