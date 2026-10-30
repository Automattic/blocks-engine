<?php
declare(strict_types=1);

require_once __DIR__ . '/../fixtures/captured-components.php';

/**
 * A phone toggle can open the same panel the wider layout shows in place: a
 * header `<nav>` that authored CSS hides only below a breakpoint, behind a
 * "Menu" button that only that breakpoint reveals. The capture reuses that
 * panel for the toggle and records its resting state (`existing-panel`,
 * `hidden: false`). That panel belongs to the document at every width, so it
 * stays in place as the source navigation, visible under authored CSS where
 * the toggle is not rendered. The source runtime that toggled it is gone after
 * import, so it is wrapped in place by a captured dropdown its trigger opens
 * and closes (#3051); it does not become a modal or a dialog box that would
 * hide the links on wide screens.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;

$failures = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures): void {
    if ( ! $ok ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . substr($detail, 0, 600) : '' ) . PHP_EOL);
    }
};

$links = '<a href="#services">Services</a><a href="#work">Work</a>';
$existing = htmlspecialchars((string) json_encode(array(
    'resting' => array('style' => null, 'class' => 'data-blocks-engine-capture-dropdown', 'hidden' => false),
    'opened' => array('style' => 'display: flex;', 'class' => 'data-blocks-engine-capture-dialog data-blocks-engine-capture-dropdown'),
)), ENT_QUOTES);
$html = '<html lang="en"><head><style>header{display:flex;justify-content:space-between}nav{display:flex;gap:28px}.menu-toggle{display:none}'
    . '@media(max-width:650px){.menu-toggle{display:block}header nav{display:none}.menu-toggle[aria-expanded=true]+nav{display:flex}}</style></head><body>'
    . '<header><a class="brand" href="/">Brand</a>'
    . '<button class="menu-toggle" type="button" aria-expanded="false" aria-controls="nav" aria-haspopup="menu" data-blocks-engine-capture-disclosure-label="Menu" data-blocks-engine-capture-dialog-trigger="nav">Menu</button>'
    . '<nav id="nav" aria-label="Main navigation" class="data-blocks-engine-capture-dialog data-blocks-engine-capture-dropdown" hidden="" data-blocks-engine-capture-observed-placement="true" data-blocks-engine-capture-dialog-panel="nav" data-blocks-engine-capture-existing-panel="' . $existing . '">' . $links . '</nav>'
    . '</header><main><h1 id="services">Services</h1><h2 id="work">Work</h2></main></body></html>';
$panelHtml = '<nav id="nav" aria-label="Main navigation" style="display: flex !important;">' . $links . '</nav>';
$state = array(
    'status' => 'captured',
    'trigger' => array('selector' => 'body > header > button', 'tag' => 'button', 'label' => 'Menu', 'ariaHaspopup' => '', 'dataBindings' => array()),
    'dialog' => array('selector' => '#nav', 'tag' => 'nav', 'presentation' => 'dropdown', 'html' => $panelHtml, 'htmlBytes' => strlen($panelHtml), 'htmlTruncated' => false),
);
$files = array(
    array('path' => 'website/index.html', 'content' => $html),
    array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))), JSON_UNESCAPED_SLASHES)),
    array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => captured_components(array($state))))), JSON_UNESCAPED_SLASHES)),
);

$projected = (string) ((new CapturedDialogProjector())->project($files)['files'][0]['content'] ?? '');
$assert(! str_contains($projected, '<dialog'), 'the shared resting panel is not moved into a dialog box', $projected);
$assert(1 === preg_match('/<header>.*<div id="([^"]+)" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="([^"]+)"[^>]*data-blocks-engine-placement="in-place" data-blocks-engine-resting="authored"[^>]*><nav id="nav"[^>]*>' . preg_quote($links, '/') . '<\/nav><\/div><\/header>/s', $projected, $wrapper), 'the panel stays in place in the header, wrapped by an in-place dropdown', $projected);
$assert(1 === preg_match('/<button[^>]*aria-controls="' . preg_quote($wrapper[1] ?? '-', '/') . '"[^>]*id="' . preg_quote($wrapper[2] ?? '-', '/') . '"/', $projected), 'the captured toggle opens and closes the in-place panel', $projected);
$assert(1 === preg_match('/<nav id="nav" aria-label="Main navigation">/', $projected), 'the panel is restored to its visible resting state', $projected);

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray();
$markup = implode('', array_column($result['source_reports']['wordpress_site_plan']['pages'] ?? array(), 'canonical_block_markup'))
    . json_encode($result['source_reports']['wordpress_site_plan']['template_parts'] ?? array(), JSON_UNESCAPED_SLASHES);
$markup = stripslashes($markup);
$assert(1 === preg_match('/<!-- wp:[a-z0-9-]+\/captured-dialog \{[^>]*"placement":"in-place","resting":"authored"[^>]*--><div [^>]*>\s*<!-- wp:navigation \{[^}]*"anchor":"nav"/', $markup), 'the panel converts to the header navigation, wrapped by its toggle\'s in-place dropdown', $markup);
$assert(! str_contains($markup, '<dialog') && ! str_contains($markup, 'data-blocks-engine-capture'), 'no dialog box or capture-only attribute is left', $markup);

if ( $failures > 0 ) {
    fwrite(STDERR, "Captured dialog shared resting panel failed ({$failures})\n");
    exit(1);
}
fwrite(STDOUT, "Captured dialog shared resting panel passed\n");
