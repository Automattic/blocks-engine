<?php
declare(strict_types=1);

/**
 * A functional custom element is preserved only when captured script content
 * proves it defines that tag. A script URL, an unrelated script, or no script
 * must not silence the unsupported gate.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\CustomElementRuntimeDependency;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$transform = static fn (string $html, array $options = array()): array => ( new HtmlTransformer() )->transform($html, $options)->toArray();
$codes = static fn (array $result): array => array_column($result['fallbacks'] ?? array(), 'diagnostic_code');
$island = static function (array $result): array {
    foreach ( $result['source_reports']['runtime_islands'] ?? array() as $candidate ) {
        if ( is_array($candidate) && 'custom_element' === ($candidate['kind'] ?? '') ) {
            return $candidate;
        }
    }

    return array();
};

$proven = $transform('<main><script>customElements.define("checkout-widget", class extends HTMLElement { connectedCallback(){ this.textContent = "Pay"; } });</script><checkout-widget offer-id="public-offer" publishable-key="pk_test_public"></checkout-widget></main>');
$provenIsland = $island($proven);
$provenScript = $provenIsland['required_scripts'][0] ?? array();
$assert(str_contains((string) ($proven['serialized_blocks'] ?? ''), 'checkout-widget'), 'proven defining script preserves the custom element');
$assert(str_contains((string) ($proven['serialized_blocks'] ?? ''), 'public-offer'), 'proven custom element keeps its public configuration attribute');
$assert(! in_array('html_unsupported_element', $codes($proven), true), 'proven custom element does not emit html_unsupported_element');
$assert('custom_element' === ($provenIsland['kind'] ?? ''), 'proven custom element is a runtime island');
$assert(1 === count($provenIsland['required_scripts'] ?? array()) && str_contains((string) ($provenScript['script_body'] ?? ''), 'customElements.define') && str_contains((string) ($provenScript['script_body'] ?? ''), 'checkout-widget'), 'runtime island carries only the proven defining script');
$assert(! str_contains((string) ($proven['serialized_blocks'] ?? ''), '<!-- wp:html'), 'proven custom element is not a raw core/html fallback');

$declaredExternal = $transform('<main><script async src="https://widgets.example.test/checkout-widget.js"></script><checkout-widget offer-id="public-offer"></checkout-widget></main>');
$declaredExternalIsland = $island($declaredExternal);
$declaredExternalScript = $declaredExternalIsland['required_scripts'][0] ?? array();
$assert(str_contains((string) ($declaredExternal['serialized_blocks'] ?? ''), 'checkout-widget') && str_contains((string) ($declaredExternal['serialized_blocks'] ?? ''), 'public-offer'), 'an adjacent declared external dependency preserves its configured element');
$assert('https://widgets.example.test/checkout-widget.js' === ($declaredExternalScript['attributes']['src'] ?? '') && 'external' === ($declaredExternalScript['script_source_kind'] ?? ''), 'external runtime dependency keeps its source URL and loading kind');
$assert(! in_array('html_unsupported_element', $codes($declaredExternal), true), 'adjacent declared external dependency does not emit an unsupported-element fallback');

$materialized = $transform(
    '<main><script src="widgets/checkout-widget.js" defer></script><checkout-widget offer-id="public-offer"></checkout-widget></main>',
    array(
        'runtime_script_metadata' => array(array(
            'path' => 'widgets/checkout-widget.js',
            'selector' => 'script:nth-of-type(1)',
            'attributes' => array( 'src' => 'widgets/checkout-widget.js', 'defer' => 'defer' ),
            'script_source_kind' => 'external',
        )),
        'runtime_projection_script_assets' => array(array(
            'path' => 'widgets/checkout-widget.js',
            'content' => "customElements.define('checkout-widget', class extends HTMLElement {});",
        )),
    )
);
$materializedScript = $island($materialized)['required_scripts'][0] ?? array();
$assert(str_contains((string) ($materialized['serialized_blocks'] ?? ''), 'checkout-widget') && str_contains((string) ($materialized['serialized_blocks'] ?? ''), 'public-offer'), 'materialized script provenance preserves the custom element');
$assert(1 === count($island($materialized)['required_scripts'] ?? array()) && 'widgets/checkout-widget.js' === ($materializedScript['attributes']['src'] ?? '') && str_contains((string) ($materializedScript['script_body'] ?? ''), 'customElements.define'), 'materialized provenance is the required script, not every page script');
$assert(! in_array('html_unsupported_element', $codes($materialized), true), 'materialized provenance does not emit html_unsupported_element');

$unproven = $transform('<main><script src="https://cdn.example.test/v3/buy-button.js"></script><p>Unrelated content</p><checkout-widget offer-id="public-offer" publishable-key="pk_test_public"></checkout-widget></main>');
$unprovenFallback = array_values(array_filter($unproven['fallbacks'] ?? array(), static fn (array $fallback): bool => 'checkout-widget' === ($fallback['tag'] ?? '')));
$assert(1 === count($unprovenFallback), 'an unproven script URL does not drop the finding');
$assert(CustomElementRuntimeDependency::UNPROVEN_CODE === ($unprovenFallback[0]['diagnostic_code'] ?? ''), 'an unproven script URL stays an explicit missing-functional-content finding');
$assert(CustomElementRuntimeDependency::UNPROVEN_REASON === ($unprovenFallback[0]['reason'] ?? ''), 'unproven finding reason is missing_functional_content');
$assert('unsupported_loss' === ($unprovenFallback[0]['conversion_classification'] ?? ''), 'unproven finding does not silence the unsupported gate');
$assert(0 < FallbackDiagnostic::countableFallbackCount($unproven['fallbacks'] ?? array()), 'unproven functional content remains in the countable fallback total');
$assert(! str_contains((string) ($unproven['serialized_blocks'] ?? ''), 'checkout-widget'), 'an unproven script URL does not preserve the custom element');
$assert(array() === $island($unproven), 'an unproven script URL does not create a custom-element runtime island');

$unrelated = $transform('<main><script>customElements.define("other-widget", class extends HTMLElement {});</script><checkout-widget offer-id="public-offer"></checkout-widget></main>');
$assert(! str_contains((string) ($unrelated['serialized_blocks'] ?? ''), 'checkout-widget') && array() === $island($unrelated), 'a script that defines a different element is not proof');

$unsafe = $transform('<main><script>customElements.define("checkout-widget", class extends HTMLElement {});</script><checkout-widget offer-id="public-offer" onclick="steal()"></checkout-widget></main>');
$assert(in_array('html_unsupported_element', $codes($unsafe), true) && ! str_contains((string) ($unsafe['serialized_blocks'] ?? ''), 'checkout-widget'), 'an event handler keeps the unsupported gate even when a defining script exists');

$inert = $transform('<main><custom-widget aria-label="Custom control"></custom-widget></main>');
$assert('html_unsupported_element' === ($inert['fallbacks'][0]['diagnostic_code'] ?? ''), 'a custom element without configuration or a defining script remains an unsupported element');

if ( 0 !== $failures ) {
    fwrite(STDERR, "{$failures} custom-element runtime dependency assertion(s) failed.\n");
    exit(1);
}

echo "Custom element runtime dependency tests passed ({$passes} assertions)\n";
