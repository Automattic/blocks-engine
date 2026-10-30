<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/**
 * A linked inline SVG inside a classed phrasing wrapper keeps its artwork.
 *
 * The `brand` class routes the wrapper through the logo-label paragraph path.
 * There the label materialized the source SVG into its native RichText image
 * object and then unwrapped that object as an "unsupported" element, leaving
 * an empty `<a> </a>`: the artwork was silently dropped, while the same
 * markup without the wrapper class converted to a linked `core/image`. Now
 * the materialized image object is label content, an image-only label
 * declines so the generic element path emits the linked `core/image`, and a
 * refused materialization is recorded as fallback evidence instead of being
 * stripped silently.
 */

$svg = '<svg width="160" height="36" viewBox="0 0 160 36"><path fill="#242424" d="M0 0h160v36H0z"/></svg>';
$icon = '<svg width="16" height="16" viewBox="0 0 16 16"><path fill="#242424" d="M0 0h16v16H0z"/></svg>';
$failures = array();

$findBlock = static function (array $blocks, string $name) use (&$findBlock): ?array {
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            return $block;
        }
        $nested = $findBlock($block['innerBlocks'] ?? array(), $name);
        if ( null !== $nested ) {
            return $nested;
        }
    }
    return null;
};
$svgAssets = static fn (array $result): int => count(array_filter(
    $result['assets'] ?? array(),
    static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? '')
));

$check = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

// 1. The reduced case: a classed wrapper around a linked inline SVG keeps the
// artwork as a linked core/image, exactly like the unclassed wrapper.
foreach ( array(
    'classed wrapper' => '<div class="brand"><a href="https://example.com/">' . $svg . '</a></div>',
    'unclassed wrapper' => '<div><a href="https://example.com/">' . $svg . '</a></div>',
) as $label => $source ) {
    $result = ( new HtmlTransformer() )->transform($source)->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $image = $findBlock($result['blocks'] ?? array(), 'core/image');
    $check(1 !== preg_match('/<a[^>]*>\s*<\/a>/', $markup), $label . ' does not emit an empty link: ' . $markup);
    $check(null !== $image, $label . ' converts to a core/image: ' . $markup);
    $check(1 === preg_match('@^assets/materialized-svg/inline-svg-[a-f0-9]{16}\.svg$@', (string) ($image['attrs']['url'] ?? '')), $label . ' references the materialized SVG asset: ' . $markup);
    $check('https://example.com/' === ($image['attrs']['href'] ?? '') && 'custom' === ($image['attrs']['linkDestination'] ?? ''), $label . ' keeps the source link on the image: ' . $markup);
    $check(1 === $svgAssets($result), $label . ' materializes exactly one SVG asset.');
    $check('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), $label . ' emits valid block markup: ' . $markup);
    $check(array() === ($result['fallbacks'] ?? array()), $label . ' records no fallback diagnostics: ' . $markup);
}

// 2. The classed wrapper keeps the brand class on the converted group.
$classed = ( new HtmlTransformer() )->transform('<div class="brand"><a href="https://example.com/">' . $svg . '</a></div>')->toArray();
$group = $findBlock($classed['blocks'] ?? array(), 'core/group');
$check(null !== $group && str_contains((string) ($group['attrs']['className'] ?? ''), 'brand'), 'the classed wrapper keeps its class on the group: ' . (string) ($classed['serialized_blocks'] ?? ''));

// 3. A logo lockup mixing the icon with its wordmark keeps the artwork beside
// the text inside one editable paragraph.
$mixed = ( new HtmlTransformer() )->transform('<div class="brand"><a href="https://example.com/">' . $icon . ' Acme</a></div>')->toArray();
$mixedMarkup = (string) ($mixed['serialized_blocks'] ?? '');
$mixedParagraph = $findBlock($mixed['blocks'] ?? array(), 'core/paragraph');
$mixedContent = (string) ($mixedParagraph['innerHTML'] ?? '');
$check(1 !== preg_match('/<a[^>]*>\s*<\/a>/', $mixedMarkup), 'the mixed lockup does not emit an empty link: ' . $mixedMarkup);
$check(null !== $mixedParagraph && str_contains($mixedContent, 'Acme') && str_contains($mixedContent, '<a href="https://example.com/"><img src="assets/materialized-svg/'), 'the mixed lockup keeps the materialized icon inside the link beside its wordmark: ' . $mixedContent);
$check('pass' === ($mixed['source_reports']['wp_block_validity']['status'] ?? ''), 'the mixed lockup emits valid block markup: ' . $mixedMarkup);
$check(array() === ($mixed['fallbacks'] ?? array()), 'the mixed lockup records no fallback diagnostics: ' . $mixedMarkup);

// 4. A logo SVG the materializer refuses is not stripped silently: the label
// keeps its wordmark and the dropped artwork records the inline SVG fallback.
$refused = ( new HtmlTransformer() )->transform('<div class="brand"><a href="https://example.com/"><svg width="16" height="16" viewBox="0 0 16 16"><use href="https://cdn.example.com/sprite.svg#icon"/></svg> Acme</a></div>')->toArray();
$refusedMarkup = (string) ($refused['serialized_blocks'] ?? '');
$check(str_contains($refusedMarkup, 'Acme'), 'the refused-SVG label keeps its wordmark: ' . $refusedMarkup);
$check(1 === count(array_filter(
    $refused['fallbacks'] ?? array(),
    static fn (array $fallback): bool => 'html_inline_svg_fallback' === ($fallback['diagnostic_code'] ?? '')
)), 'the refused logo SVG records an inline SVG fallback diagnostic: ' . json_encode($refused['fallbacks'] ?? array()));

// 5. A bare classed SVG logo keeps its core/image route.
$bare = ( new HtmlTransformer() )->transform('<div class="brand">' . $svg . '</div>')->toArray();
$bareImage = $findBlock($bare['blocks'] ?? array(), 'core/image');
$check(null !== $bareImage && str_contains((string) ($bareImage['attrs']['className'] ?? ''), 'brand') && str_contains((string) ($bareImage['attrs']['url'] ?? ''), 'assets/materialized-svg/'), 'a bare classed SVG logo keeps its core/image route: ' . (string) ($bare['serialized_blocks'] ?? ''));

// 6. The reduced neutral page: the artwork and its link survive a page-level
// compile, with no empty link and no fallback diagnostics.
$page = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<!doctype html><html><head><title>Home</title></head><body><main>'
        . '<div class="brand"><a href="https://example.com/">' . $svg . '</a></div>'
        . '<p>Hello world</p></main></body></html>'),
))->toArray();
$pageMarkup = (string) ($page['serialized_blocks'] ?? '');
$check(1 !== preg_match('/<a[^>]*>\s*<\/a>/', $pageMarkup), 'the reduced page does not emit an empty link: ' . $pageMarkup);
$check(str_contains($pageMarkup, 'assets/materialized-svg/inline-svg-') && str_contains($pageMarkup, 'href="https://example.com/"'), 'the reduced page keeps the linked logo artwork: ' . $pageMarkup);
$check(1 === $svgAssets($page), 'the reduced page materializes exactly one SVG asset.');
$check(array() === ($page['fallbacks'] ?? array()), 'the reduced page records no fallback diagnostics.');
$check(str_contains($pageMarkup, 'Hello world'), 'the reduced page keeps its sibling paragraph: ' . $pageMarkup);

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Classed wrapper linked SVG passed.\n";
