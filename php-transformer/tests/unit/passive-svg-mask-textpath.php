<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

// Masks and text paths are standard, passive SVG geometry (#3734): a <mask>
// carrying maskUnits/maskContentUnits, or text along a <textPath startOffset>
// with alignment-baseline, must take the native SVG materialization path rather
// than the core/html honest floor, exactly like clipPathUnits already does.
// $artwork marks definitions-only SVG stores, which keep their authored box as
// an svg-artwork block instead of a standalone image asset.
$cases = array(
    'mask with maskUnits' => array(
        // The reduced page from the issue.
        'svg' => '<svg viewBox="0 0 10 10"><defs><mask id="m" maskUnits="userSpaceOnUse"><path d="M0 0L10 10" stroke="#fff"></path></mask></defs></svg>',
        'cased' => 'maskUnits="userSpaceOnUse"',
        'artwork' => true,
    ),
    'mask with maskContentUnits' => array(
        'svg' => '<svg viewBox="0 0 40 40"><defs><mask id="hole" maskContentUnits="objectBoundingBox"><circle cx="0.5" cy="0.5" r="0.4" fill="#fff"></circle></mask></defs><rect width="40" height="40" fill="#123456" mask="url(#hole)"></rect></svg>',
        'cased' => 'maskContentUnits="objectBoundingBox"',
        'artwork' => false,
    ),
    'textPath with startOffset' => array(
        'svg' => '<svg viewBox="0 0 200 60"><defs><path id="curve" d="M10 50 Q100 -20 190 50"></path></defs><text font-size="20" alignment-baseline="middle"><textPath href="#curve" startOffset="25%">Curved label</textPath></text></svg>',
        'cased' => 'startOffset="25%"',
        'artwork' => false,
    ),
    'wrapper-sized textPath' => array(
        'svg' => '<svg width="220" height="60" viewBox="0 0 220 60"><defs><path id="curve" d="M10 50 Q100 -20 210 50"></path></defs><text><textPath href="#curve" startOffset="25%">Curved label</textPath></text></svg>',
        'cased' => 'startOffset="25%"',
        'artwork' => false,
        'css' => '.w{width:320px}.w svg{width:100%;filter:drop-shadow(6px 6px 0 #123456);fill:red}',
    ),
);

foreach ($cases as $label => $case) {
    $document = new DOMDocument();
    $document->loadXML($case['svg']);
    $assert(SvgMaterializer::isPassiveSvgMarkup($document->documentElement), "{$label}: markup classifies as passive SVG.");

    $page = '<!doctype html><html><head><meta charset="utf-8"><title>T</title><style>' . ($case['css'] ?? '') . '</style></head><body><main><h1>W</h1><div class="w">' . "\n"
        . $case['svg'] . "\n"
        . '</div></main></body></html>';
    $result = (new HtmlTransformer())->transform($page)->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');

    $artworkBlock = null;
    $walk = static function (array $items) use (&$walk, &$artworkBlock): void {
        foreach ($items as $block) {
            if ('custom/svg-artwork' === ($block['blockName'] ?? null)) {
                $artworkBlock = $block;
            }
            $walk(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array());
        }
    };
    $walk(is_array($result['blocks'] ?? null) ? $result['blocks'] : array());
    $assets = array_values(array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'inline-svg' === ($asset['source'] ?? null)));

    $assert(!str_contains($markup, '<!-- wp:html'), "{$label}: no core/html fallback block.");
    $assert(array() === array_filter($result['fallbacks'] ?? array(), static fn(array $fallback): bool => 'html_inline_svg_fallback' === ($fallback['diagnostic_code'] ?? null)), "{$label}: no inline SVG fallback diagnostic.");
    if ($case['artwork']) {
        $assert(null !== $artworkBlock && str_contains((string) ($artworkBlock['attrs']['svg'] ?? ''), $case['cased']), "{$label}: the artwork block keeps the cased SVG attribute.");
    } else {
        $assert(str_contains($markup, '<!-- wp:image') && 1 === count($assets), "{$label}: materializes through the native image path.");
        $assert(str_contains((string) ($assets[0]['content'] ?? ''), $case['cased']), "{$label}: the materialized asset keeps the cased SVG attribute.");
    }
    if (isset($case['css'])) {
        $css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
        $assert(1 === preg_match('/([^{}]*wp-block-image[^{}]*> img[^{}]*)\\{([^{}]*filter:drop-shadow[^{}]*)\\}/', $css, $rule), "{$label}: the wrapper svg rule reaches the generated image.");
        $assert(str_contains($rule[2], 'width:100%'), "{$label}: authored percentage width is projected, not the intrinsic width.");
        $assert(!str_contains($rule[2], 'fill:'), "{$label}: SVG paint is not replayed onto the image.");
        $assert(!str_contains($markup, 'width:220px'), "{$label}: emitted inline geometry does not pin the intrinsic width above author CSS.");
    }
    $assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), "{$label}: emitted blocks remain Gutenberg-valid.");
}

// The loosened allow-list stays bounded: standard mask geometry does not
// rehabilitate active content on the same graphic. A non-passive definition
// store keeps its existing sanitized core/html fallback instead of the native
// image path.
$active = '<svg viewBox="0 0 10 10" onload="alert(1)"><defs><mask id="m" maskUnits="userSpaceOnUse"><path d="M0 0L10 10" stroke="#fff"></path></mask></defs></svg>';
$document = new DOMDocument();
$document->loadXML($active);
$assert(!SvgMaterializer::isPassiveSvgMarkup($document->documentElement), 'An event handler still disqualifies mask artwork as passive.');
$activeResult = (new HtmlTransformer())->transform('<main><div>' . $active . '</div></main>')->toArray();
$activeMarkup = (string) ($activeResult['serialized_blocks'] ?? '');
$activeAssets = array_values(array_filter($activeResult['assets'] ?? array(), static fn(array $asset): bool => 'inline-svg' === ($asset['source'] ?? null)));
$assert(str_contains($activeMarkup, '<!-- wp:html') && !str_contains($activeMarkup, '<!-- wp:image'), 'Active mask artwork retains its inline fallback instead of the native image path.');
$assert(array() === $activeAssets, 'Active mask artwork materializes no SVG asset.');

fwrite(STDOUT, 'Passive SVG mask/textPath tests: ' . $assertions . " passed\n");
