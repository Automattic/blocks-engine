<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$evidence = (string) getenv('THEME_ACCEPTANCE_EVIDENCE_DIR');
if ('' === $evidence || ! is_dir($evidence)) {
    throw new RuntimeException('THEME_ACCEPTANCE_EVIDENCE_DIR must point to an existing evidence directory.');
}
$source = '<!doctype html><html class="dark"><head><title>Theme selection acceptance</title></head><body><main><h1>Theme selection acceptance</h1><footer><div class="flex items-center gap-2 theme-choices" role="group" aria-label="Color theme" data-site-control="appearance"><button type="button" tabindex="0" class="theme-choice" aria-label="Light theme"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="lucide lucide-sun" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path></svg></button><button type="button" tabindex="0" class="theme-choice" aria-label="System theme"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="lucide lucide-monitor" aria-hidden="true"><rect width="20" height="14" x="2" y="3" rx="2"></rect><line x1="8" x2="16" y1="21" y2="21"></line></svg></button><button type="button" tabindex="0" class="theme-choice" aria-label="Dark theme"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="lucide lucide-moon" aria-hidden="true"><path d="M20.985 12.486a9 9 0 1 1-9.473-9.472"></path></svg></button></div></footer></main></body></html>';
$css = ':root{color-scheme:light}.dark{color-scheme:dark}.theme-choices{display:flex;gap:8px}.theme-choice{width:28px;height:28px}.dark .theme-choice{color:white}:root:not(.dark) .theme-choice{color:black}';
$runtime = 'const current=localStorage.getItem("theme")||"system"; const dark=window.matchMedia("(prefers-color-scheme: dark)").matches; document.documentElement.classList.toggle("dark",dark); localStorage.setItem("theme",current);';
$result = (new HtmlTransformer())->transform($source, array(
    'static_css' => $css,
    'runtime_projection_script_assets' => array(array('path' => 'js/theme.js', 'content' => $runtime)),
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
    'source_runtime_evidence' => $runtime,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
fwrite(STDOUT, "Built canonical theme selection fixture and disposable companion plugin\n");
