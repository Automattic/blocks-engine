<?php
declare(strict_types=1);

require_once __DIR__ . '/../fixtures/captured-components.php';

/**
 * A capture panel that wraps the source's own native `<dialog>` projects as
 * one dialog. The capture opens the source dialog for its snapshot
 * (`open`, `display:block !important`) and wraps it in its wired panel
 * envelope. Projecting that envelope's content nested the source dialog
 * inside the projected one, where it had no converter: an unsupported
 * fallback, and a forced-visible box the projected dialog could not close.
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

$dialog = '<dialog class="box" aria-labelledby="t" open="" style="display: block !important; visibility: visible !important;">'
    . '<button type="button" class="close" aria-label="Close">×</button><h2 id="t">Tell us more</h2>'
    . '<form action="https://example.test/submit" method="POST"><label>Your name<input name="name"></label><button type="submit">Send</button></form></dialog>';
$html = '<html lang="en"><head><style>.box{border:0;padding:38px;background:#fff}</style></head><body><main><section class="contact"><h2>Got an idea?</h2>'
    . '<button type="button" class="open" data-blocks-engine-capture-disclosure-label="Tell us more" data-blocks-engine-capture-dialog-trigger="data-blocks-engine-capture-dialog-0" aria-controls="data-blocks-engine-capture-dialog-0" aria-expanded="false" aria-haspopup="dialog">Write to us</button>'
    . '<div class="data-blocks-engine-capture-dialog" role="dialog" aria-modal="true" hidden="" id="data-blocks-engine-capture-dialog-0" data-blocks-engine-capture-dialog-panel="data-blocks-engine-capture-dialog-0">' . $dialog . '</div>'
    . '<button type="button" hidden="" data-blocks-engine-capture-dialog-close="data-blocks-engine-capture-dialog-0" aria-label="Close Tell us more">Close</button></section></main></body></html>';
$state = array(
    'status' => 'captured',
    'trigger' => array('selector' => 'main > section > button', 'tag' => 'button', 'label' => 'Write to us', 'ariaHaspopup' => '', 'dataBindings' => array()),
    'dialog' => array('selector' => 'main > section > dialog', 'tag' => 'dialog', 'presentation' => 'modal', 'html' => $dialog, 'htmlBytes' => strlen($dialog), 'htmlTruncated' => false),
);
$files = array(
    array('path' => 'website/index.html', 'content' => $html),
    array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))), JSON_UNESCAPED_SLASHES)),
    array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => captured_components(array($state))))), JSON_UNESCAPED_SLASHES)),
);

$projected = (string) ((new CapturedDialogProjector())->project($files)['files'][0]['content'] ?? '');
$assert(1 === substr_count($projected, '<dialog'), 'the panel and the native dialog it wraps project as one dialog', $projected);
$assert(1 === preg_match('/<dialog[^>]*class="[^"]*\bbox\b/', $projected), 'the projected dialog keeps the source dialog class', $projected);
$assert(! str_contains($projected, 'display: block !important'), 'the capture snapshot forced-visible style is not carried into the projected dialog', $projected);

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray();
$json = json_encode($result, JSON_UNESCAPED_SLASHES) ?: '';
$assert(! str_contains($json, '"tag":"dialog","selector"'), 'no unsupported dialog fallback is reported', '');
$markup = implode('', array_column($result['source_reports']['wordpress_site_plan']['pages'] ?? array(), 'canonical_block_markup'));
$assert(str_contains($markup, 'Tell us more') && str_contains($markup, 'Your name'), 'the dialog content lowers into the page', $markup);

if ( $failures > 0 ) {
    fwrite(STDERR, "Captured native dialog panel failed ({$failures})\n");
    exit(1);
}
fwrite(STDOUT, "Captured native dialog panel passed\n");
