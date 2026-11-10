<?php
declare(strict_types=1);

/**
 * A header's compact menu toggle opens a captured panel declared as a modal
 * dialog (`role="dialog" aria-modal="true"`). The toggle sits in the header's
 * navigation landmark beside the panel, so it reads like an item's anchor with
 * a submenu, but the panel is an overlay the toggle opens: nothing lowers it
 * into a navigation submenu. The import must keep it working: the panel
 * becomes the native captured dialog bound to the toggle, opens as the
 * viewport overlay the capture runtime presented, and closes from the drawer's
 * capture-bound close control; never a plain hidden group with an unbound
 * toggle.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ($condition) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$helper = trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-disclosure-runtime.js'));
$key = 'capture-dialog-0';
$drawer = '<div role="navigation" class="drawer"><div class="drawer-top"><svg viewBox="0 0 24 24" width="40" height="40" data-close="true" class="close-icon" data-blocks-engine-capture-dialog-close="' . $key . '" role="button" tabindex="0"><path d="M4 4l16 16M20 4L4 20"></path></svg></div>'
    . '<div class="drawer-body"><div class="drawer-links"><ul role="menu" class="drawer-list"><li role="menuitem"><a role="link" href="/"><span>Home</span></a></li><li role="menuitem"><a role="link" href="/about/"><span>About</span></a></li><li role="menuitem"><a role="link" href="/contact/"><span>Contact</span></a></li></ul><div class="drawer-foot"></div></div></div></div>';
$snapshot = htmlspecialchars((string) json_encode(array('resting' => array('html' => $drawer, 'style' => null, 'class' => 'data-blocks-engine-capture-dialog', 'hidden' => true)), JSON_THROW_ON_ERROR), ENT_QUOTES);
// Opened, the source slid the drawer into view with an inline transform.
$openDrawer = str_replace('class="drawer"', 'class="drawer" style="visibility: visible; transform: translateX(0px);"', $drawer);
$opened = htmlspecialchars((string) json_encode(array('html' => $openDrawer, 'style' => null, 'class' => 'data-blocks-engine-capture-dialog'), JSON_THROW_ON_ERROR), ENT_QUOTES);
$toggle = '<a data-blocks-engine-capture-dialog-panel-state="' . $opened . '" role="button" aria-haspopup="menu" id="menu-toggle" aria-expanded="false" aria-label="Open menu" href="#" class="burger" data-blocks-engine-capture-dialog-trigger="' . $key . '" aria-controls="' . $key . '">'
    . '<svg viewBox="0 0 24 24" fill="currentColor" width="40px" height="40px"><path d="M4 8h16V6H4z"></path><path d="M4 13h16v-2H4z"></path></svg></a>';
$panel = '<div class="data-blocks-engine-capture-dialog" role="dialog" aria-modal="true" hidden id="' . $key . '" data-blocks-engine-capture-dialog-panel="' . $key . '" data-blocks-engine-capture-existing-panel="' . $snapshot . '">' . $drawer . '</div>';
$css = '.burger{display:none}.bar{display:flex;justify-content:space-between}@media (max-width:767px){.burger{display:block;width:40px;height:40px}.desk{display:none}}';
$header = '<header><nav class="bar"><div class="brand"><a href="/"><h3>Site title</h3></a></div>'
    . '<div class="toggle-row"><div id="toggle-wrap"><span class="toggle-host">' . $toggle . '</span>' . $panel . '</div></div></nav>'
    . '<nav class="desk"><ul><li><a href="/">Home</a></li><li><a href="/about/">About</a></li><li><a href="/contact/">Contact</a></li></ul></nav></header>';
$html = '<!doctype html><html><head><link rel="stylesheet" href="style.css"><script data-blocks-engine-capture-disclosure-runtime="true">' . $helper . '</script></head><body>'
    . $header . '<main><h1>Welcome</h1><p>Neutral page copy.</p></main></body></html>';

$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$trigger = (new DOMXPath($document))->query('//*[@id="menu-toggle"]')->item(0);
$assert($trigger instanceof DOMElement && !NavigationPattern::ownsCapturedSubmenuTrigger($trigger), 'a toggle opening a modal dialog panel is not claimed as a navigation submenu trigger');

// The capture probed the toggle: clicking it opened the drawer as a modal.
$evidence = array('status' => 'captured',
    'trigger' => array('selector' => '#menu-toggle', 'tag' => 'a', 'id' => 'menu-toggle', 'role' => 'button', 'ariaHaspopup' => 'menu', 'label' => 'Open menu', 'dataBindings' => array()),
    'dialog' => array('selector' => '.drawer', 'tag' => 'div', 'role' => 'navigation', 'ariaModal' => false, 'presentation' => 'modal', 'html' => $drawer, 'htmlBytes' => strlen($drawer), 'htmlTruncated' => false));
$component = array('selector' => '#menu-toggle', 'kind' => 'menu', 'initial' => 'closed', 'status' => 'captured',
    'states' => array(array('id' => 'closed', 'html' => $toggle, 'evidence' => $evidence)),
    'transitions' => array(array('from' => 'closed', 'to' => 'open', 'trigger' => array('selector' => '#menu-toggle', 'action' => 'click'))));
$artifact = array('entrypoint' => 'website/index.html', 'files' => array(
    array('path' => 'website/index.html', 'content' => $html),
    array('path' => 'website/style.css', 'content' => $css),
    array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))))),
    array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v3', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => array($component)))))),
));

$result = (new ArtifactCompiler())->compile($artifact)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$assets = implode("\n", array_map('strval', array_column($result['assets'] ?? array(), 'content')));
$reasons = array_values(array_unique(array_column($result['source_reports']['native_runtime_replacements'] ?? array(), 'reason')));
$assert(!in_array('native_navigation_submenu_replaces_capture_disclosure', $reasons, true), 'the disclosure runtime is not retired as if a native submenu replaced it', json_encode($reasons));
$assert(!str_contains($markup, '<!-- wp:navigation-submenu {"label":"Open menu"'), 'the toggle is not emitted as a navigation submenu');

// The panel becomes the native dialog bound to the toggle.
$shell = preg_match('/<!-- wp:group (\{"metadata":\{"blocksEngineDialog"[^\n]*?\}) -->/', $markup, $match) ? (json_decode($match[1], true)['metadata']['blocksEngineDialog']['attributes'] ?? array()) : array();
$dialog = array('triggerIds' => explode(' ', $shell['data-blocks-engine-triggers'] ?? ''), 'dialogId' => $shell['id'] ?? '', 'restingState' => json_decode($shell['data-blocks-engine-resting-state'] ?? '{}', true));
$assert(is_array($dialog) && in_array('menu-toggle', $dialog['triggerIds'] ?? array(), true), 'the panel becomes a native dialog bound to the toggle', $markup);
$dialogId = preg_quote((string) ($dialog['dialogId'] ?? '-'), '/');
$assert(1 === preg_match('/id="menu-toggle"[^>]*aria-controls="' . $dialogId . '"|aria-controls="' . $dialogId . '"[^>]*id="menu-toggle"/', $markup), 'the toggle controls the native dialog');
$dialogBody = substr($markup, strpos($markup, 'captured-dialog-closed-content') ?: 0);
foreach (array('Home' => '/', 'About' => '/about/', 'Contact' => '/contact/') as $label => $url) {
    $assert(str_contains($dialogBody, '"label":"' . $label . '","url":"' . $url . '"') || 1 === preg_match('/href="' . preg_quote($url, '/') . '"[^>]*>(?:<[^>]+>)*' . $label . '</', $dialogBody), 'the dialog holds the "' . $label . '" menu item', $dialogBody);
}
$assert(!str_contains($assets, 'data-blocks-engine-capture-disclosure-runtime'), 'the native dialog replaces the capture disclosure runtime');

// The capture runtime opened the modal panel as a viewport overlay; the
// drawer inside is laid out against it, so the dialog opens it the same way.
$overlay = (string) ($dialog['restingState']['opened']['style'] ?? '');
$assert(str_contains($overlay, 'position:fixed') && str_contains($overlay, 'inset:0'), 'the modal panel opens as a viewport overlay', json_encode($dialog['restingState'] ?? null));

// The drawer's capture-bound close icon still closes the dialog.
$assert(str_contains($dialogBody, 'data-blocks-engine-capture-dialog-close="' . $key . '"'), 'the drawer keeps its capture-bound close control', $dialogBody);
$viewScripts = (new Automattic\BlocksEngine\PhpTransformer\WordPress\CapturedDialogBlockContract())->definition('unused/dialog')['view_js'];
$assert(str_contains($viewScripts, "hasAttribute( 'data-blocks-engine-capture-dialog-close' )"), 'the dialog view script closes from a capture-bound close control');

if (0 < $failures) { fwrite(STDERR, "navigation modal menu trigger FAILED: {$passes} passed, {$failures} failed\n"); exit(1); }
echo "navigation modal menu trigger passed: {$passes} assertions\n";
