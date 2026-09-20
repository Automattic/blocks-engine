<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CopyToClipboardBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$findCopy = static function (array $blocks) use (&$findCopy): ?array {
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        if ( is_string($block['blockName'] ?? null) && str_ends_with((string) $block['blockName'], '/copy-to-clipboard') ) {
            return $block;
        }
        $nested = $findCopy(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array());
        if ( null !== $nested ) {
            return $nested;
        }
    }

    return null;
};

$saveMarkup = static function (array $attrs): string {
    $definition = ( new CopyToClipboardBlockGenerator() )->definition('custom');
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

$contract = ( new HtmlTransformer() )->transform(
    '<main><button type="button" data-clipboard-text="Hello from data">Copy</button></main>'
)->toArray();
$contractBlock = $findCopy($contract['blocks'] ?? array());
$assert(null !== $contractBlock, 'data-clipboard-text uses the copy-to-clipboard companion');
$assert('custom/copy-to-clipboard' === ($contractBlock['blockName'] ?? null), 'the default generated namespace is used');
$assert('Hello from data' === ($contractBlock['attrs']['copyText'] ?? null) && 'Copy' === ($contractBlock['attrs']['label'] ?? null), 'the copied payload and visible label stay editable attributes');
$contractMarkup = (string) ($contract['serialized_blocks'] ?? '');
$assert(str_contains($contractMarkup, '<button type="button"') && str_contains($contractMarkup, 'data-blocks-engine-copy="true"') && str_contains($contractMarkup, 'aria-live="polite"'), 'saved markup keeps a real button, the copy contract, and a live region');
$assert('pass' === ($contract['source_reports']['wp_block_validity']['status'] ?? null), 'copy-to-clipboard serialization is editor-valid');

$target = ( new HtmlTransformer() )->transform(
    '<main><p id="payload">Targeted source text</p><button type="button" data-clipboard-target="#payload">Copy</button></main>'
)->toArray();
$targetBlock = $findCopy($target['blocks'] ?? array());
$assert('Targeted source text' === ($targetBlock['attrs']['copyText'] ?? null), 'data-clipboard-target resolves an id selector');

$handler = ( new HtmlTransformer() )->transform(
    '<main><button type="button" onclick="navigator.clipboard.writeText(\'From handler\')">Copy</button></main>'
)->toArray();
$handlerBlock = $findCopy($handler['blocks'] ?? array());
$assert('From handler' === ($handlerBlock['attrs']['copyText'] ?? null), 'a clipboard.writeText handler is copy evidence and supplies the payload');

$english = ( new HtmlTransformer() )->transform(
    '<article><p>Card body for English copy.</p><button type="button">Copy</button></article>'
)->toArray();
$englishBlock = $findCopy($english['blocks'] ?? array());
$assert(null !== $englishBlock && 'Card body for English copy.' === ($englishBlock['attrs']['copyText'] ?? null), 'an accessible name Copy resolves the nearest card text');

$german = ( new HtmlTransformer() )->transform(
    '<article><p>Kartentext zum Kopieren.</p><button type="button">Kopieren</button></article>'
)->toArray();
$germanBlock = $findCopy($german['blocks'] ?? array());
$assert(null !== $germanBlock && 'Kartentext zum Kopieren.' === ($germanBlock['attrs']['copyText'] ?? null), 'an accessible name Kopieren is generic copy intent, not a site string');

$iconOnly = ( new HtmlTransformer() )->transform(
    '<article><p>Icon only payload.</p><button type="button" aria-label="Copy" style="padding:.375rem .625rem;font-size:10px;letter-spacing:.1em"><img src="icon.svg" alt="" width="12" height="12"></button></article>'
)->toArray();
$iconBlock = $findCopy($iconOnly['blocks'] ?? array());
$assert(null !== $iconBlock, 'an icon-only control with a copy accessible name is recognized');
$assert('Copy' === ($iconBlock['attrs']['ariaLabel'] ?? null) && '' === ($iconBlock['attrs']['label'] ?? ''), 'icon-only controls keep an accessible name and no invented visible label');
$assert(str_contains((string) ($iconBlock['attrs']['iconHtml'] ?? ''), 'src="icon.svg"'), 'the source icon is preserved');
$assert(str_contains((string) ($iconBlock['attrs']['style'] ?? ''), 'font-size:10px') && str_contains((string) ($iconBlock['attrs']['style'] ?? ''), 'letter-spacing:.1em'), 'authored padding, type size, and tracking stay on the control');
$iconMarkup = (string) ($iconOnly['serialized_blocks'] ?? '');
$assert(str_contains($iconMarkup, 'aria-label="Copy"') && str_contains($iconMarkup, 'src="icon.svg"'), 'icon-only markup exposes an accessible name and the icon');

$cards = ( new HtmlTransformer() )->transform(
    '<section>'
    . '<article><p>First card text.</p><button type="button" style="padding:.375rem .625rem;font-size:10px;letter-spacing:.1em"><img src="copy.svg" alt="" class="icon"> COPIER</button></article>'
    . '<article><p>Second card text.</p><button type="button"><img src="copy.svg" alt=""> COPIER</button></article>'
    . '</section>'
)->toArray();
$first = $findCopy($cards['blocks'] ?? array());
$assert(null !== $first && 'First card text.' === ($first['attrs']['copyText'] ?? null) && 'COPIER' === ($first['attrs']['label'] ?? null), 'a copy verb as the accessible name is recognized generically and copies that card, not the sibling');
$copyCount = substr_count((string) ($cards['serialized_blocks'] ?? ''), 'data-blocks-engine-copy="true"');
$assert(2 === $copyCount, 'each card copy control materializes as the companion rather than an inert authored-button');
$assert(! str_contains((string) ($cards['serialized_blocks'] ?? ''), 'authored-button'), 'copy controls are not lowered onto authored-button');

$lucideOnly = ( new HtmlTransformer() )->transform(
    '<main><button type="button" class="lucide lucide-copy w-3 h-3">Share</button></main>'
)->toArray();
$assert(null === $findCopy($lucideOnly['blocks'] ?? array()), 'an icon class is not copy evidence');

$copyright = ( new HtmlTransformer() )->transform(
    '<main><button type="button">Copyright</button></main>'
)->toArray();
$assert(null === $findCopy($copyright['blocks'] ?? array()), 'copyright is not treated as copy intent');

$ordinary = ( new HtmlTransformer() )->transform(
    '<main><button type="button">Submit</button></main>'
)->toArray();
$assert(null === $findCopy($ordinary['blocks'] ?? array()), 'ordinary buttons stay on native lowering');

$unresolved = ( new HtmlTransformer() )->transform(
    '<main><button type="button">Copy</button></main>'
)->toArray();
$assert(null === $findCopy($unresolved['blocks'] ?? array()), 'copy intent without a resolvable target does not emit a dead copy block');
$loss = array_values(array_filter(
    is_array($unresolved['fallbacks'] ?? null) ? $unresolved['fallbacks'] : array(),
    static fn (mixed $fallback): bool => is_array($fallback) && 'interactive_control_behavior_lost' === ($fallback['diagnostic_code'] ?? '')
));
$assert(array() !== $loss && 'interactive_behavior_loss' === ($loss[0]['loss_class'] ?? null), 'an unresolved copy target records the canonical interactive_behavior_loss class');

$generator = new CopyToClipboardBlockGenerator();
$attrs = array(
    'label' => 'Copy',
    'copyText' => 'Hello & friends',
    'style' => 'font-size:10px;letter-spacing:.1em',
    'copiedAnnouncement' => 'Copied',
);
$assert($generator->markup($attrs) === $saveMarkup($attrs), 'save() round-trips the serialized markup Gutenberg validates');
$unsafe = $generator->markup(array( 'label' => 'Copy', 'copyText' => 'Safe', 'iconHtml' => '<img src="javascript:alert(1)" onload="alert(1)">' ));
$assert(! str_contains($unsafe, 'javascript:') && ! str_contains($unsafe, 'onload='), 'PHP serialization rejects hostile icon markup');

$definition = $contract['source_reports']['generated_blocks'][0] ?? array();
$view = (string) ($definition['view_js'] ?? '');
$editor = (string) ($definition['assets']['index.js'] ?? '');
$assert('custom/copy-to-clipboard' === ($definition['block_json']['name'] ?? null) && str_contains($editor, "registerBlockType( 'custom/copy-to-clipboard'") && 'file:./view.js' === ($definition['block_json']['viewScript'] ?? null) && isset($definition['assets']['style.css']), 'the companion declares the generated block name and ships editor, view, and style assets');
$assert(str_contains($view, 'navigator.clipboard.writeText') && str_contains($view, 'data-blocks-engine-copy') && str_contains($view, 'aria-live') === false, 'the view script copies with the Clipboard API and does not pull in a front-end framework');
$assert(str_contains($editor, 'Copied text') && str_contains($editor, 'Label'), 'the editor exposes editable label and copied-text controls');
$payload = ( new CompanionPluginPayload() )->fromBlockTypes(array(), array(), array(), array( $definition ));
$assert('copy-to-clipboard' === ($payload['blocks'][0]['name'] ?? null) && str_contains((string) ($payload['blocks'][0]['view_js'] ?? $payload['blocks'][0]['assets']['view.js'] ?? ''), 'clipboard.writeText'), 'the companion plugin payload preserves the view script');

$consumer = ( new HtmlTransformer() )->transform(
    '<article><p>Namespaced payload.</p><button type="button" data-copy-text="Namespaced payload.">Copy</button></article>',
    array( 'generated_block_namespace' => 'acme-site' )
)->toArray();
$consumerBlock = $findCopy($consumer['blocks'] ?? array());
$consumerDefinition = $consumer['source_reports']['generated_blocks'][0] ?? array();
$assert('acme-site/copy-to-clipboard' === ($consumerBlock['blockName'] ?? null) && 'acme-site/copy-to-clipboard' === ($consumerDefinition['block_json']['name'] ?? null), 'a consumer-supplied namespace resolves the block name');
$assert('acme-site/copy-to-clipboard' === ( ( new Runtime() )->parseBlocks(( new Runtime() )->serializeBlocks(array( $consumerBlock )))[0]['blockName'] ?? null ), 'the companion persists through parse and serialize');

fwrite(STDOUT, "Copy to clipboard companion tests passed\n");
