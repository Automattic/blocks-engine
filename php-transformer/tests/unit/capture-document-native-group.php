<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$files = array();
foreach (array('index.html' => 'Home', 'about/index.html' => 'About') as $path => $title) {
    $html = '<html><body>';
    foreach (array('desktop', 'mobile') as $device) {
        $html .= '<div class="data-blocks-engine-capture-' . $device . '-document body-hook" data-blocks-engine-capture-device-document="' . $device . '" data-blocks-engine-capture-document-scope=""><header><a href="/">Logo</a></header><main><h1>' . $title . '</h1><p>Editable content.</p></main><footer><p>Copyright</p></footer></div>';
    }
    $files[$path] = $html . '</body></html>';
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$markup = implode("\n", array_column(array_merge($plan['pages'], $plan['templates'], $plan['template_parts']), 'canonical_block_markup'));
preg_match_all('/<!-- wp:[a-z0-9-]+\/layout-shell (\{.*?\}) -->/s', $markup, $shells);
foreach ($shells[1] as $json) {
    foreach (json_decode($json, true)['wrappers'] ?? array() as $wrapper) {
        if (array_key_exists('data-blocks-engine-capture-document-scope', $wrapper['attributes'] ?? array())) {
            throw new RuntimeException('Capture document roots must use native Groups after shared chrome extraction.');
        }
    }
}
if (!str_contains($markup, 'blocksEngineRuntimeAttributes') || !str_contains($markup, 'body-hook') || !str_contains($markup, 'data-blocks-engine-capture-device-document')) {
    throw new RuntimeException('Native document Groups must retain capture selector and visibility hooks.');
}
echo "capture-document-native-group: passed\n";
