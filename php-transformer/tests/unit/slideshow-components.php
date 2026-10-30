<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
$files = require dirname(__DIR__) . '/fixtures/slideshow-components.php';
$compiler = new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler();
$artifact = array('entrypoint' => 'website/index.html', 'files' => $files);
$shared = $compiler->prepareShared($artifact);
$compiled = $compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared)))->toArray();
if (!str_contains(json_encode($compiled), 'authored-carousel')) throw new RuntimeException('Artifact compiler omitted slideshow');
$projection = (new CapturedDialogProjector())->project($files);
$result = (new HtmlTransformer())->transform($projection['files'][0]['content'])->toArray();
$markup = $result['serialized_blocks'];
if (1 !== substr_count($markup, '<!-- wp:custom/authored-carousel ') || 5 !== substr_count($markup, '<!-- wp:image ')
    || str_contains($markup, 'example.org') || str_contains($markup, '-319px') || !str_contains($markup, 'data-carousel-swipe="true"')
    || !str_contains($markup, '"initialSlide":2')) throw new RuntimeException('Slideshow frame/materialization contract failed: ' . $markup);
$report = json_decode($files[2]['content'], true);
$report['pages'][0]['components'][0]['status'] = 'partial';
$report['pages'][0]['components'][0]['slideshow']['frames'][1]['media'] = array();
$files[2]['content'] = json_encode($report);
$partial = (new CapturedDialogProjector())->project($files);
if (!str_contains(json_encode($partial['diagnostics']), 'slide-1')) throw new RuntimeException('Missing slot diagnostic absent');
echo "Slideshow components passed\n";
