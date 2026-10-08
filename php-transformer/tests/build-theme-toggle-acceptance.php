<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$evidence = (string) getenv('THEME_ACCEPTANCE_EVIDENCE_DIR');
if ('' === $evidence || ! is_dir($evidence)) {
    throw new RuntimeException('THEME_ACCEPTANCE_EVIDENCE_DIR must point to an existing evidence directory.');
}
$source = '<!doctype html><html class="dark"><head><title>Theme selection acceptance</title></head><body><main><h1>Theme selection acceptance</h1><footer><div class="flex items-center gap-2 theme-choices" role="group" aria-label="Color theme" data-site-control="appearance"><button type="button" tabindex="0" id="light-choice" class="theme-choice" aria-label="Light theme" aria-describedby="theme-help" data-choice="light" style="width:40px" onclick="unsafe()"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="lucide lucide-sun" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path></svg></button><button type="button" tabindex="0" class="theme-choice" aria-label="System theme"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="lucide lucide-monitor" aria-hidden="true"><rect width="20" height="14" x="2" y="3" rx="2"></rect><line x1="8" x2="16" y1="21" y2="21"></line></svg></button><button type="button" tabindex="0" class="theme-choice" aria-label="Dark theme"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="lucide lucide-moon" aria-hidden="true"><path d="M20.985 12.486a9 9 0 1 1-9.473-9.472"></path></svg></button></div><span id="theme-help">Select a color theme.</span></footer></main></body></html>';
$source = str_replace('style="width:40px"', 'style="width:40px;min-width:32px;background-color:#123456;border-radius:4px;padding:8px 16px"', $source);
$css = ':root{color-scheme:light}.dark{color-scheme:dark}.theme-choices{display:flex;gap:8px}.theme-choice{width:28px;height:28px}.dark .theme-choice{color:white}:root:not(.dark) .theme-choice{color:black}';
$capturePath = $evidence . '/theme-control-ownership.json';
$capture = is_readable($capturePath) ? json_decode((string) file_get_contents($capturePath), true) : null;
if (! is_array($capture) || 'blocks-engine/php-transformer/theme-preference-capture/v1' !== ($capture['schema'] ?? null)) throw new RuntimeException('A browser-produced theme ownership capture is required.');
$operators = array_column(is_array($capture['operators'] ?? null) ? $capture['operators'] : array(), null, 'source_path');
$runtimeAssets = is_array($capture['runtime_projection_script_assets'] ?? null) ? $capture['runtime_projection_script_assets'] : array();
$classSourcePath = 'theme-controls/index.html';
$dataSourcePath = 'theme-controls/data.html';
$classOperator = $operators[$classSourcePath] ?? array();
$dataOperator = $operators[$dataSourcePath] ?? array();
if ( ! is_array($classOperator) || ! is_array($dataOperator) ) throw new RuntimeException('Browser capture is missing a source-qualified theme contract.');
$result = (new HtmlTransformer())->transform($source, array(
    'source' => $classSourcePath,
    'static_css' => $css,
    'runtime_projection_script_assets' => $runtimeAssets,
    'theme_preference_ownership' => array($classOperator, $dataOperator),
))->toArray();
$findBlock = static function (array $blocks) use (&$findBlock): array {
    foreach ($blocks as $candidate) {
        if ('custom/theme-toggle' === ($candidate['blockName'] ?? '')) return $candidate;
        $nested = $findBlock($candidate['innerBlocks'] ?? array());
        if (array() !== $nested) return $nested;
    }
    return array();
};
$block = $findBlock($result['blocks'] ?? array());
if (array() === $block) throw new RuntimeException('The three-button source group was not promoted.');
if (count($block['attrs']['selectionButtons'] ?? array()) !== 3) {
    throw new RuntimeException('The promoted group does not contain three selection controls.');
}
$attributeSource = str_replace('<html class="dark">', '<html data-theme="dark">', $source);
$attributeCss = ':root:not([data-theme]){color-scheme:light;background:#fff}:root[data-theme="dark"]{color-scheme:dark;background:#111}';
$attributeResult = (new HtmlTransformer())->transform($attributeSource, array(
    'source' => $dataSourcePath,
    'static_css' => $attributeCss,
    'runtime_projection_script_assets' => $runtimeAssets,
    'theme_preference_ownership' => array($classOperator, $dataOperator),
))->toArray();
$attributeBlock = $findBlock($attributeResult['blocks'] ?? array());
if ('custom/theme-toggle' !== ($attributeBlock['blockName'] ?? '') || 'data-theme' !== ($attributeBlock['attrs']['rootAttribute'] ?? '') || empty($attributeBlock['attrs']['rootAttributeRemoved'])) {
    throw new RuntimeException('The source data-theme ownership contract was not derived.');
}

$plugin = $evidence . '/theme-toggle-companion';
if ( ! is_dir($plugin) && ! mkdir($plugin, 0775, true) && ! is_dir($plugin) ) {
    throw new RuntimeException('Unable to create temporary companion plugin directory.');
}
$definition = $result['source_reports']['generated_blocks'][0] ?? array();
$json = $definition['block_json'] ?? array();
$json['editorScript'] = 'file:./index.js';
$json['viewScriptModule'] = 'file:./view.js';
file_put_contents($plugin . '/block.json', json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
file_put_contents($plugin . '/index.js', (string) ($definition['assets']['index.js'] ?? ''));
file_put_contents($plugin . '/index.asset.php', '<?php return ' . var_export(array('dependencies' => $definition['script_dependencies']['index.js'] ?? array(), 'version' => '1'), true) . ";\n");
file_put_contents($plugin . '/view.js', (string) ($definition['view_js'] ?? ''));
file_put_contents($plugin . '/view.asset.php', '<?php return ' . var_export(array('dependencies' => $definition['script_dependencies']['view.js'] ?? array(), 'version' => '1'), true) . ";\n");
file_put_contents($plugin . '/theme-toggle-acceptance.php', "<?php\n/**\n * Plugin Name: Theme Toggle Acceptance\n */\nadd_action('init', static function () { register_block_type(__DIR__); });\n");
file_put_contents($evidence . '/source-and-page.json', json_encode(array(
    'content' => $result['serialized_blocks'] ?? '',
    'block_name' => $block['blockName'],
    'attributes' => $block['attrs'],
    'fallbacks' => $result['fallbacks'] ?? array(),
    'validity' => $result['source_reports']['wp_block_validity'] ?? array(),
    'source_group_markup' => $source,
    'static_css' => $css,
    'source_runtime_evidence' => $runtimeAssets,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
file_put_contents($evidence . '/root-attribute-page.json', json_encode(array(
    'content' => $attributeResult['serialized_blocks'] ?? '',
    'block_name' => $attributeBlock['blockName'],
    'attributes' => $attributeBlock['attrs'],
    'fallbacks' => $attributeResult['fallbacks'] ?? array(),
    'root_css' => $attributeCss,
    'runtime_evidence' => $runtimeAssets,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
fwrite(STDOUT, "Built canonical theme selection fixture and disposable companion plugin\n");
