<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$source = '<html class="dark"><body><button class="theme-toggle-btn" aria-label="Toggle theme"><svg viewBox="0 0 24 24"><path d="M12 1v2"></path></svg><span class="theme-toggle-label">Light Mode</span></button></body></html>';
$css = '.theme-toggle-btn{display:flex}.dark .theme-toggle-btn{color:white}:root:not(.dark) .theme-toggle-btn{color:black}';
$result = (new HtmlTransformer())->transform($source, array('static_css' => $css))->toArray();
$block = $result['blocks'][0] ?? array();
$markup = (string) ($result['serialized_blocks'] ?? '');
$assert('custom/theme-toggle' === ($block['blockName'] ?? null), 'a dark-root toggle with identity, accessible name, and both CSS states uses the theme companion');
$assert('theme-toggle-btn' === ($block['attrs']['className'] ?? null) && 'theme-toggle-label' === ($block['attrs']['labelClassName'] ?? null) && str_contains((string) ($block['attrs']['icon'] ?? ''), '<svg') && str_contains((string) ($block['attrs']['icon'] ?? ''), '<path d="M12 1v2"></path>') && 'Light Mode' === ($block['attrs']['lightLabel'] ?? null) && 'Dark Mode' === ($block['attrs']['darkLabel'] ?? null), 'the authored button selector, icon, and bounded light/dark label pair remain editable and selector-addressable');
$assert(str_contains($markup, 'data-wp-interactive="blocks-engine/theme-toggle"') && str_contains($markup, 'data-wp-init="callbacks.init"') && str_contains($markup, 'data-wp-on--click="actions.toggle"'), 'saved markup declares deterministic Interactivity API initialization and action directives');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'theme-toggle serialization is editor-valid');
$assert('custom/theme-toggle' === ((new Runtime())->parseBlocks((new Runtime())->serializeBlocks(array($block)))[0]['blockName'] ?? null), 'the theme companion persists through parse and serialize');

$definition = $result['source_reports']['generated_blocks'][0] ?? array();
$view = (string) ($definition['view_js'] ?? '');
$editor = (string) ($definition['assets']['index.js'] ?? '');
$assert('custom/theme-toggle' === ($definition['block_json']['name'] ?? null) && 'file:./view.js' === ($definition['block_json']['viewScriptModule'] ?? null) && true === ($definition['block_json']['supports']['interactivity'] ?? null) && array('@wordpress/interactivity') === ($definition['script_dependencies']['view.js'] ?? null) && str_contains($editor, "'data-wp-init': 'callbacks.init'"), 'the generated companion declares its complete WordPress block name, registers its Interactivity API runtime asset, and keeps save/init parity with PHP markup');
$assert(str_contains($view, "store( 'blocks-engine/theme-toggle'") && str_contains($view, "classList.toggle( rootClass, dark )") && str_contains($view, 'localStorage.setItem') && str_contains($view, 'get label()') && str_contains($view, 'context.defaultTheme'), 'the runtime toggles the captured root class, persists it, and exposes the opposing theme label through Interactivity state');
$payload = (new CompanionPluginPayload())->fromBlockTypes(array(), array(), array(), array($definition));
$assert('theme-toggle' === ($payload['blocks'][0]['name'] ?? null) && array('@wordpress/interactivity') === ($payload['blocks'][0]['script_dependencies']['view.js'] ?? null), 'the companion plugin payload preserves runtime asset registration');

$missingCss = (new HtmlTransformer())->transform($source, array('static_css' => '.dark .theme-toggle-btn{color:white}'))->toArray();
$assert('custom/theme-toggle' !== ($missingCss['blocks'][0]['blockName'] ?? null), 'one theme CSS state is insufficient evidence for promotion');
$ambiguous = (new HtmlTransformer())->transform('<html class="dark"><body><button class="theme-toggle-btn" aria-label="Toggle theme"><svg></svg><span>Light Mode</span></button></body></html>', array('static_css' => $css))->toArray();
$assert('custom/theme-toggle' !== ($ambiguous['blocks'][0]['blockName'] ?? null), 'a selector-identical button without the authored label carrier remains a core button');
$ordinary = (new HtmlTransformer())->transform('<html class="dark"><body><button aria-label="Toggle theme">Light Mode</button></body></html>', array('static_css' => $css))->toArray();
$assert('custom/theme-toggle' !== ($ordinary['blocks'][0]['blockName'] ?? null), 'ordinary accessible buttons remain on core/button lowering');
$lightRootSource = str_replace(array('<html class="dark"', 'Light Mode'), array('<html class="light"', 'Dark Mode'), $source);
$lightRoot = (new HtmlTransformer())->transform($lightRootSource, array('static_css' => $css))->toArray();
$assert('light' === ($lightRoot['blocks'][0]['attrs']['defaultTheme'] ?? null) && 'Light Mode' === ($lightRoot['blocks'][0]['attrs']['lightLabel'] ?? null) && str_contains((string) ($lightRoot['serialized_blocks'] ?? ''), '>Dark Mode</span>'), 'the default theme and its current action label are read from the captured root class rather than forced dark');
$sanitizedSvg = (new HtmlTransformer())->transform(str_replace('<svg viewBox="0 0 24 24">', '<svg viewBox="0 0 24 24" onload="alert(1)"><script>alert(1)</script>', $source), array('static_css' => $css))->toArray();
$sanitizedIcon = (string) ($sanitizedSvg['blocks'][0]['attrs']['icon'] ?? '');
$assert('custom/theme-toggle' === ($sanitizedSvg['blocks'][0]['blockName'] ?? null) && !str_contains($sanitizedIcon, 'onload=') && !str_contains($sanitizedIcon, '<script') && str_contains($sanitizedIcon, '<path'), 'a drawable hostile SVG is sanitized through the shared SVG safety path before it becomes companion content');
$unsafeOnly = (new HtmlTransformer())->transform(str_replace('<svg viewBox="0 0 24 24"><path d="M12 1v2"></path></svg>', '<svg><script>alert(1)</script></svg>', $source), array('static_css' => $css))->toArray();
$assert('custom/theme-toggle' !== ($unsafeOnly['blocks'][0]['blockName'] ?? null), 'a non-drawable hostile SVG fails closed instead of creating a theme companion');

fwrite(STDOUT, "Theme toggle companion tests passed\n");
