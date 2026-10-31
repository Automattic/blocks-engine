<?php
declare(strict_types=1);

require_once __DIR__ . '/../fixtures/captured-components.php';

/**
 * A phone menu panel closed at rest by a class (`hidden lg:hidden`), toggled by
 * a source script, records `resting.hidden: false`: the producer reads only the
 * `hidden` attribute. That does not prove the panel shows at rest, and the
 * source script is gone after import. The panel is adopted in place as a
 * native dropdown its captured trigger opens and closes; while closed it keeps
 * its resting class, so it stays closed until the trigger opens it (#3051).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;

$failures = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures): void {
    if ( ! $ok ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . substr($detail, 0, 900) : '' ) . PHP_EOL);
    }
};

$html = <<<'HTML'
<html><head><style>.hidden{display:none}@media(min-width:1024px){.lg\:hidden{display:none}}</style></head><body>
<header><a href="/">Site</a>
<button id="menuBtn" class="lg:hidden" aria-label="Toggle menu" aria-controls="menuPanel" aria-expanded="false" aria-haspopup="menu" data-blocks-engine-capture-dialog-trigger="menuPanel">Menu</button>
<div id="menuPanel" class="lg:hidden data-blocks-engine-capture-dropdown" style="display: block ; visibility: visible !important;"
  data-blocks-engine-capture-existing-panel='{"resting":{"html":"<p>Closed content</p>","style":null,"class":"hidden lg:hidden data-blocks-engine-capture-dropdown","hidden":false},"opened":{"style":"display: block ; visibility: visible !important;","class":"lg:hidden data-blocks-engine-capture-dropdown"}}'
  hidden data-blocks-engine-capture-dialog-panel="menuPanel"><a href="/about">About</a><a href="/contact">Contact</a></div>
</header><main><h1>Home</h1></main></body></html>
HTML;
$html = str_replace('id="menuBtn"', 'id="menuBtn" data-blocks-engine-capture-dialog-panel-state="' . htmlspecialchars(json_encode(array('html' => '<a href="/about">About</a><a href="/contact">Contact</a>', 'class' => 'lg:hidden data-blocks-engine-capture-dropdown', 'style' => 'display: block ; visibility: visible !important;')), ENT_QUOTES) . '"', $html);
$html = str_replace('</head>', '<script data-blocks-engine-capture-disclosure-runtime="true">' . file_get_contents(__DIR__ . '/../fixtures/capture-disclosure-runtime.js') . '</script></head>', $html);
$panelHtml = '<div id="menuPanel" class="lg:hidden" style="display: block ; visibility: visible !important;"><a href="/about">About</a><a href="/contact">Contact</a></div>';
$state = array(
    'status' => 'captured',
    'trigger' => array('selector' => '#menuBtn', 'tag' => 'button', 'label' => 'Toggle menu', 'ariaHaspopup' => 'menu', 'dataBindings' => array()),
    'dialog' => array('selector' => '#menuPanel', 'tag' => 'div', 'presentation' => 'dropdown', 'html' => $panelHtml, 'htmlBytes' => strlen($panelHtml), 'htmlTruncated' => false),
);
$files = array(
    array('path' => 'website/index.html', 'content' => $html),
    array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))), JSON_UNESCAPED_SLASHES)),
    array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => captured_components(array($state))))), JSON_UNESCAPED_SLASHES)),
);

// Projection: the panel becomes a captured dropdown bound to its trigger.
$projection = (new CapturedDialogProjector())->project($files);
$projected = (string) ($projection['files'][0]['content'] ?? '');
$assert(1 === $projection['projected_count'], 'the class-hidden panel is projected as a captured dialog', $projected);
$document = new DOMDocument();
@$document->loadHTML($projected);
$xpath = new DOMXPath($document);
$dialog = $xpath->query('//*[@data-blocks-engine-captured-dialog="true"]')->item(0);
$button = $document->getElementById('menuBtn');
$assert($dialog instanceof DOMElement && $button instanceof DOMElement, 'the projected dialog and its trigger exist', $projected);
if ($dialog instanceof DOMElement && $button instanceof DOMElement) {
    $assert($button->getAttribute('aria-controls') === $dialog->getAttribute('id'), 'the trigger controls the projected dialog', $projected);
    $assert('menuBtn' === $dialog->getAttribute('data-blocks-engine-triggers'), 'the dialog is bound to #menuBtn', $projected);
    $assert('dropdown' === $dialog->getAttribute('data-blocks-engine-presentation') && 'in-place' === $dialog->getAttribute('data-blocks-engine-placement'), 'the dialog is a dropdown kept at its source place', $projected);
    $assert('header' === strtolower($dialog->parentNode->nodeName), 'the dialog stays in the header', $projected);
    $assert(! $dialog->hasAttribute('open'), 'the dialog is closed', $projected);
    $panel = $dialog->firstElementChild;
    $assert($panel instanceof DOMElement && 'menuPanel' === $panel->getAttribute('id') && 'hidden lg:hidden' === $panel->getAttribute('class') && ! $panel->hasAttribute('style') && ! $panel->hasAttribute('hidden'), 'the dialog holds the panel at its resting presentation', $projected);
}

$assert(! str_contains($projected, '<script'), 'the current disclosure body is recognized and retired');
$assert(str_contains($projected, 'captured-dialog-closed-content"><p>Closed content</p>') && str_contains($projected, 'captured-dialog-open-content"><a href="/about">About</a>'), 'both observed content states survive native projection', $projected);

// Conversion: the saved markup holds a closed captured dialog that toggles the panel.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$markup = implode('', array_column($plan['pages'] ?? array(), 'canonical_block_markup')) . implode('', array_column($plan['template_parts'] ?? array(), 'canonical_block_markup'));
$assert(1 === preg_match('/<!-- wp:[a-z0-9-]+\/captured-dialog (\{.*?\}) --><div ([^>]*)>(.*?)<!-- \/wp:[a-z0-9-]+\/captured-dialog -->/s', $markup, $match), 'the panel converts to a captured dialog block', $markup);
$attrs = json_decode($match[1] ?? '', true);
$assert(is_array($attrs) && array('menuBtn') === ($attrs['triggerIds'] ?? null), 'the saved dialog is bound to #menuBtn', $match[1] ?? $markup);
$assert(is_array($attrs) && 'dropdown' === ($attrs['presentation'] ?? null) && 'in-place' === ($attrs['placement'] ?? null), 'the saved dialog is an in-place dropdown', $match[1] ?? $markup);
$assert(! preg_match('/(?:^|\s)open(?:[\s=]|$)/', $match[2] ?? 'open'), 'the saved dialog is closed', $match[2] ?? $markup);
$assert(! str_contains($markup, 'data-blocks-engine-capture'), 'no capture-only attribute or class survives', $markup);
// The resting class sits only on the panel the dialog toggles: closed it is
// `hidden lg:hidden`, open the dialog swaps it for the opened class.
$assert(! str_contains(str_replace($match[0] ?? '', '', $markup), 'hidden lg:hidden'), 'the resting class appears on no element outside the dialog', $markup);
$assert(1 === preg_match('/^<!-- wp:group \{"anchor":"menuPanel","className":"hidden lg:hidden[^"]*"/', $match[3] ?? ''), 'the resting class is on the panel inside the dialog', $match[3] ?? $markup);
$resting = is_array($attrs) ? ($attrs['restingState'] ?? null) : null;
$assert('hidden lg:hidden' === ($resting['closed']['class'] ?? null) && 'lg:hidden' === ($resting['opened']['class'] ?? null) && 'authored' === ($attrs['resting'] ?? null), 'the dialog opens the panel by replacing its resting class', json_encode($attrs));

$definition = (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CapturedDialogBlockGenerator())->definition('custom/captured-dialog');
$assert(str_contains($markup, 'captured-dialog-closed-content') && str_contains($markup, 'Closed content') && str_contains($markup, 'captured-dialog-open-content'), 'both observed content states remain editable after conversion', $markup);
$assert(str_contains($definition['assets']['style.css'], ':not([open]) > * > .captured-dialog-open-content') && str_contains($definition['assets']['style.css'], '[open] > * > .captured-dialog-closed-content'), 'native open state selects the matching content without destroying either editable copy');
file_put_contents(sys_get_temp_dir() . '/captured-dialog-resting-content.json', json_encode(array(
    'source' => $html,
    'markup' => $markup,
    'script' => $definition['view_js'],
    'css' => $definition['assets']['style.css'],
)));

if ( $failures > 0 ) {
    fwrite(STDERR, "Captured dialog class-hidden resting panel failed ({$failures})\n");
    exit(1);
}
fwrite(STDOUT, "Captured dialog class-hidden resting panel passed\n");
