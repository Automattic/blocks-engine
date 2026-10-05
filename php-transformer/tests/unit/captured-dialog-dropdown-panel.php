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
$assert(1 === preg_match('/<dialog[^>]*data-blocks-engine-presentation="dropdown"/', $blocks), 'a dropdown panel keeps its presentation on the generated dialog', $blocks);
$assert(str_contains($blocks, '"presentation":"dropdown"'), 'the dropdown presentation is saved as a block attribute so the editor round trips it', $blocks);

$placed = $compile('dropdown', 'absolute top-full left-0');
$assert(! str_contains((string) ($placed['serialized_blocks'] ?? ''), 'data-blocks-engine-presentation'), 'a dropdown panel that positions itself keeps its own placement');

$modal = $compile(null);
$assert(! str_contains((string) ($modal['serialized_blocks'] ?? ''), 'data-blocks-engine-presentation'), 'a dialog without a dropdown presentation is left alone');

$definition = (new CapturedDialogBlockGenerator())->definition('site/captured-dialog');
$css = (string) ($definition['assets']['style.css'] ?? '');
$assert('file:./style.css' === ($definition['block_json']['style'] ?? null), 'the dialog block ships a stylesheet');
$assert(str_contains($css, '[data-blocks-engine-presentation="dropdown"]'), 'the stylesheet targets dropdown dialogs', $css);
foreach (array('position:fixed', 'margin:0', 'max-width:none', 'width:100%', 'background-color:var(--blocks-engine-dropdown-background', 'color:inherit', '::backdrop') as $needle) {
    $assert(str_contains($css, $needle), 'dropdown dialog style resets the user agent look: ' . $needle, $css);
}
$assert(1 === preg_match('/:where\(dialog\[data-blocks-engine-presentation="dropdown"\]\)/', $css), 'the reset has no specificity, so source classes still win', $css);

$view = (string) ($definition['view_js'] ?? '');
$assert(str_contains($view, '--blocks-engine-dropdown-background') && str_contains($view, 'backgroundColor'), 'the view script resolves the background from the trigger ancestors', $view);
$assert(str_contains($view, '--blocks-engine-dropdown-top'), 'the view script places the panel under the header', $view);

if (0 !== $failures) {
    exit(1);
}
echo "captured-dialog-dropdown-panel: ok\n";
