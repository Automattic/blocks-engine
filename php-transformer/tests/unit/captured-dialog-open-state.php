<?php
declare(strict_types=1);

require_once __DIR__ . '/../fixtures/captured-components.php';

/**
 * A projected modal opens in its source open state, not its rest state.
 *
 * The dialog is the captured open panel. Its source panel is often hidden or
 * slid away at rest and shown by a rule that only matches once script marks an
 * ancestor:
 *
 *   #panel{display:none;transform:translateX(-100%)}
 *   body.nav-open #panel{display:block;transform:none}
 *
 * Review of #2744 found three gaps:
 * - id rules never matched the dialog during analysis (its id is generated;
 *   the source id rides on its editor anchor class), so placement "source"
 *   missed a panel positioned only by id rules;
 * - a rest rule that hides the panel (by id or by class) made the dialog
 *   hidden content, and the dialog block was dropped;
 * - without a captured ancestor state the rest rule won on the open dialog.
 * State rules are trusted only when they agree, or when they name the opened
 * ancestor class the capture recorded; only an out-of-view translate counts
 * as a rest offset.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

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

/** The rendered `<dialog>` opening tag of the compiled dialog block, or ''. */
$dialog = static function (string $css, string $triggerAttributes = ''): string {
    $panel = '<div id="panel" class="panel" role="dialog"><nav class="menu"><a href="/a">Alpha</a><a href="/b">Beta</a></nav></div>';
    $wired = '<div class="data-blocks-engine-capture-dialog" role="dialog" aria-modal="true" hidden id="wired" data-blocks-engine-capture-dialog-panel="wired">' . $panel . '</div>';
    $html = '<html><head><style>' . $css . '</style></head><body><header><a href="/">Home</a>'
        . '<button id="menu" type="button" aria-label="Open menu" aria-haspopup="dialog" aria-expanded="false" aria-controls="wired"'
        . ' data-blocks-engine-capture-dialog-trigger="wired" data-blocks-engine-capture-disclosure-label="Open menu"' . $triggerAttributes . '><span></span></button>'
        . $wired . '</header><main><p>Body</p></main></body></html>';
    $state = array(
        'status' => 'captured',
        'trigger' => array('selector' => '#menu', 'tag' => 'button', 'label' => 'Open menu', 'ariaHaspopup' => 'dialog', 'dataBindings' => array()),
        'dialog' => array('selector' => '#panel', 'tag' => 'div', 'role' => 'dialog', 'presentation' => 'modal', 'html' => $panel, 'htmlBytes' => strlen($panel), 'htmlTruncated' => false),
    );
    $artifact = array('entrypoint' => 'website/index.html', 'files' => array(
        array('path' => 'website/index.html', 'content' => $html),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))), JSON_UNESCAPED_SLASHES)),
        array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => captured_components(array($state))))), JSON_UNESCAPED_SLASHES)),
    ));
    $compiler = new ArtifactCompiler();
    $shared = $compiler->prepareShared($artifact);
    $result = $compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared)))->toArray();
    $all = stripslashes((string) json_encode($result, JSON_UNESCAPED_SLASHES));
    preg_match_all('/<dialog [^>]*>/', $all, $matches);
    foreach ( $matches[0] as $tag ) {
        // The block's rendered dialog, not the projected source HTML.
        if ( ! str_contains($tag, 'data-blocks-engine-captured-dialog=') ) {
            return $tag;
        }
    }

    return '';
};

$byId = $dialog('#panel{position:fixed;top:0;left:10px;width:200px;display:none;transform:translateX(-100%)}body.nav-open #panel{display:block;transform:none}');
$assert('' !== $byId, 'a panel hidden at rest by an id rule still becomes a dialog block', $byId);
$assert(str_contains($byId, 'style="display:block;transform:none"'), 'the open dialog carries the state rule values over the id rest rule', $byId);
$assert(str_contains($byId, 'data-blocks-engine-placement="source"'), 'a panel positioned only by id rules places itself', $byId);

$byClass = $dialog('.panel{position:fixed;display:none;opacity:0}body.nav-open .panel{display:flex;opacity:1}');
$assert(str_contains($byClass, 'style="display:flex;opacity:1"'), 'the open dialog carries the state rule values over the class rest rule', $byClass);

$positioned = $dialog('#panel{position:fixed;top:0;left:10px;width:200px}');
$assert(str_contains($positioned, 'data-blocks-engine-placement="source"') && ! str_contains($positioned, 'style='), 'an id-positioned panel with no rest state places itself and gets no open values', $positioned);

$centred = $dialog('.panel{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%)}body.nav-open .panel{transform:translate(-50%,-50%) scale(1)}');
$assert(! str_contains($centred, 'transform:'), 'a transform the open state keeps is not treated as a rest offset', $centred);

$centredNoJs = $dialog('#panel{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%)}.no-js #panel{transform:none}');
$assert(! str_contains($centredNoJs, 'style='), 'a centring translate is in view, so another state rule does not un-centre the modal', $centredNoJs);

$theme = $dialog('#panel{opacity:0;position:fixed}body.nav-open #panel{opacity:1}.dark-theme #panel{opacity:.5}');
$assert('' !== $theme && ! str_contains($theme, 'opacity:'), 'state rules that disagree carry nothing', $theme);

$noJs = $dialog('#panel{display:none;position:fixed}body.nav-open #panel{display:flex}.no-js #panel{display:block}');
$assert('' !== $noJs && ! str_contains($noJs, 'display:'), 'state rules that disagree on display carry nothing', $noJs);

$recordedState = ' data-blocks-engine-capture-dialog-ancestor-state="' . htmlspecialchars((string) json_encode(array(array('tag' => 'body', 'closed' => array('class' => ''), 'opened' => array('class' => 'nav-open')))), ENT_QUOTES) . '"';
$recorded = $dialog('#panel{display:none;position:fixed}body.nav-open #panel{display:flex}.no-js #panel{display:block}', $recordedState);
$assert(str_contains($recorded, 'style="display:flex"'), 'the state rule naming the recorded opened class wins over other state rules', $recorded);

$unreachable = $dialog('#menu{display:none}#panel{display:none;position:fixed}body.nav-open #panel{display:block}');
$assert('' === $unreachable, 'a dialog whose trigger never shows is still dropped', $unreachable);

$phoneTrigger = $dialog('#menu{display:none}@media (max-width:767px){#menu{display:block}}#panel{display:none;position:fixed}body.nav-open #panel{display:block}');
$assert(str_contains($phoneTrigger, 'style="display:block"'), 'a dialog whose trigger shows at some viewport is kept', $phoneTrigger);

if ( 0 < $failures ) {
    fwrite(STDERR, "Captured dialog open state: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Captured dialog open state passed: {$passes} assertions\n";
