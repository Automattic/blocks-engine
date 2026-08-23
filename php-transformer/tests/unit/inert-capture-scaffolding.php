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
$assert(str_contains((string) ($referencedStore['serialized_blocks'] ?? ''), 'id="mark"'), 'referenced SVG store remains available');
$materializedSvg = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($referencedStore['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? ''))));
$assert(str_contains($materializedSvg, 'id="mark"') && str_contains($materializedSvg, 'href="#mark"'), 'referenced SVG symbols remain available to materialized images');

$visibleIframe = $transform('<main><iframe title="HubSpot form" src="https://forms.hsforms.com/widget" width="600" height="400"></iframe></main>');
$iframeFallbacks = array_values(array_filter($visibleIframe['fallbacks'] ?? array(), static fn (array $fallback): bool => 'iframe' === ($fallback['tag'] ?? '')));
$assert(1 === count($iframeFallbacks), 'visible HubSpot iframe remains a bounded external embed');
$assert('https://forms.hsforms.com/widget' === ($iframeFallbacks[0]['attributes']['src'] ?? ''), 'visible HubSpot iframe source is retained');

$hiddenSourcedIframe = $transform('<main><iframe title="third-party embed" style="display:none" src="https://example.test/embed"></iframe></main>');
$assert(1 === count($hiddenSourcedIframe['fallbacks'] ?? array()), 'hidden sourced iframe remains preserved');

echo "OK: inert capture scaffolding passed ({$assertions} assertions)\n";
