<?php
declare(strict_types=1);

/**
 * A captured dropdown menu panel is not a modal. The user agent paints a bare
 * <dialog> as a centred white box with black text, and the source panel's
 * dark paint often lived on a wrapper (a header) that is not part of the
 * captured panel. The generated dialog must keep the "dropdown" presentation
 * and the block must ship rules that replace the user agent look.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CapturedDialogBlockGenerator;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if (! $condition) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    }
};

$compile = static function (?string $presentation, string $rootClass = 'menu-panel'): array {
    $panel = '<div class="' . $rootClass . '"><a href="/one">One</a><a href="/two">Two</a></div>';
    $dialog = array('html' => $panel, 'htmlBytes' => strlen($panel), 'htmlTruncated' => false);
    if (null !== $presentation) {
        $dialog['presentation'] = $presentation;
    }
    return (new ArtifactCompiler())->compile(array(
        'site' => array('name' => 'Dropdown Site', 'slug' => 'dropdown-site'),
        'entrypoint' => 'website/index.html',
        'files' => array(
            array('path' => 'website/index.html', 'content' => '<header><nav><button type="button" aria-label="Menu">Menu</button></nav></header><main><p>Body</p></main>'),
            array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))), JSON_UNESCAPED_SLASHES)),
            array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'data-liberation/captured-interactions/v1', 'pages' => array(array(
                'sourceUrl' => 'https://example.test/',
                'states' => array(array(
                    'status' => 'captured',
                    'trigger' => array('selector' => 'body > header > nav > button', 'tag' => 'button', 'ariaHaspopup' => '', 'label' => 'Menu', 'dataBindings' => array()),
                    'dialog' => $dialog,
                )),
            ))), JSON_UNESCAPED_SLASHES)),
        ),
    ))->toArray();
};

$dropdown = $compile('dropdown');
$blocks = (string) ($dropdown['serialized_blocks'] ?? '');
$assert(1 === preg_match('/<dialog[^>]*data-blocks-engine-presentation="dropdown"[^>]*data-blocks-engine-placement="under-header"/', $blocks), 'a dropdown panel keeps its presentation and drops under its header', $blocks);
$assert(str_contains($blocks, '"presentation":"dropdown"') && str_contains($blocks, '"placement":"under-header"'), 'the dropdown presentation and placement are saved as block attributes so the editor round trips them', $blocks);
$assert(! str_contains($blocks, 'data-blocks-engine-dialog-close') && ! str_contains($blocks, '"addCloseButton"'), 'a dropdown gets no generated Close control: its source trigger stays reachable', $blocks);

$placed = $compile('dropdown', 'absolute top-full left-0');
$placedBlocks = (string) ($placed['serialized_blocks'] ?? '');
$assert(str_contains($placedBlocks, 'data-blocks-engine-presentation="dropdown"') && str_contains($placedBlocks, 'data-blocks-engine-placement="source"'), 'a dropdown panel that positions itself is still a dropdown and keeps its own placement', $placedBlocks);

$modal = $compile(null);
$modalBlocks = (string) ($modal['serialized_blocks'] ?? '');
$assert(! str_contains($modalBlocks, 'data-blocks-engine-presentation') && ! str_contains($modalBlocks, 'data-blocks-engine-placement'), 'a dialog without a dropdown presentation is left alone', $modalBlocks);
$assert(str_contains($modalBlocks, 'data-blocks-engine-dialog-close="true"') && str_contains($modalBlocks, '"addCloseButton":true'), 'a modal without a source close control still gets the generated Close control', $modalBlocks);
$explicitModal = (string) ($compile('modal')['serialized_blocks'] ?? '');
$assert($explicitModal === $modalBlocks, 'an observed modal presentation keeps exactly the existing modal output', $explicitModal);

// A capture can instead wire the panel in place beside its trigger and mark it
// `dla-dropdown`. That adopted panel is the same dropdown, and the producer's
// recorded open-state change on the header travels with the dialog.
$ancestorState = array(array('selector' => 'body > header', 'tag' => 'header', 'depth' => 2, 'closed' => array('class' => 'top bg-transparent'), 'opened' => array('class' => 'top bg-dark')));
$compileWired = static function (string $panelClass, string $ancestorJson, bool $observedPlacement = false): array {
    $trigger = '<button type="button" aria-label="Toggle menu" data-dla-dialog-ancestor-state="' . htmlspecialchars($ancestorJson, ENT_QUOTES) . '" data-dla-disclosure-label="Toggle menu" data-dla-dialog-trigger="dla-dialog-0" aria-controls="dla-dialog-0" aria-expanded="false" aria-haspopup="menu">Menu</button>';
    $panel = '<div class="' . $panelClass . '" hidden id="dla-dialog-0"' . ($observedPlacement ? ' data-dla-observed-placement="true"' : '') . ' data-dla-dialog-panel="dla-dialog-0"><div><a href="/one">One</a></div><div><a href="/two">Two</a></div></div>';
    $html = '<html><body><header class="top bg-transparent"><div>' . $trigger . '</div>' . $panel . '</header><main><p>Body</p></main></body></html>';
    return (new ArtifactCompiler())->compile(array(
        'site' => array('name' => 'Wired Site', 'slug' => 'wired-site'),
        'entrypoint' => 'website/index.html',
        'files' => array(
            array('path' => 'website/index.html', 'content' => $html),
            array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))), JSON_UNESCAPED_SLASHES)),
            array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'data-liberation/captured-interactions/v1', 'pages' => array(array(
                'sourceUrl' => 'https://example.test/',
                'states' => array(array(
                    'status' => 'captured',
                    'trigger' => array('selector' => 'body > header > div > button', 'tag' => 'button', 'ariaHaspopup' => 'menu', 'label' => 'Toggle menu', 'dataBindings' => array()),
                    'dialog' => array('html' => '<div><a href="/one">One</a></div>', 'htmlBytes' => strlen('<div><a href="/one">One</a></div>'), 'htmlTruncated' => false, 'presentation' => 'dropdown'),
                )),
            ))), JSON_UNESCAPED_SLASHES)),
        ),
    ))->toArray();
};
$wired = (string) ($compileWired('px-5 dla-dialog dla-dropdown', json_encode($ancestorState))['serialized_blocks'] ?? '');
$assert(1 === preg_match('/<dialog[^>]*data-blocks-engine-presentation="dropdown"/', $wired), 'an in-place wired dla-dropdown panel keeps its dropdown presentation', $wired);
$assert(str_contains($wired, '"ancestorState":[{"tag":"header","closed":{"class":"top bg-transparent"},"opened":{"class":"top bg-dark"}}]'), 'the recorded ancestor open state is saved on the dialog block without source-DOM paths', $wired);
$assert(1 === preg_match('/<dialog[^>]*data-blocks-engine-ancestor-state="\[\{&quot;tag&quot;:&quot;header&quot;/', $wired), 'the dialog markup carries the ancestor open state for the view script', $wired);
$assert(! str_contains($wired, 'data-blocks-engine-dialog-close'), 'a wired dropdown gets no generated Close control', $wired);
$inPlaceResult = $compileWired('px-5 dla-dialog dla-dropdown', json_encode($ancestorState), true);
$inPlace = (string) ($inPlaceResult['serialized_blocks'] ?? '');
$assert(1 === preg_match('/<header[^>]*>.*<dialog[^>]*data-blocks-engine-placement="in-place".*<\/dialog>.*<\/header>/s', $inPlace), 'a dropdown at its observed source place stays inside its source parent', $inPlace);
$wiredModal = (string) ($compileWired('dla-dialog', '[]')['serialized_blocks'] ?? '');
$assert(str_contains($wiredModal, '<dialog') && ! str_contains($wiredModal, 'data-blocks-engine-presentation') && ! str_contains($wiredModal, 'ancestor-state'), 'a wired modal panel without the dropdown marker or ancestor state is left alone', $wiredModal);
$unsafe = $ancestorState;
$unsafe[0]['opened'] = array('onclick' => 'alert(1)');
$unsafe[0]['closed'] = array('onclick' => '');
$wiredUnsafe = (string) ($compileWired('dla-dialog dla-dropdown', json_encode($unsafe))['serialized_blocks'] ?? '');
$assert(! str_contains($wiredUnsafe, 'ancestor') && ! str_contains($wiredUnsafe, 'onclick'), 'ancestor state outside class, style and hidden is dropped, not replayed', $wiredUnsafe);

$definition = (new CapturedDialogBlockGenerator())->definition('site/captured-dialog');
$css = (string) ($definition['assets']['style.css'] ?? '');
$assert('file:./style.css' === ($definition['block_json']['style'] ?? null), 'the dialog block ships a stylesheet');
$assert(str_contains($css, '[data-blocks-engine-presentation="dropdown"]'), 'the stylesheet targets dropdown dialogs', $css);
foreach (array('position:fixed', 'margin:0', 'max-width:none', 'width:100%', 'background-color:var(--blocks-engine-dropdown-background', 'color:inherit', 'z-index:var(--blocks-engine-dropdown-layer', 'position:static') as $needle) {
    $assert(str_contains($css, $needle), 'dropdown dialog style resets the user agent look: ' . $needle, $css);
}
$assert(1 === preg_match('/:where\(dialog\[data-blocks-engine-presentation="dropdown"\]\[data-blocks-engine-placement="under-header"\]\)/', $css) && 1 === preg_match('/:where\(dialog\[data-blocks-engine-presentation="dropdown"\]\[data-blocks-engine-placement="in-place"\]\)/', $css), 'the resets have no specificity, so source classes still win', $css);

$view = (string) ($definition['view_js'] ?? '');
$assert(str_contains($view, '--blocks-engine-dropdown-background') && str_contains($view, 'backgroundColor'), 'the view script resolves the background from the trigger ancestors', $view);
$assert(str_contains($view, '--blocks-engine-dropdown-top'), 'the view script places the panel under the header', $view);
$assert(str_contains($view, 'data-blocks-engine-ancestor-state') && str_contains($view, "addEventListener( 'close'"), 'the view script replays the ancestor open state and restores it on close', $view);
$assert(str_contains($view, 'maskedDeclarations') && str_contains($view, 'CSS.escape'), 'inline block paint that masks a replayed open class is lifted while open and restored on close', $view);
$assert(str_contains($view, 'getKeyframes') && str_contains($css, 'backdrop-filter:var(--blocks-engine-dropdown-backdrop'), 'the panel takes the settled paint and backdrop of its painted ancestor', $css);

$assert(str_contains($view, 'dialog.show()') && str_contains($view, 'dialog.showModal()'), 'the view script opens dropdowns non-modally and keeps modals modal', $view);

// Fixtures for the real-browser contract (captured-dialog-dropdown-panel-browser.mjs).
$fixtureCss = '.top{position:fixed;top:0;left:0;right:0;z-index:50}.bg-transparent{background-color:transparent}.bg-dark{background-color:rgb(2,6,23)}.px-5{padding-left:20px;padding-right:20px}.page-layer{position:fixed;inset:0;z-index:30;pointer-events:none}';
file_put_contents(sys_get_temp_dir() . '/captured-dialog-dropdown-panel.json', json_encode(array(
    'css' => $css . $fixtureCss,
    'script' => $view,
    'inPlace' => $inPlace,
    'underHeader' => (string) ($compileWired('px-5 dla-dialog dla-dropdown', json_encode($ancestorState))['serialized_blocks'] ?? ''),
    'modal' => $modalBlocks,
), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

if (0 !== $failures) {
    exit(1);
}
echo "captured-dialog-dropdown-panel: ok\n";
