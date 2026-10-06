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
$assert('custom/theme-toggle' === ($block['blockName'] ?? null), 'a dark-root toggle with identity, accessible name, and both CSS states uses the canonical Blocks Engine theme toggle');
$labelMarker = (string) ($block['attrs']['labelMarker'] ?? '');
$assert('theme-toggle-btn' === ($block['attrs']['className'] ?? null) && 'theme-toggle-label' === ($block['attrs']['labelClassName'] ?? null) && '' !== $labelMarker && str_contains((string) ($block['attrs']['lightIcon'] ?? ''), 'lucide-sun') && str_contains((string) ($block['attrs']['darkIcon'] ?? ''), 'M20.985 12.486') && str_contains((string) ($block['attrs']['darkIcon'] ?? ''), 'width="18" height="18"') && str_contains((string) ($block['attrs']['darkIcon'] ?? ''), 'aria-hidden="true"') && 'Light Mode' === ($block['attrs']['lightLabel'] ?? null) && 'Dark Mode' === ($block['attrs']['darkLabel'] ?? null) && 'theme' === ($block['attrs']['storageKey'] ?? null), 'the authored button selector, projected label marker, equal-sized safe action icons, bounded label pair, and source storage contract remain editable');
$assert(str_contains($markup, 'data-wp-interactive="custom/theme-toggle"') && str_contains($markup, 'data-wp-init="callbacks.init"') && str_contains($markup, 'data-wp-on--click="actions.toggle"') && str_contains($markup, 'data-blocks-engine-richtext-marker="' . $labelMarker . '"') && str_contains($markup, 'data-wp-bind--hidden="state.hideLightIcon"') && str_contains($markup, 'data-wp-bind--hidden="state.hideDarkIcon"'), 'saved markup declares deterministic Interactivity, preserves the projected hidden-label carrier, and renders both reactive icon states');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'theme-toggle serialization is editor-valid');
$assert('custom/theme-toggle' === ((new Runtime())->parseBlocks((new Runtime())->serializeBlocks(array($block)))[0]['blockName'] ?? null), 'the theme toggle persists through parse and serialize');

$definition = $result['source_reports']['generated_blocks'][0] ?? array();
$view = (string) ($definition['view_js'] ?? '');
$editor = (string) ($definition['assets']['index.js'] ?? '');
$assert('custom/theme-toggle' === ($definition['block_json']['name'] ?? null) && str_contains($editor, "registerBlockType( 'custom/theme-toggle'") && 'file:./view.js' === ($definition['block_json']['viewScriptModule'] ?? null) && true === ($definition['block_json']['supports']['interactivity'] ?? null) && array('@wordpress/interactivity') === ($definition['script_dependencies']['view.js'] ?? null) && str_contains($editor, "'data-wp-init': 'callbacks.init'"), 'the generated companion declares the canonical Blocks Engine block name, registers its Interactivity API runtime asset, and keeps save/init parity with PHP markup');
$assert(str_contains($view, "store( 'custom/theme-toggle'") && str_contains($view, "root.classList.toggle( darkValue, dark )") && str_contains($view, "root.setAttribute( attribute, dark ? darkValue : lightValue )") && str_contains($view, "root.style.colorScheme = dark ? 'dark' : 'light'") && str_contains($view, "context.storageKey || 'theme'") && str_contains($view, 'get label()') && str_contains($view, 'get hideLightIcon()') && str_contains($view, 'get hideDarkIcon()') && str_contains($view, 'context.defaultTheme'), 'the runtime mutates the evidenced root contract, follows color scheme, persists the configured preference key, and reactively updates labels/icons');
$threeWay = (new ThemeToggleBlockGenerator())->markup(array('themeModes' => array('light', 'system', 'dark')), 'custom/theme-toggle');
$assert(str_contains($threeWay, '&quot;themeModes&quot;:[&quot;light&quot;,&quot;system&quot;,&quot;dark&quot;]') && str_contains($view, "window.matchMedia( '(prefers-color-scheme: dark)' )") && str_contains($view, "media.addEventListener( 'change', onSchemeChange )") && str_contains($view, "window.localStorage.setItem( context.storageKey || 'theme', next )"), 'the canonical control can cycle an explicit system preference, follow OS scheme changes, and persist all three modes');
$payload = (new CompanionPluginPayload())->fromBlockTypes(array(), array(), array(), array($definition));
$assert('theme-toggle' === ($payload['blocks'][0]['name'] ?? null) && array('@wordpress/interactivity') === ($payload['blocks'][0]['script_dependencies']['view.js'] ?? null), 'the companion plugin payload preserves runtime asset registration');

$missingCss = (new HtmlTransformer())->transform($source, array('static_css' => '.dark .theme-toggle-btn{color:white}'))->toArray();
$assert('custom/theme-toggle' !== ($missingCss['blocks'][0]['blockName'] ?? null), 'one theme CSS state is insufficient evidence for promotion');
$ambiguous = (new HtmlTransformer())->transform('<html class="dark"><body><button class="theme-toggle-btn" aria-label="Toggle theme"><svg class="lucide lucide-star" viewBox="0 0 24 24"><path d="M12 1v2"></path></svg><span class="theme-toggle-label">Light Mode</span></button></body></html>', array('static_css' => $css))->toArray();
$assert('custom/theme-toggle' !== ($ambiguous['blocks'][0]['blockName'] ?? null), 'a selector-identical button with an unrecognized single icon remains a core button rather than inventing a theme action');
$ordinary = (new HtmlTransformer())->transform('<html class="dark"><body><button aria-label="Toggle theme">Light Mode</button></body></html>', array('static_css' => $css))->toArray();
$assert('custom/theme-toggle' !== ($ordinary['blocks'][0]['blockName'] ?? null), 'ordinary accessible buttons remain on core/button lowering');
$lightRootSource = str_replace(array('<html class="dark"', 'Light Mode'), array('<html class="light"', 'Dark Mode'), $source);
$lightRoot = (new HtmlTransformer())->transform($lightRootSource, array('static_css' => $css))->toArray();
$assert('light' === ($lightRoot['blocks'][0]['attrs']['defaultTheme'] ?? null) && 'Light Mode' === ($lightRoot['blocks'][0]['attrs']['lightLabel'] ?? null) && str_contains((string) ($lightRoot['serialized_blocks'] ?? ''), '>Dark Mode</span>'), 'the default theme and its current action label are read from the captured root class rather than forced dark');
$sanitizedSvg = (new HtmlTransformer())->transform(str_replace('<svg class="lucide lucide-sun" data-lucide="sun" viewBox="0 0 24 24">', '<svg class="lucide lucide-sun" data-lucide="sun" viewBox="0 0 24 24" onload="alert(1)"><script>alert(1)</script>', $source), array('static_css' => $css))->toArray();
$sanitizedIcon = (string) ($sanitizedSvg['blocks'][0]['attrs']['lightIcon'] ?? '');
$assert('custom/theme-toggle' === ($sanitizedSvg['blocks'][0]['blockName'] ?? null) && !str_contains($sanitizedIcon, 'onload=') && !str_contains($sanitizedIcon, '<script') && str_contains($sanitizedIcon, '<path'), 'a drawable hostile source icon is sanitized through the shared SVG safety path before it becomes companion content');
$unsafeOnly = (new HtmlTransformer())->transform(str_replace('<svg class="lucide lucide-sun" data-lucide="sun" viewBox="0 0 24 24"><path d="M12 1v2"></path></svg>', '<svg class="lucide lucide-sun"><script>alert(1)</script></svg>', $source), array('static_css' => $css))->toArray();
$assert('custom/theme-toggle' !== ($unsafeOnly['blocks'][0]['blockName'] ?? null), 'a non-drawable hostile SVG fails closed instead of creating a theme companion');

$unsafeMarkup = (new ThemeToggleBlockGenerator())->markup(array('lightIcon' => '<svg><script>alert(1)</script></svg>', 'darkIcon' => '<svg onload="alert(1)"><path d="M1 1"></path></svg>', 'labelMarker' => 'bad\" marker'), 'custom/theme-toggle');
$assert(!str_contains($unsafeMarkup, '<script') && !str_contains($unsafeMarkup, 'onload=') && !str_contains($unsafeMarkup, 'bad\" marker'), 'PHP serialization rejects unsafe editable icon markup and malformed marker values');

$threeWayDefinition = (new ThemeToggleBlockGenerator())->definition('custom');
$threeWayMarkup = (new ThemeToggleBlockGenerator())->markup(array('themeModes' => array('light', 'system', 'dark')), 'custom/theme-toggle');
$assert(str_contains((string) ($threeWayDefinition['view_js'] ?? ''), "context.themeModes.includes( 'system' )")
    && str_contains((string) ($threeWayDefinition['view_js'] ?? ''), "window.matchMedia( '(prefers-color-scheme: dark)' )")
    && str_contains((string) ($threeWayDefinition['view_js'] ?? ''), "setItem( context.storageKey || 'theme', next )")
    && str_contains($threeWayMarkup, 'themeModes'), 'the existing companion runtime can cycle and persist an explicit light/system/dark preference while resolving system through prefers-color-scheme');

$groupSource = '<html class="dark"><body><footer><div class="theme-choices layout-row" style="display:flex;gap:8px" role="group" aria-label="Color theme" data-site-control="appearance"><button type="button" id="light-choice" class="theme-choice" aria-label="Light theme" aria-describedby="theme-help" data-choice="light" style="width:40px;min-width:32px;background-color:#123456;border-radius:4px;padding:8px 16px" onclick="unsafe()"><svg class="lucide lucide-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"></circle></svg></button><button type="button" class="theme-choice" aria-label="System theme"><svg class="lucide lucide-monitor" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14"></rect></svg></button><button type="button" class="theme-choice" aria-label="Dark theme"><svg class="lucide lucide-moon" viewBox="0 0 24 24"><path d="M20 12a8 8 0 1 1-8-8"></path></svg></button></div></footer><span id="theme-help">Select a color theme.</span></body></html>';
$groupCss = '.dark .theme-choices .theme-choice{color:#fff}:root:not(.dark) .theme-choices .theme-choice{color:#111}';
$groupRuntime = 'const labels=["Light theme","System theme","Dark theme"];const provider=({storageKey:key="theme"})=>{const root=document.documentElement;const read=(arg,fallback)=>localStorage.getItem(arg)||fallback;let preference=read(key,"system");const apply=value=>{root.classList.remove(...["dark"]);root.classList.add(value)};const select=value=>{apply(value);localStorage.setItem(key,value)};window.matchMedia("(prefers-color-scheme: dark)")};';
$makeOwnership = static function (string $sourcePath, string $runtimePath, string $runtime, string $storageKey = 'theme', string $rootAttribute = 'class', string $darkValue = 'dark', string $lightValue = '', string $lightOperation = 'remove-theme-class', array $labels = array('Light theme', 'System theme', 'Dark theme')): array {
    return array(
        'schema' => 'blocks-engine/php-transformer/theme-preference-ownership/v1',
        'source_path' => $sourcePath,
        'group_selector' => '.theme-choices',
        'runtime_script_path' => $runtimePath,
        'runtime_script_sha256' => hash('sha256', $runtime),
        'storage_key' => $storageKey,
        'system_query' => '(prefers-color-scheme: dark)',
        'root' => array('selector' => 'html', 'attribute' => $rootAttribute, 'dark_value' => $darkValue, 'light_value' => $lightValue, 'light_operation' => $lightOperation),
        'controls' => array(
            array('mode' => 'light', 'accessible_name' => $labels[0], 'icon' => 'sun'),
            array('mode' => 'system', 'accessible_name' => $labels[1], 'icon' => 'monitor'),
            array('mode' => 'dark', 'accessible_name' => $labels[2], 'icon' => 'moon'),
        ),
        'observed_transitions' => array(
            array('mode' => 'light', 'storage_value' => 'light', 'resolved' => 'light'),
            array('mode' => 'dark', 'storage_value' => 'dark', 'resolved' => 'dark'),
            array('mode' => 'system', 'storage_value' => 'system', 'os_scheme' => 'dark', 'resolved' => 'dark'),
            array('mode' => 'system', 'storage_value' => 'system', 'os_scheme' => 'light', 'resolved' => 'light'),
        ),
    );
};
$groupOperator = $makeOwnership('theme-controls/index.html', 'js/theme.js', $groupRuntime);
$groupTransformOptions = array('source' => 'theme-controls/index.html', 'static_css' => $groupCss, 'runtime_projection_script_assets' => array(array('path' => 'js/theme.js', 'content' => $groupRuntime)), 'theme_preference_ownership' => array($groupOperator));
$groupResult = (new HtmlTransformer())->transform($groupSource, $groupTransformOptions)->toArray();
$findThemeBlock = static function (array $blocks) use (&$findThemeBlock): array {
    foreach ($blocks as $candidate) {
        if ('custom/theme-toggle' === ($candidate['blockName'] ?? '')) return $candidate;
        $nested = $findThemeBlock($candidate['innerBlocks'] ?? array());
        if (array() !== $nested) return $nested;
    }
    return array();
};
$groupBlock = $findThemeBlock($groupResult['blocks'] ?? array());
$groupAttrs = $groupBlock['attrs'] ?? array();
$groupMarkup = (string) ($groupResult['serialized_blocks'] ?? '');
$assert('custom/theme-toggle' === ($groupBlock['blockName'] ?? null)
    && 3 === count($groupAttrs['selectionButtons'] ?? array())
    && array('light', 'system', 'dark') === ($groupAttrs['themeModes'] ?? array())
    && 'system' === ($groupAttrs['selectedMode'] ?? '')
    && str_starts_with((string) ($groupAttrs['groupClassName'] ?? ''), 'theme-choices layout-row')
    && str_contains($groupMarkup, 'style="gap:8px;display:flex"')
    && 'group' === ($groupAttrs['groupAttributes']['role'] ?? '')
    && 'Color theme' === ($groupAttrs['groupAttributes']['aria-label'] ?? '')
    && 'appearance' === ($groupAttrs['groupAttributes']['data-site-control'] ?? '')
    && 'light-choice' === ($groupAttrs['selectionButtons'][0]['attributes']['id'] ?? '')
    && 'theme-help' === ($groupAttrs['selectionButtons'][0]['attributes']['aria-describedby'] ?? '')
    && 'light' === ($groupAttrs['selectionButtons'][0]['attributes']['data-choice'] ?? '')
    && str_contains($groupMarkup, 'width:40px')
    && str_contains($groupMarkup, 'min-width:32px')
    && str_contains($groupMarkup, 'background-color:#123456')
    && str_contains($groupMarkup, 'border-radius:4px')
    && str_contains($groupMarkup, 'padding-top:8px')
    && str_contains($groupMarkup, 'padding-right:16px')
    && ! str_contains($groupMarkup, 'onclick=')
    && str_contains($groupMarkup, 'aria-label="System theme"')
    && str_contains($groupMarkup, 'lucide-monitor'), 'corroborated icon-only source groups promote once onto the canonical block while preserving the authored wrapper, names, icons, and order');
$groupDefinition = $groupResult['source_reports']['generated_blocks'][0] ?? array();
$groupEditor = (string) ($groupDefinition['assets']['index.js'] ?? '');
$groupFirstButtonStyle = (string) json_encode($groupBlock['attrs']['selectionButtons'][0]['style'] ?? array(), JSON_UNESCAPED_SLASHES);
$assert(str_contains($groupMarkup, 'data-wp-bind--aria-pressed="state.selected"')
    && str_contains($groupMarkup, 'data-wp-on--click="actions.select"')
    && str_contains($groupEditor, 'attrs.selectionButtons.map')
    && str_contains((string) ($groupDefinition['view_js'] ?? ''), "preference: 'dark'")
    && str_contains((string) ($groupDefinition['view_js'] ?? ''), "const { state: themeState } = store( 'custom/theme-toggle'") , 'the canonical saved/editable selection group exposes reactive selected state and direct per-mode actions');
$assert(str_contains($groupMarkup, 'width:40px')
    && str_contains($groupMarkup, 'min-width:32px')
    && str_contains($groupMarkup, 'background-color:#123456')
    && str_contains($groupMarkup, 'border-radius:4px')
    && str_contains($groupMarkup, 'padding-top:8px')
    && str_contains($groupMarkup, 'padding-right:16px')
    && str_contains($groupEditor, "name.replace( /-([a-z])/g")
    && str_contains($groupEditor, 'clean[ key ] = value')
    && str_contains($groupFirstButtonStyle, 'min-width')
    && str_contains($groupFirstButtonStyle, 'background-color')
    && str_contains($groupFirstButtonStyle, 'border-radius'), 'PHP save markup and the WordPress editor map all captured button CSS declarations to their matching camel-case React style keys');
$unconfirmedGroup = (new HtmlTransformer())->transform($groupSource, array('static_css' => '.dark .theme-choices .theme-choice{color:#fff}:root:not(.dark) .theme-choices .theme-choice{color:#111}'))->toArray();
$assert(array() === $findThemeBlock($unconfirmedGroup['blocks'] ?? array()), 'theme-shaped buttons without runtime corroboration remain ordinary controls');
$ambiguousGroup = (new HtmlTransformer())->transform(str_replace('lucide-monitor', 'lucide-star', $groupSource), $groupTransformOptions)->toArray();
$assert(array() === $findThemeBlock($ambiguousGroup['blocks'] ?? array()), 'a three-button group with one semantically ambiguous icon is not guessed to be a theme selector even when labels and runtime resemble one');
$disconnectedRuntime = 'const labels=["Light theme","System theme","Dark theme"];function cart(){localStorage.getItem("cart");localStorage.setItem("cart","x")}function os(){window.matchMedia("(prefers-color-scheme: dark)")}function unrelated(){document.documentElement.classList.toggle("dark")}';
$disconnected = (new HtmlTransformer())->transform($groupSource, array('static_css' => $groupCss, 'runtime_projection_script_assets' => array(array('path' => 'js/unrelated.js', 'content' => $disconnectedRuntime))))->toArray();
$assert(array() === $findThemeBlock($disconnected['blocks'] ?? array()), 'unrelated cart storage, theme text, OS query, and root class mutation do not establish a linked theme preference contract');
$compoundRootRuntime = 'const labels=["Light theme","System theme","Dark theme"];const drawerClass="drawer-open";document.documentElement.classList.toggle(drawerClass);function cart(){localStorage.getItem("cart");localStorage.setItem("cart","x")}function os(){window.matchMedia("(prefers-color-scheme: dark)")}';
$compoundRoot = (new HtmlTransformer())->transform($groupSource, array('static_css' => $groupCss, 'runtime_projection_script_assets' => array(array('path' => 'js/unrelated.js', 'content' => $compoundRootRuntime))))->toArray();
$assert(array() === $findThemeBlock($compoundRoot['blocks'] ?? array()), 'a root mutation for a non-theme class and cart storage cannot borrow a separate dark CSS state');
$semanticNamesSource = str_replace(array('Light theme', 'System theme', 'Dark theme'), array('Light', 'System mode', 'Dark'), $groupSource);
$semanticNamesRuntime = str_replace(array('Light theme', 'System theme', 'Dark theme'), array('Light', 'System mode', 'Dark'), $groupRuntime);
$semanticNames = (new HtmlTransformer())->transform($semanticNamesSource, array_replace($groupTransformOptions, array('runtime_projection_script_assets' => array(array('path' => 'js/theme.js', 'content' => $semanticNamesRuntime)), 'theme_preference_ownership' => array($makeOwnership('theme-controls/index.html', 'js/theme.js', $semanticNamesRuntime, labels: array('Light', 'System mode', 'Dark'))))))->toArray();
$assert(array('Light', 'System mode', 'Dark') === array_column($semanticNames['blocks'][0]['attrs']['selectionButtons'] ?? array(), 'ariaLabel'), 'semantically equivalent evidenced accessible names remain authored and editable');
$sourceSelected = (new HtmlTransformer())->transform(str_replace('aria-label="Dark theme"', 'aria-label="Dark theme" aria-pressed="true"', $groupSource), $groupTransformOptions)->toArray();
$sourceSelectedBlock = $findThemeBlock($sourceSelected['blocks'] ?? array());
$assert('dark' === ($sourceSelectedBlock['attrs']['selectedMode'] ?? null)
    && true === ($sourceSelectedBlock['attrs']['selectionButtons'][2]['selected'] ?? false), 'an explicit source selected-state marker takes precedence over inferred system default');
$rootAttributeSource = str_replace('<html class="dark">', '<html data-theme="dark">', $groupSource);
$rootAttributeCss = ':root:not([data-theme]){color-scheme:light;background:#fff}:root[data-theme="dark"]{color-scheme:dark;background:#111}';
$rootAttributeRuntime = 'const labels=["Light theme","System theme","Dark theme"];const root=document.documentElement;const storageKey="appearance";const value=localStorage.getItem(storageKey);root.setAttribute("data-theme","dark");root.removeAttribute("data-theme");localStorage.setItem(storageKey,value);window.matchMedia("(prefers-color-scheme: dark)");';
$rootAttributeSourcePath = 'theme-controls/data.html';
$rootAttributeRuntimePath = 'js/data-theme.js';
$rootAttributeResult = (new HtmlTransformer())->transform($rootAttributeSource, array('source' => $rootAttributeSourcePath, 'static_css' => $rootAttributeCss, 'runtime_projection_script_assets' => array(array('path' => $rootAttributeRuntimePath, 'content' => $rootAttributeRuntime)), 'theme_preference_ownership' => array($makeOwnership($rootAttributeSourcePath, $rootAttributeRuntimePath, $rootAttributeRuntime, 'appearance', 'data-theme', 'dark', '', 'remove-attribute'))))->toArray();
$rootAttributeBlock = $findThemeBlock($rootAttributeResult['blocks'] ?? array());
$assert('data-theme' === ($rootAttributeBlock['attrs']['rootAttribute'] ?? null)
    && 'dark' === ($rootAttributeBlock['attrs']['darkValue'] ?? null)
    && '' === ($rootAttributeBlock['attrs']['lightValue'] ?? null)
    && true === ($rootAttributeBlock['attrs']['rootAttributeRemoved'] ?? false)
    && 'appearance' === ($rootAttributeBlock['attrs']['storageKey'] ?? null), 'the root attribute and storage key derive from the owned runtime/CSS contract instead of fixed defaults');
$assert(str_contains((string) ($definition['view_js'] ?? ''), "'undefined' === typeof context.lightValue ? 'light' : context.lightValue")
    && str_contains((string) ($rootAttributeResult['source_reports']['generated_blocks'][0]['view_js'] ?? ''), 'context.rootAttributeRemoved && ! lightValue'), 'an authored empty light value remains distinct from a missing value so removeAttribute contracts survive frontend runtime initialization');
$formSemantics = (new HtmlTransformer())->transform(str_replace('type="button" id="light-choice"', 'type="submit" id="light-choice"', $groupSource), $groupTransformOptions)->toArray();
$assert(array() === $findThemeBlock($formSemantics['blocks'] ?? array()), 'a source submit button is not consumed by the theme-control projection');

$consumerNamespace = (new HtmlTransformer())->transform($source, array('static_css' => $css, 'generated_block_namespace' => 'acme-site'))->toArray();
$consumerDefinition = $consumerNamespace['source_reports']['generated_blocks'][0] ?? array();
$assert('acme-site/theme-toggle' === ($consumerNamespace['blocks'][0]['blockName'] ?? null)
    && 'acme-site/theme-toggle' === ($consumerDefinition['block_json']['name'] ?? null)
    && str_contains((string) ($consumerNamespace['serialized_blocks'] ?? ''), 'data-wp-interactive="acme-site/theme-toggle"')
    && str_contains((string) ($consumerDefinition['assets']['index.js'] ?? ''), "'data-wp-interactive': 'acme-site/theme-toggle'")
    && str_contains((string) ($consumerDefinition['view_js'] ?? ''), "store( 'acme-site/theme-toggle'"),
    'a consumer-supplied namespace resolves the block name and keeps the save, store, and PHP markup Interactivity namespaces consistent');

fwrite(STDOUT, "Theme toggle companion tests passed\n");
