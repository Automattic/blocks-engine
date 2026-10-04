<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredSelectBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredTextareaBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$saveMarkup = static function (object $generator, array $attrs): string {
    $definition = $generator->definition('custom');
    $script     = (string) ($definition['assets']['index.js'] ?? '');
    $runner     = <<<'JS'
const vm = require( 'node:vm' );
let save;
const RawHTML = function RawHTML() {};
const context = {
    window: { wp: {
        blocks: { registerBlockType: ( name, settings ) => { save = settings.save; } },
        blockEditor: { RichText: {}, InspectorControls: {} },
        components: { PanelBody: {}, TextControl: {}, TextareaControl: {}, SelectControl: {}, ToggleControl: {} },
        element: {
            createElement: ( type, props, ...children ) => type === RawHTML ? ( children[0] ?? '' ) : { type, props, children },
            RawHTML,
            Fragment: 'Fragment'
        }
    } }
};

vm.runInNewContext( Buffer.from( process.argv[1], 'base64' ).toString(), context );
process.stdout.write( String( save( { attributes: JSON.parse( process.argv[2] ) } ) ) );
JS;
    $saved = shell_exec(
        'node -e ' . escapeshellarg($runner) . ' '
        . escapeshellarg(base64_encode($script)) . ' '
        . escapeshellarg(json_encode($attrs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
    );

    return is_string($saved) ? $saved : '';
};

$invokeLabelEditor = static function (object $generator, array $attrs, string $surface, string $value): string {
    $script = (string) $generator->definition('custom')['assets']['index.js'];
    $runner = <<<'JS'
const vm = require( 'node:vm' );
let settings;
const TextControl = function TextControl() {};
const RichText = function RichText() {};
const RawHTML = function RawHTML() {};
const context = { window: { wp: {
    blocks: { registerBlockType: ( name, value ) => { settings = value; } },
    richText: { create: ( { html } ) => ( { text: String( html ).replace( /<[^>]*>/g, '' ) } ) },
    blockEditor: { RichText, InspectorControls: function() {} },
    components: { PanelBody: function() {}, TextControl, TextareaControl: function() {}, SelectControl: function() {}, ToggleControl: function() {} },
    element: { createElement: ( type, props, ...children ) => type === RawHTML ? ( children[0] || '' ) : ( { type, props: props || {}, children } ), RawHTML, Fragment: 'Fragment' }
} } };
vm.runInNewContext( Buffer.from( process.argv[1], 'base64' ).toString(), context );
const attrs = JSON.parse( process.argv[2] );
function walk( node ) { if ( ! node || 'object' !== typeof node ) return null; if ( node.type === ( 'rich' === process.argv[3] ? RichText : TextControl ) && ( 'rich' === process.argv[3] || 'Label' === node.props.label ) ) return node; for ( const child of node.children || [] ) { const found = walk( child ); if ( found ) return found; } return null; }
const tree = settings.edit( { attributes: attrs, setAttributes: next => Object.assign( attrs, next ) } );
const target = walk( tree );
if ( ! target || ! target.props.onChange ) throw new Error( 'Expected rendered label editor callback' );
target.props.onChange( process.argv[4] );
const reloadedAttrs = JSON.parse( JSON.stringify( attrs ) );
process.stdout.write( JSON.stringify( { html: settings.save( { attributes: reloadedAttrs } ), attrs: reloadedAttrs } ) );
JS;
    $result = shell_exec(
        'node -e ' . escapeshellarg($runner) . ' '
        . escapeshellarg(base64_encode($script)) . ' '
        . escapeshellarg(json_encode($attrs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . ' '
        . escapeshellarg($surface) . ' ' . escapeshellarg($value)
    );
    return is_string($result) ? $result : '';
};

$generator = new AuthoredSelectBlockGenerator();
$attrs     = array(
    'className'      => 'mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/30',
    'required'       => true,
    'label'          => 'Assistance required',
    'labelClassName' => 'block',
    'options'        => array(
        array( 'label' => 'Select a program', 'value' => '', 'selected' => true, 'disabled' => true ),
        array( 'label' => 'Food & housing', 'value' => 'food' ),
    ),
);
$serialized = $generator->markup($attrs);
$assert(str_contains($serialized, '<select class="' . $attrs['className'] . '" required>'), 'PHP markup emits required on the select');
$assert(str_contains($serialized, '<option value="" selected disabled>Select a program</option>'), 'PHP markup keeps the selected disabled placeholder option');
$assert(str_contains($serialized, '<label class="block">Assistance required<select'), 'PHP markup keeps the wrapping label');
$assert(str_contains($serialized, '<option value="food">Food &amp; housing</option>'), 'PHP markup escapes option labels');
$assert($serialized === $saveMarkup($generator, $attrs), 'authored-select save() round-trips the serialized markup Gutenberg validates');

$selectedAttrs = array(
    'selectedValue' => 'ca',
    'selectedLabel' => 'Canada',
    'options' => array(
        array( 'label' => 'Select country', 'value' => '', 'disabled' => true ),
        array( 'label' => 'Canada', 'value' => 'ca', 'selected' => true ),
    ),
);
$selectedMarkup = $generator->markup($selectedAttrs);
$assert(str_contains($selectedMarkup, '<option value="ca" selected>Canada</option>'), 'native select markup preserves the selected option value and label');
$assert($selectedMarkup === $saveMarkup($generator, $selectedAttrs), 'selected-value companion metadata does not invalidate the saved native select');

$listboxResult = ( new HtmlTransformer() )->transform(
    '<form><button type="button" data-dla-listbox-trigger="country" aria-expanded="false">Canada</button>'
    . '<div hidden data-dla-listbox-panel="country" role="listbox">'
    . '<div role="option" aria-selected="false">Afghanistan</div>'
    . '<div role="option" aria-selected="true">Canada</div>'
    . '</div></form>'
)->toArray();
$listboxMarkup = (string) ( $listboxResult['serialized_blocks'] ?? '' );
$assert(str_contains($listboxMarkup, 'authored-select'), 'captured listbox trigger uses the editable native select companion');
$assert(str_contains($listboxMarkup, '<option value="Canada" selected>Canada</option>'), 'captured listbox materialization carries the selected value and label');
$assert(!str_contains($listboxMarkup, 'core/details'), 'captured listbox is not degraded to a disclosure block');

$disabledAttrs = array(
    'className' => 'authored-select',
    'disabled'  => true,
    'options'   => array( array( 'label' => 'Only', 'value' => 'only' ) ),
);
$assert(( new AuthoredSelectBlockGenerator() )->markup($disabledAttrs) === $saveMarkup($generator, $disabledAttrs), 'authored-select save() round-trips disabled');

$inputAttrs = array( 'type' => 'email', 'className' => 'authored-input', 'required' => true, 'disabled' => true );
$input      = new AuthoredInputBlockGenerator();
$assert($input->markup($inputAttrs) === $saveMarkup($input, $inputAttrs), 'authored-input save() already round-trips required and disabled');

$textareaAttrs = array( 'className' => 'authored-textarea', 'required' => true, 'value' => 'Notes' );
$textarea      = new AuthoredTextareaBlockGenerator();
$assert($textarea->markup($textareaAttrs) === $saveMarkup($textarea, $textareaAttrs), 'authored-textarea save() already round-trips required');

$buttonAttrs = array( 'type' => 'submit', 'text' => 'Send', 'disabled' => true );
$button      = new AuthoredButtonBlockGenerator();
$assert($button->markup($buttonAttrs) === $saveMarkup($button, $buttonAttrs), 'authored-button save() already round-trips disabled');
$stateAttrs = array( 'type' => 'submit', 'ariaLabel' => 'Play Marquee', 'ariaPressed' => 'true', 'className' => 'kgbJ1s', 'iconSvg' => '<svg viewBox="0 0 18 18" width="18" height="18"><path d="M1,1"></path></svg>', 'sourceAttributes' => array( array( 'name' => 'data-dla-responsive-source', 'value' => 'comp-m5b146s3:button:1' ) ) );
$assert($button->markup($stateAttrs) === $saveMarkup($button, $stateAttrs) && str_contains($button->markup($stateAttrs), 'aria-pressed="true"') && !str_contains($button->markup($stateAttrs), 'onclick'), 'authored-button save() round-trips a static svg state button Gutenberg validates');

foreach ( array( $input, $generator, $textarea ) as $fieldGenerator ) {
    $initial = array( 'label' => 'Old label', 'labelMarkup' => '<span>(required)</span>', 'type' => 'text', 'options' => array( array( 'label' => 'Choice', 'value' => 'choice' ) ) );
    $editedInspector = json_decode($invokeLabelEditor($fieldGenerator, $initial, 'inspector', 'New inspector label'), true, 512, JSON_THROW_ON_ERROR);
    $assert(str_contains($editedInspector['html'], 'New inspector label') && ! str_contains($editedInspector['html'], 'Old label'), get_class($fieldGenerator) . ' Inspector Label callback survives save/reload');
    $assert('New inspector label' === ($editedInspector['attrs']['label'] ?? '') && '' === ($editedInspector['attrs']['labelMarkup'] ?? null), get_class($fieldGenerator) . ' Inspector edit resets stale rich markup');

    $richMarkup = '<strong>Richly edited (required)</strong>';
    $editedRichText = json_decode($invokeLabelEditor($fieldGenerator, $initial, 'rich', $richMarkup), true, 512, JSON_THROW_ON_ERROR);
    $assert(str_contains($editedRichText['html'], $richMarkup) && ! str_contains($editedRichText['html'], 'Old label'), get_class($fieldGenerator) . ' RichText callback survives save/reload with authored markup');
    $assert('Richly edited (required)' === ($editedRichText['attrs']['label'] ?? '') && $richMarkup === ($editedRichText['attrs']['labelMarkup'] ?? ''), get_class($fieldGenerator) . ' RichText edit synchronizes accessible plain label');
}

fwrite(STDOUT, "Authored select block round-trip tests passed\n");
