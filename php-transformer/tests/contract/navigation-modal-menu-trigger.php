<?php
declare(strict_types=1);

/**
 * A header's compact menu toggle opens a captured panel declared as a modal
 * dialog (`role="dialog" aria-modal="true"`). The toggle sits in the header's
 * navigation landmark beside the panel, so it reads like an item's anchor with
 * a submenu, but the panel is an overlay the toggle opens: nothing lowers it
 * into a navigation submenu. The import must keep it working: the panel
 * becomes the native captured dialog bound to the toggle (the dialog runtime
 * opens and closes it), never a plain hidden group with an unbound toggle.
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
    . '<ul role="menu" class="drawer-list"><li role="menuitem"><a href="/">Home</a></li><li role="menuitem"><a href="/about/">About</a></li><li role="menuitem"><a href="/contact/">Contact</a></li></ul></div>';
$snapshot = htmlspecialchars((string) json_encode(array('resting' => array('html' => $drawer, 'style' => null, 'class' => 'data-blocks-engine-capture-dialog', 'hidden' => true)), JSON_THROW_ON_ERROR), ENT_QUOTES);
$toggle = '<a role="button" aria-haspopup="menu" id="menu-toggle" aria-expanded="false" aria-label="Open menu" href="#" class="burger" data-blocks-engine-capture-dialog-trigger="' . $key . '" aria-controls="' . $key . '">'
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

$artifact = static function (bool $withReport) use ($html, $css): array {
    $files = array(
        array('path' => 'website/index.html', 'content' => $html),
        array('path' => 'website/style.css', 'content' => $css),
    );
    if ($withReport) {
        $files[] = array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html')))));
        $files[] = array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => array())))));
    }
    return array('entrypoint' => 'website/index.html', 'files' => $files);
};

$compiler = new ArtifactCompiler();
foreach (array('interaction report' => true, 'no interaction report' => false) as $case => $withReport) {
    $result = $compiler->compile($artifact($withReport))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $assets = array_column($result['assets'] ?? array(), 'content');
    $runtimeShips = in_array($helper, $assets, true);
    $reasons = array_values(array_unique(array_column($result['source_reports']['native_runtime_replacements'] ?? array(), 'reason')));
    $assert(!in_array('native_navigation_submenu_replaces_capture_disclosure', $reasons, true), $case . ': the disclosure runtime is not retired as if a native submenu replaced it', json_encode($reasons));

    $dialog = preg_match('/<!-- wp:custom\/captured-dialog (\{[^\n]*?\}) -->/', $markup, $match) ? json_decode($match[1], true) : null;
    if (is_array($dialog)) {
        // Native projection: the dialog block owns the panel and its opener.
        $assert(in_array('menu-toggle', $dialog['triggerIds'] ?? array(), true), $case . ': the native dialog is bound to the toggle', json_encode($dialog));
        $dialogMarkup = substr($markup, (int) strpos($markup, $match[0]));
        foreach (array('Home', 'About', 'Contact') as $label) $assert(str_contains($dialogMarkup, '>' . $label . '</a>'), $case . ': the dialog holds the "' . $label . '" menu item');
        $assert(1 === preg_match('/id="menu-toggle"[^>]*aria-controls="' . preg_quote((string) ($dialog['dialogId'] ?? ''), '/') . '"|aria-controls="' . preg_quote((string) ($dialog['dialogId'] ?? ''), '/') . '"[^>]*id="menu-toggle"/', $markup), $case . ': the toggle controls the native dialog');
        $assert(!$runtimeShips, $case . ': the native dialog replaces the capture disclosure runtime');
    } else {
        // Kept runtime: the toggle and panel keep their capture bindings and
        // the disclosure runtime that opens the panel ships with the page.
        $assert($runtimeShips, $case . ': the disclosure runtime that opens the panel ships with the page');
        $assert(str_contains($markup, 'data-blocks-engine-capture-dialog-trigger="' . $key . '"') && str_contains($markup, 'data-blocks-engine-capture-dialog-panel="' . $key . '"'), $case . ': toggle and panel keep the bindings the runtime opens');
    }
    $assert(!str_contains($markup, '<!-- wp:navigation-submenu {"label":"Open menu"'), $case . ': the toggle is not emitted as a navigation submenu');
}

if (0 < $failures) { fwrite(STDERR, "navigation modal menu trigger FAILED: {$passes} passed, {$failures} failed\n"); exit(1); }
echo "navigation modal menu trigger passed: {$passes} assertions\n";
