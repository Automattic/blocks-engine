<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$helper = trim(file_get_contents(dirname(__DIR__) . '/fixtures/capture-gallery-track-runtime.js'));
$items = '<li data-blocks-engine-capture-gallery-frame="0"><img src="https://example.test/one.jpg" alt="One"></li>'
    . '<li data-blocks-engine-capture-gallery-frame="1"><img src="https://example.test/two.jpg" alt="Two"></li>';
$html = '<html><head><script data-blocks-engine-capture-gallery-runtime>' . $helper . '</script></head><body>'
    . '<div id="pictures" class="gallery" data-blocks-engine-capture-gallery data-blocks-engine-capture-gallery-source="#pictures" data-blocks-engine-capture-gallery-capture-width="390">'
    . '<ul data-blocks-engine-capture-gallery-stage data-blocks-engine-capture-gallery-track="0.3" data-blocks-engine-capture-gallery-initial="0">' . $items . '</ul></div></body></html>';
$state = array('kind' => 'gallery', 'status' => 'no-dialog', 'gallery' => array('inline' => array(
    'selector' => '#pictures', 'viewport' => array('width' => 390, 'height' => 900),
    'coverage' => 'complete', 'restoration' => 'verified', 'initial' => 0, 'swipe' => true,
    'frames' => array(array('key' => 'https://example.test/one.jpg'), array('key' => 'https://example.test/two.jpg')),
)));
$files = array(
    'website/index.html' => $html,
    'interaction-states.json' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v1', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'states' => array($state))))),
    'capture-receipt.json' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html')))),
);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray();
$replaced = $result['source_reports']['native_runtime_replacements'] ?? array();
if (!in_array(hash('sha256', $helper), array_column($replaced, 'body_hash'), true)) throw new RuntimeException('Verified translated gallery runtime must be replaced by native carousel behavior.');
$markup = $result['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'] ?? '';
if (!str_contains($markup, '/authored-carousel') || str_contains($markup, '<!-- wp:html')) throw new RuntimeException('A verified touch gallery must remain editable without raw HTML.');
$runtime = new Runtime();
if ($markup !== $runtime->serializeBlocks($runtime->parseBlocks($markup))) throw new RuntimeException('Native gallery must survive save and reload.');
echo "Captured track runtime materialization regression passed\n";
