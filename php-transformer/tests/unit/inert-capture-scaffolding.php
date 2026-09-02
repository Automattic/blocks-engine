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

echo "OK: inert capture scaffolding passed ({$assertions} assertions)\n";
