<?php
declare(strict_types=1);

require_once __DIR__ . '/../fixtures/captured-components.php';

/**
 * A projected modal keeps the box its source panel drew.
 *
 * A Wix phone menu panel (`#MENU_AS_CONTAINER`) is captured twice: the
 * capture's own hidden wired copy keeps the panel's id, and the captured
 * state is projected as the native dialog under a generated id. Two things
 * then broke the panel at phone width:
 * - author rules that name the panel by id (`#panel{left;width;--paint}`)
 *   reached only the hidden copy, so the dialog lost its offset and the
 *   variable its background layer paints with;
 * - the dialog kept the user agent's box (1em padding, a 3px border, auto
 *   margins), which the source `div` panel never had, so the column sat off
 *   centre inside a black frame.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CapturedDialogBlockGenerator;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . substr($detail, 0, 600) : '' ) . PHP_EOL);
};

$panel = '<div id="panel" class="panel" role="dialog"><div class="paint"></div><nav class="menu"><a href="/a">Alpha</a><a href="/b">Beta</a></nav></div>';
$files = static function (string $wiredCopy) use ($panel): array {
    $html = '<html><head><style>#panel{left:10px;width:200px;--panel-paint:rgb(233, 220, 211)}'
        . '.panel{position:fixed;top:0;height:100vh}.panel .paint{position:absolute;inset:0;background-color:var(--panel-paint)}</style></head>'
        . '<body><header><a href="/">Home</a><button id="menu" type="button" aria-label="Open menu" aria-haspopup="dialog" aria-expanded="false" aria-controls="wired"'
        . ' data-blocks-engine-capture-dialog-trigger="wired" data-blocks-engine-capture-disclosure-label="Open menu"><span></span></button>'
        . $wiredCopy . '</header><main><p>Body</p></main></body></html>';
    $state = array(
        'status' => 'captured',
        'trigger' => array('selector' => '#menu', 'tag' => 'button', 'label' => 'Open menu', 'ariaHaspopup' => 'dialog', 'dataBindings' => array()),
        'dialog' => array('selector' => '#panel', 'tag' => 'div', 'role' => 'dialog', 'presentation' => 'modal', 'html' => $panel, 'htmlBytes' => strlen($panel), 'htmlTruncated' => false),
    );

    return array(
        array('path' => 'website/index.html', 'content' => $html),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))), JSON_UNESCAPED_SLASHES)),
        array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => captured_components(array($state))))), JSON_UNESCAPED_SLASHES)),
    );
};
$wired = '<div class="data-blocks-engine-capture-dialog" role="dialog" aria-modal="true" hidden id="wired" data-blocks-engine-capture-dialog-panel="wired">' . $panel . '</div>';

// Projection: the dialog carries the held id as its editor anchor class.
$projected = (string) ((new CapturedDialogProjector())->project($files($wired))['files'][0]['content'] ?? '');
$assert(1 === preg_match('/<dialog[^>]*class="[^"]*\bblocks-engine-editor-anchor-panel\b/', $projected), 'a dialog whose source id another copy holds carries it as its anchor class', $projected);
$free = (string) ((new CapturedDialogProjector())->project($files(''))['files'][0]['content'] ?? '');
$assert(! str_contains($free, 'blocks-engine-editor-anchor-panel'), 'a dialog whose source id no element holds gets no anchor class', $free);

// Compilation: the id rules reach the dialog and the modal keeps the source box.
$artifact = array('entrypoint' => 'website/index.html', 'files' => $files($wired));
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$result = $compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$markup = implode('', array_column($plan['pages'] ?? array(), 'canonical_block_markup'));
foreach ( $plan['template_parts'] ?? array() as $part ) {
    $markup .= (string) ($part['content'] ?? $part['canonical_block_markup'] ?? '');
}
$css = json_encode($result, JSON_UNESCAPED_SLASHES) ?: '';
$assert(1 === preg_match('/:is\(#panel,\.blocks-engine-editor-anchor-panel\)/', stripslashes($css)), 'the id rule keeps the anchor class alternative', '');
$assert(str_contains(stripslashes($css), 'data-blocks-engine-placement="source"'), 'the dialog is marked as placing itself', '');

$style = (string) ((new CapturedDialogBlockGenerator())->definition('test/captured-dialog')['assets']['style.css'] ?? '');
$assert(
    str_contains($style, ':where(dialog:not([data-blocks-engine-presentation])[data-blocks-engine-placement="source"]){inset:auto;max-width:none;max-height:none;margin:0;padding:0;border-width:0}'),
    'a self-placed modal drops the user agent box geometry under zero-specificity rules',
    $style
);

if ( 0 < $failures ) {
    fwrite(STDERR, "Captured dialog source panel box: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Captured dialog source panel box passed: {$passes} assertions\n";
