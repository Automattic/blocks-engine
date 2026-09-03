<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\ThemeToggleBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$source = '<html class="dark"><body><button class="theme-toggle-btn" aria-label="Toggle theme"><svg class="lucide lucide-sun" data-lucide="sun" viewBox="0 0 24 24"><path d="M12 1v2"></path></svg><span class="theme-toggle-label">Light Mode</span></button></body></html>';
$css = '.theme-toggle-btn{display:flex}.theme-toggle-label{display:none}.dark .theme-toggle-btn{color:white}:root:not(.dark) .theme-toggle-btn{color:black}';
$result = (new HtmlTransformer())->transform($source, array('static_css' => $css))->toArray();
$block = $result['blocks'][0] ?? array();
$markup = (string) ($result['serialized_blocks'] ?? '');
$assert('blocks-engine/theme-toggle' === ($block['blockName'] ?? null), 'a dark-root toggle with identity, accessible name, and both CSS states uses the canonical Blocks Engine theme toggle');
$labelMarker = (string) ($block['attrs']['labelMarker'] ?? '');
$assert('theme-toggle-btn' === ($block['attrs']['className'] ?? null) && 'theme-toggle-label' === ($block['attrs']['labelClassName'] ?? null) && '' !== $labelMarker && str_contains((string) ($block['attrs']['lightIcon'] ?? ''), 'lucide-sun') && str_contains((string) ($block['attrs']['darkIcon'] ?? ''), 'M20.985 12.486') && 'Light Mode' === ($block['attrs']['lightLabel'] ?? null) && 'Dark Mode' === ($block['attrs']['darkLabel'] ?? null) && 'theme' === ($block['attrs']['storageKey'] ?? null), 'the authored button selector, projected label marker, safe action icons, bounded label pair, and source storage contract remain editable');
$assert(str_contains($markup, 'data-wp-interactive="blocks-engine/theme-toggle"') && str_contains($markup, 'data-wp-init="callbacks.init"') && str_contains($markup, 'data-wp-on--click="actions.toggle"') && str_contains($markup, 'data-blocks-engine-richtext-marker="' . $labelMarker . '"') && str_contains($markup, 'data-wp-bind--hidden="state.hideLightIcon"') && str_contains($markup, 'data-wp-bind--hidden="state.hideDarkIcon"'), 'saved markup declares deterministic Interactivity, preserves the projected hidden-label carrier, and renders both reactive icon states');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'theme-toggle serialization is editor-valid');
$assert('blocks-engine/theme-toggle' === ((new Runtime())->parseBlocks((new Runtime())->serializeBlocks(array($block)))[0]['blockName'] ?? null), 'the theme toggle persists through parse and serialize');

$definition = $result['source_reports']['generated_blocks'][0] ?? array();
$view = (string) ($definition['view_js'] ?? '');
$editor = (string) ($definition['assets']['index.js'] ?? '');
$assert('blocks-engine/theme-toggle' === ($definition['block_json']['name'] ?? null) && str_contains($editor, "registerBlockType( 'blocks-engine/theme-toggle'") && 'file:./view.js' === ($definition['block_json']['viewScriptModule'] ?? null) && true === ($definition['block_json']['supports']['interactivity'] ?? null) && array('@wordpress/interactivity') === ($definition['script_dependencies']['view.js'] ?? null) && str_contains($editor, "'data-wp-init': 'callbacks.init'"), 'the generated companion declares the canonical Blocks Engine block name, registers its Interactivity API runtime asset, and keeps save/init parity with PHP markup');
$assert(str_contains($view, "store( 'blocks-engine/theme-toggle'") && str_contains($view, "classList.toggle( rootClass, dark )") && str_contains($view, "root.style.colorScheme = dark ? 'dark' : 'light'") && str_contains($view, "context.storageKey || 'theme'") && str_contains($view, 'get label()') && str_contains($view, 'get hideLightIcon()') && str_contains($view, 'get hideDarkIcon()') && str_contains($view, 'context.defaultTheme'), 'the runtime toggles the captured root class and color scheme, persists through the configurable source-compatible key, and reactively swaps the label and icons');
$payload = (new CompanionPluginPayload())->fromBlockTypes(array(), array(), array(), array($definition));
$assert('theme-toggle' === ($payload['blocks'][0]['name'] ?? null) && array('@wordpress/interactivity') === ($payload['blocks'][0]['script_dependencies']['view.js'] ?? null), 'the companion plugin payload preserves runtime asset registration');

$missingCss = (new HtmlTransformer())->transform($source, array('static_css' => '.dark .theme-toggle-btn{color:white}'))->toArray();
$assert('blocks-engine/theme-toggle' !== ($missingCss['blocks'][0]['blockName'] ?? null), 'one theme CSS state is insufficient evidence for promotion');
$ambiguous = (new HtmlTransformer())->transform('<html class="dark"><body><button class="theme-toggle-btn" aria-label="Toggle theme"><svg class="lucide lucide-star" viewBox="0 0 24 24"><path d="M12 1v2"></path></svg><span class="theme-toggle-label">Light Mode</span></button></body></html>', array('static_css' => $css))->toArray();
$assert('blocks-engine/theme-toggle' !== ($ambiguous['blocks'][0]['blockName'] ?? null), 'a selector-identical button with an unrecognized single icon remains a core button rather than inventing a theme action');
$ordinary = (new HtmlTransformer())->transform('<html class="dark"><body><button aria-label="Toggle theme">Light Mode</button></body></html>', array('static_css' => $css))->toArray();
$assert('blocks-engine/theme-toggle' !== ($ordinary['blocks'][0]['blockName'] ?? null), 'ordinary accessible buttons remain on core/button lowering');
$lightRootSource = str_replace(array('<html class="dark"', 'Light Mode'), array('<html class="light"', 'Dark Mode'), $source);
$lightRoot = (new HtmlTransformer())->transform($lightRootSource, array('static_css' => $css))->toArray();
$assert('light' === ($lightRoot['blocks'][0]['attrs']['defaultTheme'] ?? null) && 'Light Mode' === ($lightRoot['blocks'][0]['attrs']['lightLabel'] ?? null) && str_contains((string) ($lightRoot['serialized_blocks'] ?? ''), '>Dark Mode</span>'), 'the default theme and its current action label are read from the captured root class rather than forced dark');
$sanitizedSvg = (new HtmlTransformer())->transform(str_replace('<svg class="lucide lucide-sun" data-lucide="sun" viewBox="0 0 24 24">', '<svg class="lucide lucide-sun" data-lucide="sun" viewBox="0 0 24 24" onload="alert(1)"><script>alert(1)</script>', $source), array('static_css' => $css))->toArray();
$sanitizedIcon = (string) ($sanitizedSvg['blocks'][0]['attrs']['lightIcon'] ?? '');
$assert('blocks-engine/theme-toggle' === ($sanitizedSvg['blocks'][0]['blockName'] ?? null) && !str_contains($sanitizedIcon, 'onload=') && !str_contains($sanitizedIcon, '<script') && str_contains($sanitizedIcon, '<path'), 'a drawable hostile source icon is sanitized through the shared SVG safety path before it becomes companion content');
$unsafeOnly = (new HtmlTransformer())->transform(str_replace('<svg class="lucide lucide-sun" data-lucide="sun" viewBox="0 0 24 24"><path d="M12 1v2"></path></svg>', '<svg class="lucide lucide-sun"><script>alert(1)</script></svg>', $source), array('static_css' => $css))->toArray();
$assert('blocks-engine/theme-toggle' !== ($unsafeOnly['blocks'][0]['blockName'] ?? null), 'a non-drawable hostile SVG fails closed instead of creating a theme companion');

$unsafeMarkup = (new ThemeToggleBlockGenerator())->markup(array('lightIcon' => '<svg><script>alert(1)</script></svg>', 'darkIcon' => '<svg onload="alert(1)"><path d="M1 1"></path></svg>', 'labelMarker' => 'bad\" marker'));
$assert(!str_contains($unsafeMarkup, '<script') && !str_contains($unsafeMarkup, 'onload=') && !str_contains($unsafeMarkup, 'bad\" marker'), 'PHP serialization rejects unsafe editable icon markup and malformed marker values');

fwrite(STDOUT, "Theme toggle companion tests passed\n");
