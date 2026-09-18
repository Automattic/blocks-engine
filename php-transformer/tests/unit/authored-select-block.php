<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredSelectBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredTextareaBlockGenerator;

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

fwrite(STDOUT, "Authored select block round-trip tests passed\n");
