<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $label) use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        throw new RuntimeException($label);
    }
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();

$inertIframe = $transform('<main><iframe title="archetype" style="display:none;visibility:hidden"></iframe><p>Visible copy</p></main>');
$assert(array() === ($inertIframe['fallbacks'] ?? array()), 'hidden sourceless iframe emits no fallback');
$assert(array() === ($inertIframe['source_reports']['runtime_islands'] ?? array()), 'hidden sourceless iframe emits no runtime island');
$assert(str_contains((string) ($inertIframe['serialized_blocks'] ?? ''), 'Visible copy') && ! str_contains((string) ($inertIframe['serialized_blocks'] ?? ''), 'archetype'), 'hidden sourceless iframe emits no block');

$inertLiveRegions = $transform('<main><capture-shell><p aria-live="assertive" id="captured-live-region" role="alert" style="border:0;clip:rect(0 0 0 0);height:1px;margin:-1px;overflow:hidden;padding:0;position:absolute;white-space:nowrap;width:1px;word-wrap:normal"></p></capture-shell><next-route-announcer><p aria-live="assertive" id="__next-route-announcer__" role="alert" style="border:0;clip:rect(0 0 0 0);height:1px;margin:-1px;overflow:hidden;padding:0;position:absolute;white-space:nowrap;width:1px;word-wrap:normal"></p></next-route-announcer><h1>Visible heading</h1></main>');
$assert(array() === ($inertLiveRegions['fallbacks'] ?? array()), 'empty visually clipped custom-element live regions emit no fallback regardless of wrapper name');
$assert(str_contains((string) ($inertLiveRegions['serialized_blocks'] ?? ''), 'Visible heading') && ! str_contains((string) ($inertLiveRegions['serialized_blocks'] ?? ''), 'capture-shell') && ! str_contains((string) ($inertLiveRegions['serialized_blocks'] ?? ''), 'next-route-announcer'), 'inert live-region scaffolding emits no editable block');

$liveRegionWithContent = $transform('<main><capture-shell><p aria-live="assertive" role="alert" style="clip:rect(0 0 0 0);height:1px;overflow:hidden;position:absolute;width:1px">Page changed</p></capture-shell></main>');
$assert(str_contains((string) ($liveRegionWithContent['serialized_blocks'] ?? ''), 'Page changed'), 'live regions with captured content remain editable');

$namedWrapper = $transform('<main><capture-shell aria-label="Notifications"><p aria-live="polite" role="status" style="clip-path:inset(50%);height:1px;overflow:hidden;position:absolute;width:1px"></p></capture-shell></main>');
$assert('capture-shell' === ($namedWrapper['fallbacks'][0]['tag'] ?? ''), 'semantically named custom wrappers remain explicit fallbacks');

$customElement = $transform('<main><custom-widget aria-label="Custom control"></custom-widget></main>');
$assert('custom-widget' === ($customElement['fallbacks'][0]['tag'] ?? ''), 'unrelated custom elements remain explicit fallbacks');

$unreferencedStore = $transform('<main><svg data-dom-store style="display:none"><defs><symbol id="unused"><path d="M0 0h1v1z"/></symbol></defs></svg><p>Visible copy</p></main>');
$assert(array() === ($unreferencedStore['fallbacks'] ?? array()), 'hidden unreferenced SVG store emits no fallback');
$assert(! str_contains((string) ($unreferencedStore['serialized_blocks'] ?? ''), 'unused'), 'hidden unreferenced SVG store emits no raw HTML');

$referencedStore = $transform('<main><svg data-dom-store style="display:none"><defs><symbol id="mark"><path d="M0 0h1v1z"/></symbol></defs></svg><svg viewBox="0 0 1 1"><use href="#mark"/></svg></main>');
$assert(! str_contains((string) ($referencedStore['serialized_blocks'] ?? ''), '<!-- wp:html') && str_contains((string) ($referencedStore['serialized_blocks'] ?? ''), 'assets/materialized-svg/'), 'referenced SVG store hydrates a typed image instead of remaining raw HTML');
$materializedSvg = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($referencedStore['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? ''))));
$assert(str_contains($materializedSvg, 'id="mark"') && str_contains($materializedSvg, 'href="#mark"'), 'referenced SVG symbols remain available to materialized images');

$conditionalStyles = '<style>@media (max-width:600px){svg{display:block}}</style>';
$conditionalStore = $transform($conditionalStyles . '<main><svg data-dom-store style="display:none"><defs id="conditional-unused"></defs></svg><p>Visible copy</p></main>');
$assert(! str_contains((string) ($conditionalStore['serialized_blocks'] ?? ''), 'conditional-unused'), 'unreferenced data DOM store stays inert under conditional SVG styles');

$conditionalReferencedStore = $transform($conditionalStyles . '<main><svg data-dom-store style="display:none"><defs><symbol id="conditional-mark"><path d="M0 0h1v1z"/></symbol></defs></svg><svg viewBox="0 0 1 1"><use href="#conditional-mark"/></svg></main>');
$conditionalReferencedSvg = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($conditionalReferencedStore['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? ''))));
$assert(str_contains($conditionalReferencedSvg, 'conditional-mark') && ! str_contains((string) ($conditionalReferencedStore['serialized_blocks'] ?? ''), '<!-- wp:html'), 'referenced data DOM store hydrates its consumer image under conditional SVG styles');

$conditionalOrdinaryStore = $transform($conditionalStyles . '<main><svg style="display:none"><defs><symbol id="ordinary-store"><path d="M0 0h1v1z"/></symbol></defs></svg><p>Visible copy</p></main>');
$conditionalOrdinarySvg = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($conditionalOrdinaryStore['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? ''))));
$assert(str_contains((string) ($conditionalOrdinaryStore['serialized_blocks'] ?? ''), 'assets/materialized-svg/') && str_contains($conditionalOrdinarySvg, 'ordinary-store'), 'ordinary hidden SVG retains conditional visibility safeguards');

$capturedCollection = $transform('<main><fluid-columns-repeater><div><svg viewBox="0 0 1 1"><defs><link rel="stylesheet" href="/assets/icon.css"></defs><path d="M0 0h1v1z"/></svg><p>One</p></div><div><p>Two</p></div></fluid-columns-repeater></main>');
$generatedRender = implode("\n", array_map(static fn (array $block): string => (string) ($block['render'] ?? ''), $capturedCollection['source_reports']['generated_blocks'] ?? array()));
$assert('' !== $generatedRender && ! str_contains($generatedRender, '<link'), 'generated custom blocks strip captured stylesheet links from nested SVG markup');

$visibleIframe = $transform('<main><iframe title="HubSpot form" src="https://forms.hsforms.com/widget" width="600" height="400"></iframe></main>');
$visibleIframeBlock = $visibleIframe['blocks'][0] ?? array();
$assert('custom/visual-iframe' === ($visibleIframeBlock['blockName'] ?? ''), 'visible HubSpot iframe becomes a bounded companion embed');
$assert('https://forms.hsforms.com/widget' === ($visibleIframeBlock['attrs']['src'] ?? ''), 'visible HubSpot iframe source is retained structurally');
$assert(array() === ($visibleIframe['fallbacks'] ?? array()), 'visible bounded iframe does not add a fallback');

$hiddenSourcedIframe = $transform('<main><iframe title="third-party embed" style="display:none" src="https://example.test/embed"></iframe></main>');
$assert(1 === count($hiddenSourcedIframe['fallbacks'] ?? array()), 'hidden sourced iframe remains preserved');
$assert(! str_contains((string) ($hiddenSourcedIframe['serialized_blocks'] ?? ''), '<iframe'), 'hidden sourced iframe remains a suppressed runtime island');

// An empty container inside a CSS-owned layout used to lower to an empty
// `core/group`; enough of them fail the editability policy and abort a whole
// site. Only a container that nothing renders and nothing addresses is dropped.
$authoredLayout = '<style>.panel{display:flex;flex-direction:column;justify-content:space-between;align-items:stretch;padding:4px}</style>';
$emptyContainer = static fn (string $styles, string $markup): array => $transform($authoredLayout . $styles . '<main><div class="panel"><p>Runnable example</p>' . $markup . '</div></main>');

$inertSeparator = $emptyContainer('', '<div class="widget-separator"></div>');
$assert(! str_contains((string) ($inertSeparator['serialized_blocks'] ?? ''), 'widget-separator'), 'an inert empty container emits no block');
$assert(array() === ($inertSeparator['fallbacks'] ?? array()), 'an inert empty container emits no fallback');

$paintedRule = $emptyContainer('<style>.rule{height:2px;background:#333}</style>', '<div class="rule"></div>');
$assert(str_contains((string) ($paintedRule['serialized_blocks'] ?? ''), 'rule'), 'an empty container the author paints and sizes still emits a block');

$generatedContent = $emptyContainer('<style>.glyph::before{content:"\2726"}</style>', '<div class="glyph"></div>');
$assert(str_contains((string) ($generatedContent['serialized_blocks'] ?? ''), 'glyph blocks-engine-empty-visual-group'), 'an empty container whose pseudo-element draws generated content stays a recognized empty visual');

$anchored = $emptyContainer('', '<div id="section-anchor"></div>');
$assert(str_contains((string) ($anchored['serialized_blocks'] ?? ''), 'section-anchor'), 'an empty container carrying an anchor id still emits a block');

$responsiveSpacer = $emptyContainer('<style>@media (min-width:600px){.gap{height:40px}}</style>', '<div class="gap"></div>');
$assert(str_contains((string) ($responsiveSpacer['serialized_blocks'] ?? ''), 'gap'), 'an empty container sized only at another viewport still emits a block');

$animated = $emptyContainer('<style>.pulse{animation:pulse 2s linear infinite}</style>', '<div class="pulse"></div>');
$assert(str_contains((string) ($animated['serialized_blocks'] ?? ''), 'pulse'), 'an empty container the author animates still emits a block');

$gridCell = $transform('<style>.grid{display:grid;grid-template-columns:1fr 1fr}</style><main><div class="grid"><p>One</p><div class="cell"></div><p>Two</p></div></main>');
$assert(str_contains((string) ($gridCell['serialized_blocks'] ?? ''), 'cell'), 'an empty grid item owns a track, so it still emits a block');

$gappedItem = $transform('<style>.row{display:flex;gap:16px}</style><main><div class="row"><p>One</p><div class="cell"></div><p>Two</p></div></main>');
$assert(str_contains((string) ($gappedItem['serialized_blocks'] ?? ''), 'cell'), 'an empty flex item between authored gaps still emits a block');

$distributedItem = $transform('<style>.row{display:flex;justify-content:space-between}</style><main><div class="row"><p>One</p><p>Two</p><div class="cell"></div></div></main>');
$assert(str_contains((string) ($distributedItem['serialized_blocks'] ?? ''), 'cell'), 'an empty flex item sharing a definite main axis still emits a block');

$labelled = $emptyContainer('', '<div class="status-slot" aria-label="Upload progress"></div>');
$assert(str_contains((string) ($labelled['serialized_blocks'] ?? ''), 'status-slot'), 'an empty container with an accessible name still emits a block');

echo "OK: inert capture scaffolding passed ({$assertions} assertions)\n";
