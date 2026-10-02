<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$script = <<<'JS'
document.querySelectorAll('[data-dla-dialog-trigger]').forEach(function (trigger) {
  trigger.addEventListener('click', function () {
    var panel = document.getElementById(trigger.getAttribute('aria-controls'));
    if (!panel) return;
    var open = trigger.getAttribute('aria-expanded') === 'true';
    panel.hidden = open;
    trigger.setAttribute('aria-expanded', open ? 'false' : 'true');
    if (open) trigger.focus();
    else {
      var link = panel.querySelector('a');
      if (link) link.focus();
    }
  });
});
document.querySelector('[data-dla-dialog-panel]').addEventListener('keydown', function () {});
JS;

$button = static function (string $id, string $panel, string $inner): string {
    return '<button type="button" id="' . $id . '" class="menu-toggle" style="width:44px;height:44px" aria-label="Open menu" data-dla-dialog-trigger="' . $panel . '" aria-controls="' . $panel . '" aria-expanded="false" aria-haspopup="menu" onclick="bad()" data-action="open"><' . $inner . '</button>';
};
$panel = static function (string $id): string {
    return '<div class="dla-dialog" role="dialog" hidden id="' . $id . '" data-dla-dialog-panel="' . $id . '" aria-label="Mobile navigation"><nav><a href="#intro">Home</a><a href="#contact">Contact</a></nav></div>';
};
$document = static function (string $body, string $scriptSrc) : string {
    return '<!doctype html><html><head><title>Menu</title></head><body><header>' . $body . '</header><main><p>Home</p></main><script src="' . $scriptSrc . '"></script></body></html>';
};

$desktop = $document(
    $button('menu-toggle', 'menu-panel', 'i class="icon-bars"></i>')
    . $panel('menu-panel')
    . '<button type="button" id="text-toggle" aria-label="Open the menu" data-dla-dialog-trigger="text-panel" aria-controls="text-panel" aria-expanded="false">Open menu</button>'
    . '<div hidden id="text-panel" data-dla-dialog-panel="text-panel"><a href="#contact">Contact</a></div>'
    . '<button type="button" id="mixed-toggle" aria-label="Open menu" data-dla-dialog-trigger="mixed-panel" aria-controls="mixed-panel"><span class="label">Open</span><i class="icon-bars"></i></button>'
    . '<div hidden id="mixed-panel" data-dla-dialog-panel="mixed-panel"><a href="#intro">Home</a></div>',
    'menu.js'
);
$mobile = $document(
    $button('menu-toggle-mobile', 'menu-panel-mobile', 'i class="icon-bars"></i>')
    . $panel('menu-panel-mobile'),
    '../../menu.js'
);

$minimal = ( new HtmlTransformer() )->transform(
    $button('menu-toggle', 'menu-panel', 'i class="icon-bars"></i>') . $panel('menu-panel'),
    array( 'runtime_dom_selectors' => array( '[data-dla-dialog-trigger]', '[data-dla-dialog-panel]', '#menu-toggle' ) )
)->toArray();
$minimalMarkup = (string) ($minimal['serialized_blocks'] ?? '');
$assert(str_contains($minimalMarkup, '<button type="button" id="menu-toggle"'), 'minimal runtime transform keeps the native button root');
$assert(str_contains($minimalMarkup, 'data-dla-dialog-trigger="menu-panel"') && str_contains($minimalMarkup, 'aria-controls="menu-panel"') && str_contains($minimalMarkup, 'aria-expanded="false"') && str_contains($minimalMarkup, 'aria-haspopup="menu"'), 'minimal runtime transform keeps the dialog bindings');
$assert(str_contains($minimalMarkup, '<i class="icon-bars"></i>') && str_contains($minimalMarkup, 'aria-label="Open menu"') && str_contains($minimalMarkup, 'class="menu-toggle"') && str_contains($minimalMarkup, 'style="width:44px;height:44px"'), 'minimal runtime transform keeps the icon, accessible name, and CSS box');
$assert(str_contains($minimalMarkup, 'wp:custom/authored-button') && ! str_contains($minimalMarkup, '<details') && ! preg_match('/<button[^>]*\sonclick=/', $minimalMarkup), 'runtime trigger is an authored button, not details or an event handler');
$assert(array() === ($minimal['fallbacks'] ?? array()) && 'pass' === ($minimal['source_reports']['wp_block_validity']['status'] ?? ''), 'minimal runtime button adds no fallback and stays Gutenberg-valid');
$split = ( new HtmlTransformer() )->transform(
    '<button type="button" id="split-label" data-dla-dialog-trigger="split" aria-controls="split" aria-label="Buy now">Buy <span class="label">now</span><i class="icon-bars"></i></button>',
    array( 'runtime_dom_selectors' => array( '[data-dla-dialog-trigger]' ) )
)->toArray();
$splitMarkup = (string) ($split['serialized_blocks'] ?? '');
$splitButton = ( new DOMDocument() );
$splitButton->loadHTML($splitMarkup, LIBXML_NOERROR | LIBXML_NOWARNING);
$splitControl = $splitButton->getElementsByTagName('button')->item(0);
$splitSpan = $splitControl instanceof DOMElement ? $splitControl->getElementsByTagName('span')->item(0) : null;
$assert($splitControl instanceof DOMElement && $splitSpan instanceof DOMElement && 'Buy now' === trim(preg_replace('/\s+/', ' ', $splitControl->textContent ?? '') ?? '') && 'now' === trim($splitSpan->textContent ?? '') && 1 === $splitControl->getElementsByTagName('span')->length && str_contains($splitMarkup, 'Buy <span class="label">now</span><i class="icon-bars"></i>'), 'a text node plus a wrapped fragment is not duplicated or dropped: ' . $splitMarkup);
$splitBlock = $split['blocks'][0]['attrs'] ?? array();
$assert(! isset($splitBlock['text']) && 'Buy ' === ($splitBlock['contentParts'][0]['text'] ?? '') && 'now' === ($splitBlock['contentParts'][1]['text'] ?? ''), 'each text fragment keeps its own editable literal');
$nested = ( new HtmlTransformer() )->transform(
    '<button type="button" id="nested-label" data-dla-dialog-trigger="nested" aria-controls="nested">Go <span class="label">Buy <i class="icon-bars"></i></span></button>',
    array( 'runtime_dom_selectors' => array( '[data-dla-dialog-trigger]' ) )
)->toArray();
$nestedMarkup = (string) ($nested['serialized_blocks'] ?? '');
$assert(str_contains($nestedMarkup, 'Go <span class="label">Buy <i class="icon-bars"></i></span>') && 1 === substr_count($nestedMarkup, '>Buy <'), 'a wrapper keeps its text and icon without duplicating the label');

$artifact = array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'site' => array( 'name' => 'Dialog Site', 'slug' => 'dialog-site' ),
    'entrypoint' => 'website/index.html',
    'document_variants' => array( array(
        'source_path' => 'website/index.html',
        'variants' => array( array(
            'id' => 'mobile',
            'source_path' => 'website/.variants/mobile/index.html',
            'media' => '(max-width: 768px)',
        ) ),
    ) ),
    'files' => array(
        array( 'path' => 'website/index.html', 'content' => $desktop ),
        array( 'path' => 'website/.variants/mobile/index.html', 'role' => 'document_variant', 'content' => $mobile ),
        array( 'path' => 'website/menu.js', 'content' => $script ),
    ),
);
$compiled = ( new ArtifactCompiler() )->compile($artifact)->toArray();
$compiledMarkup = (string) ($compiled['serialized_blocks'] ?? '');
$runtimeFailures = array_values(array_filter($compiled['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'runtime_dependency_contract_failed' === ($diagnostic['code'] ?? '')));
$planDiagnostics = $compiled['source_reports']['wordpress_site_plan_diagnostics'] ?? array();
$scriptGateFailures = array_values(array_filter(is_array($planDiagnostics) ? $planDiagnostics : array(), static fn (array $diagnostic): bool => str_starts_with((string) ($diagnostic['code'] ?? ''), 'wordpress_site_plan_script_')));
$assert(array() === $runtimeFailures, 'compiler script target gate passes for the dialog trigger: ' . json_encode($runtimeFailures));
$assert(is_array($compiled['source_reports']['wordpress_site_plan'] ?? null), 'WordPress site plan is composed for the dialog fixture');
$assert(array() === $scriptGateFailures, 'WordPress site plan accepts the dialog runtime script: ' . json_encode($scriptGateFailures));
$assert(str_contains($compiledMarkup, 'site-document-variant-default') && str_contains($compiledMarkup, 'site-document-variant-mobile'), 'responsive document variants both survive compilation');
$assert(str_contains($compiledMarkup, 'id="menu-toggle"') && str_contains($compiledMarkup, 'id="menu-toggle-mobile"') && substr_count($compiledMarkup, 'data-dla-dialog-trigger=') >= 4, 'desktop and mobile variants keep icon, text, and mixed triggers');
$assert(str_contains($compiledMarkup, 'id="text-toggle"') && str_contains($compiledMarkup, '>Open menu</button>') && str_contains($compiledMarkup, '<span class="label">Open</span><i class="icon-bars"></i>'), 'text and icon descendants stay inside native buttons');
$triggerFallbacks = array_values(array_filter($compiled['fallbacks'] ?? array(), static fn (array $fallback): bool => str_contains(json_encode($fallback) ?: '', 'menu-toggle') || str_contains(json_encode($fallback) ?: '', 'data-dla-dialog-trigger')));
$assert(! str_contains($compiledMarkup, '<details') && ! str_contains($compiledMarkup, 'onclick=') && array() === $triggerFallbacks, 'compiled dialog triggers are not details, event code, or fallback losses');
$assert('pass' === (( new BlockValidityValidator() )->validateBlocks($compiled['blocks'] ?? array())['status'] ?? ''), 'compiled dialog markup remains Gutenberg-valid');

$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($compiled['assets'] ?? array(), 'is_array')));
$assert(str_contains($css, '@media (max-width: 768px){.site-document-variant-default{display:none!important}}') && str_contains($css, '@media not all and (max-width: 768px){.site-document-variant-mobile{display:none!important}}'), 'desktop keeps the mobile dialog variant hidden and mobile keeps the desktop variant hidden');

$generator = new AuthoredButtonBlockGenerator();
$definition = $generator->definition('custom');
$editor = (string) ($definition['assets']['index.js'] ?? '');
$assert(str_contains($editor, "label: 'Label'") && str_contains($editor, "label: 'Accessible name'"), 'button text and aria-label are editable block controls');
$saveMarkup = static function (array $attrs) use ($editor): string {
    $runner = <<<'JS'
const vm = require('node:vm');
let save;
const RawHTML = function RawHTML() {};
vm.runInNewContext(Buffer.from(process.argv[1], 'base64').toString(), { window: { wp: {
  blocks: { registerBlockType: (name, settings) => { save = settings.save; } },
  blockEditor: { InspectorControls: function InspectorControls() {} },
  components: { PanelBody: function PanelBody() {}, TextControl: function TextControl() {}, SelectControl: function SelectControl() {}, ToggleControl: function ToggleControl() {} },
  element: { createElement: (type, props, ...children) => type === RawHTML ? (children[0] ?? '') : { type, props, children }, RawHTML, Fragment: 'Fragment' }
} } });
process.stdout.write(String(save({ attributes: JSON.parse(process.argv[2]) })));
JS;
    $saved = shell_exec('node -e ' . escapeshellarg($runner) . ' ' . escapeshellarg(base64_encode($editor)) . ' ' . escapeshellarg(json_encode($attrs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

    return is_string($saved) ? $saved : '';
};
$oldAttrs = array( 'type' => 'submit', 'text' => 'Send', 'disabled' => true );
$assert('<button type="submit" disabled>Send</button>' === $generator->markup($oldAttrs) && $generator->markup($oldAttrs) === $saveMarkup($oldAttrs), 'old generated button attributes keep their save shape');
$runtimeAttrs = array(
    'type' => 'button',
    'id' => 'menu-toggle',
    'ariaLabel' => 'Open menu',
    'className' => 'menu-toggle',
    'style' => 'width:44px;height:44px',
    'labelWrappers' => array( array( 'tagName' => 'i', 'attributes' => array( 'class' => 'icon-bars' ) ) ),
    'sourceAttributes' => array( 'aria-controls' => 'menu-panel', 'aria-expanded' => 'false', 'aria-haspopup' => 'menu', 'data-dla-dialog-trigger' => 'menu-panel', 'onclick' => 'bad()', 'data-action' => 'open' ),
);
$runtimeSaved = $saveMarkup($runtimeAttrs);
$assert($generator->markup($runtimeAttrs) === $runtimeSaved && str_contains($runtimeSaved, 'data-dla-dialog-trigger="menu-panel"') && ! str_contains($runtimeSaved, 'onclick') && ! str_contains($runtimeSaved, 'data-action'), 'editor save matches PHP markup and drops inline event attributes');
$editedParts = $splitBlock['contentParts'] ?? array();
$editedParts[0]['text'] = 'Get ';
$editedParts[1]['text'] = 'this';
$editedAttrs = array( 'type' => 'button', 'contentParts' => $editedParts );
$editedSaved = $saveMarkup($editedAttrs);
$assert($generator->markup($editedAttrs) === $editedSaved && str_contains($editedSaved, 'Get <span class="label">this</span><i class="icon-bars"></i>') && ! str_contains($editedSaved, '>now<'), 'each text fragment stays editable through save without dropping the icon');
$parsed = ( new Runtime() )->parseBlocks(( new Runtime() )->serializeBlocks(array( array(
    'blockName' => 'custom/authored-button',
    'attrs' => $runtimeAttrs,
    'innerBlocks' => array(),
    'innerHTML' => $generator->markup($runtimeAttrs),
    'innerContent' => array( $generator->markup($runtimeAttrs) ),
) )));
$assert('custom/authored-button' === ($parsed[0]['blockName'] ?? '') && 'Open menu' === ($parsed[0]['attrs']['ariaLabel'] ?? ''), 'companion comment parses and reloads the editable accessible name');
$unchanged = ( new HtmlTransformer() )->transform('<form><button type="submit" class="send" style="letter-spacing:0.1em">Join</button></form>')->toArray();
$assert(str_contains((string) ($unchanged['serialized_blocks'] ?? ''), '<button type="submit" class="send" style="letter-spacing:0.1em">Join</button>') && ! str_contains((string) ($unchanged['serialized_blocks'] ?? ''), 'sourceAttributes'), 'existing form submit controls keep their default companion save');

$helper = <<<'JS'
(function(){function triggers(){return document.querySelectorAll('[data-dla-dialog-trigger]');}function panel(trigger){var id=trigger.getAttribute('aria-controls');return id?document.getElementById(id):null;}function apply(trigger){var target=panel(trigger);if(!target)return;target.hidden=trigger.getAttribute('aria-expanded')!=='true';}function toggle(trigger){var open=trigger.getAttribute('aria-expanded')==='true';trigger.setAttribute('aria-expanded',open?'false':'true');apply(trigger);if(open)trigger.focus();}document.addEventListener('click',function(event){var trigger=event.target.closest&&event.target.closest('[data-dla-dialog-trigger]');if(!trigger||!panel(trigger))return;event.preventDefault();toggle(trigger);});triggers().forEach(apply);})();
JS;
$triggerButton = '<button type="button" id="navOpenButton" class="lg:hidden" aria-label="Open menu" data-dla-dialog-trigger="dla-dialog-0" aria-controls="dla-dialog-0" aria-expanded="false" aria-haspopup="menu"><i class="icon-bars"></i></button>';
$inlinePage = '<!doctype html><html><head><style>.data-liberation-mobile-document{display:none!important}@media(max-width:991px){.data-liberation-desktop-document{display:none!important}.data-liberation-mobile-document{display:contents!important}}[data-dla-dialog-panel][hidden],[data-dla-dialog-close][hidden]{display:none!important}[data-dla-dialog-panel]:not(.dla-dropdown):not([hidden]){display:block;position:fixed;inset:0}[data-dla-dialog-panel].dla-dropdown:not([hidden]){display:block;position:absolute;top:100%;left:0;right:0}</style><script data-dla-disclosure-runtime="true">' . $helper . '</script></head><body>'
    . '<div class="data-liberation-desktop-document"><header>' . $triggerButton . '<div class="dla-dropdown" hidden id="dla-dialog-0" data-dla-dialog-panel="dla-dialog-0"><nav><a href="#intro">Home</a></nav></div></header></div>'
    . '<div class="data-liberation-mobile-document"><header>' . str_replace('dla-dialog-0', 'dla-dialog-1', $triggerButton) . '<div class="dla-dropdown" hidden id="dla-dialog-1" data-dla-dialog-panel="dla-dialog-1"><nav><a href="#contact">Contact</a></nav></div></header></div>'
    . '</body></html>';
$derived = ( new ArtifactCompiler() )->runtimeContextForSource($inlinePage, 'website/index.html', array( array( 'path' => 'website/index.html', 'content' => $inlinePage ) ));
$assert(in_array('[data-dla-dialog-trigger]', $derived['runtime_dom_selectors'] ?? array(), true), 'an inline named query helper is a runtime selector without a separate script file: ' . json_encode($derived['runtime_dom_selectors'] ?? array()));
$inlineCompiled = ( new ArtifactCompiler() )->compile(array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'site' => array( 'name' => 'Dialog Site', 'slug' => 'dialog-site' ),
    'entrypoint' => 'website/index.html',
    'files' => array( 'website/index.html' => $inlinePage ),
))->toArray();
$inlineMarkup = (string) ($inlineCompiled['serialized_blocks'] ?? '');
$inlineContractFailures = array_values(array_filter($inlineCompiled['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'runtime_dependency_contract_failed' === ($diagnostic['code'] ?? '')));
$assert(array() === $inlineContractFailures && str_contains($inlineMarkup, 'data-dla-dialog-trigger="dla-dialog-0"') && str_contains($inlineMarkup, '<button'), 'inline helper compilation keeps the accessible trigger and passes the script gate: ' . json_encode($inlineContractFailures) . ' ' . substr($inlineMarkup, 0, 400));
$assert(is_array($inlineCompiled['source_reports']['wordpress_site_plan'] ?? null), 'inline helper compilation reaches a WordPress site plan');
$inlinePlan = $inlineCompiled['source_reports']['wordpress_site_plan'] ?? array();
$inlineThemeScripts = array_merge(...array_map(static fn (array $page): array => $page['document_metadata']['scripts'] ?? array(), $inlinePlan['pages'] ?? array()));
$inlinePreserved = $inlineCompiled['source_reports']['companion_plugin_payload']['preserved_js'] ?? array();
$assert(1 === count($inlineThemeScripts) && array() === $inlinePreserved, 'theme-declared dialog runtime is not enqueued again by the companion island');
$inlineCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($inlineCompiled['assets'] ?? array(), 'is_array')));
$assert(str_contains($inlineCss, '[data-dla-dialog-panel][hidden]') && str_contains($inlineCss, '[data-dla-dialog-panel].dla-dropdown:not([hidden])'), 'stateful data predicates stay source selectors because the wrapper retains them: ' . $inlineCss);
$assert(str_contains($inlineMarkup, 'data-dla-dialog-panel="dla-dialog-1"') && str_contains($inlineMarkup, 'hidden=""') && str_contains($inlineMarkup, '/layout-shell'), 'layout-shell save keeps the live hidden and data attributes core/group cannot carry: ' . substr($inlineMarkup, 0, 700));
$panelShell = null;
$findShell = static function (array $blocks) use (&$findShell, &$panelShell): void {
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        $wrappers = $block['attrs']['wrappers'] ?? array();
        foreach ( is_array($wrappers) ? $wrappers : array() as $wrapper ) {
            if ( true === ($wrapper['attributes']['hidden'] ?? null) && 'dla-dialog-1' === ($wrapper['attributes']['data-dla-dialog-panel'] ?? null) ) {
                $panelShell = $block;
            }
        }
        $findShell(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array());
    }
};
$findShell($inlineCompiled['blocks'] ?? array());
$assert(is_array($panelShell) && str_ends_with((string) ($panelShell['blockName'] ?? ''), '/layout-shell'), 'the dialog panel is an editable layout-shell, not a group that drops script state');
$assert('pass' === (( new BlockValidityValidator() )->validateBlocks($inlineCompiled['blocks'] ?? array())['status'] ?? ''), 'layout-shell dialog markup remains Gutenberg-valid');
$distinctScripts = ( new ArtifactCompiler() )->compile(array(
    'files' => array( 'index.html' => '<main><p id="one">One</p><p id="two">Two</p></main><script>document.getElementById("one").dataset.ready="a";</script><script>document.getElementById("two").dataset.ready="b";</script>' ),
))->toArray();
$distinctPlan = $distinctScripts['source_reports']['wordpress_site_plan'] ?? array();
$distinctThemeScripts = array();
foreach ( $distinctPlan['pages'] ?? array() as $page ) {
    foreach ( $page['document_metadata']['scripts'] ?? array() as $scriptRow ) {
        $distinctThemeScripts[] = $scriptRow;
    }
}
$assert(2 === count($distinctThemeScripts) && array() === ($distinctScripts['source_reports']['companion_plugin_payload']['preserved_js'] ?? array()), 'distinct inline script occurrences stay separate theme declarations');
$feedback = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        'index.html' => '<main><div class="form-success js-form-success" role="status" aria-live="polite"></div></main>',
        'website/nav.js' => 'document.querySelector(".form-success"); document.querySelector(".form-error");',
    ),
))->toArray();
$feedbackMarkup = (string) ($feedback['serialized_blocks'] ?? '');
$assert(str_contains($feedbackMarkup, 'form-success') && str_contains($feedbackMarkup, 'role="status"') && str_contains($feedbackMarkup, 'aria-live="polite"') && ! str_contains($feedbackMarkup, 'js-form-success'), 'a queried status wrapper keeps role and live semantics without an untargeted behavior-hook class: ' . substr($feedbackMarkup, 0, 500));

file_put_contents(sys_get_temp_dir() . '/runtime-dialog-trigger-button.json', json_encode(array(
    'html' => $compiledMarkup,
    'css' => $css,
    'script' => $script,
), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Runtime dialog trigger button passed\n";
