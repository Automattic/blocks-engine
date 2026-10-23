<?php
declare(strict_types=1);

/**
 * At rest, a captured dialog's editor rendering takes no canvas space and
 * carries no label element; its inner blocks stay mounted so List View still
 * lists the dialog. Selecting the dialog, or anything inside it, shows the
 * inner blocks for editing. List View and the toolbar name the dialog by its
 * `ariaLabel` (BE #3167).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CapturedDialogBlockGenerator;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    }
};

$definition = ( new CapturedDialogBlockGenerator() )->definition('site/captured-dialog');
$editor = (string) ( $definition['assets']['index.js'] ?? '' );
$assert(in_array('wp-data', $definition['script_dependencies']['index.js'] ?? array(), true), 'the editor script depends on wp-data');

$harness = <<<'JS'
const editor = require( 'fs' ).readFileSync( 0, 'utf8' );
const state = { blockSelected: false, innerSelected: false, calls: [] };
let settings = null;
const InnerBlocks = function InnerBlocks() { return null; };
InnerBlocks.Content = function InnerBlocksContent() { return null; };
global.window = { wp: {
    blocks: { registerBlockType: ( name, value ) => { settings = value; } },
    element: { createElement: ( type, props, ...children ) => ( { type, props: props || {}, children } ) },
    blockEditor: { InnerBlocks, useBlockProps: ( props ) => Object.assign( { 'data-block': 'wrapper' }, props || {} ) },
    data: { useSelect: ( selector ) => selector( ( store ) => ( {
        isBlockSelected: ( id ) => { state.calls.push( [ store, 'isBlockSelected', id ] ); return state.blockSelected; },
        hasSelectedInnerBlock: ( id, deep ) => { state.calls.push( [ store, 'hasSelectedInnerBlock', id, deep ] ); return state.innerSelected && true === deep; },
    } ) ) },
} };
eval( editor );
function walk( node, visit, hidden ) {
    if ( ! node || 'object' !== typeof node ) return;
    const style = node.props.style || {};
    const nowHidden = hidden || true === node.props.hidden || 'none' === style.display;
    visit( node, nowHidden );
    node.children.flat().forEach( ( child ) => walk( child, visit, nowHidden ) );
}
function render( blockSelected, innerSelected ) {
    state.blockSelected = blockSelected; state.innerSelected = innerSelected; state.calls = [];
    const tree = settings.edit( { clientId: 'dialog-1', isSelected: blockSelected, attributes: { ariaLabel: 'Main menu' }, setAttributes() {} } );
    const out = { strong: false, inner: 0, visibleInner: 0, wrapperClass: tree && tree.props.className, calls: state.calls };
    walk( tree, ( node, hidden ) => {
        if ( 'strong' === node.type ) out.strong = true;
        if ( InnerBlocks === node.type ) { out.inner++; if ( ! hidden ) out.visibleInner++; }
    }, false );
    return out;
}
const label = settings.__experimentalLabel ? settings.__experimentalLabel( { ariaLabel: 'Main menu' }, { context: 'list-view' } ) : null;
const fallback = settings.__experimentalLabel ? settings.__experimentalLabel( { ariaLabel: '' }, { context: 'list-view' } ) : 'missing';
process.stdout.write( JSON.stringify( { rest: render( false, false ), selected: render( true, false ), descendant: render( false, true ), label, fallback: fallback || null } ) );
JS;

$process = proc_open(array('node', '-e', $harness), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
$assert(is_resource($process), 'node runs the generated editor script');
if ( is_resource($process) ) {
    fwrite($pipes[0], $editor);
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $assert(0 === $exit, 'the generated editor script executes', $stderr);
    $result = json_decode($stdout, true);
    $assert(is_array($result), 'the harness reports its renders', $stdout . $stderr);
    if ( is_array($result) ) {
        $rest = $result['rest'];
        $assert(false === $rest['strong'], 'at rest no label element renders', json_encode($rest));
        $assert(1 === $rest['inner'] && 0 === $rest['visibleInner'], 'at rest the inner blocks stay mounted but hidden', json_encode($rest));
        $assert(str_contains((string) $rest['wrapperClass'], 'blocks-engine-captured-dialog-editor'), 'the block wrapper keeps the editor class', json_encode($rest));
        $assert(in_array(array('core/block-editor', 'hasSelectedInnerBlock', 'dialog-1', true), $rest['calls'], true), 'descendant selection is read deeply from core/block-editor', json_encode($rest['calls']));
        $assert(1 === $result['selected']['visibleInner'] && false === $result['selected']['strong'], 'a selected dialog shows its inner blocks', json_encode($result['selected']));
        $assert(1 === $result['descendant']['visibleInner'], 'a dialog with a selected descendant shows its inner blocks', json_encode($result['descendant']));
        $assert('Main menu' === $result['label'], 'List View and the toolbar name the dialog by its ariaLabel', json_encode($result['label']));
        $assert(null === $result['fallback'], 'without an ariaLabel the editor falls back to the block title', json_encode($result['fallback']));
    }
}

if ( $failures > 0 ) {
    exit(1);
}
echo "captured-dialog-editor-at-rest: ok\n";
