<?php
declare(strict_types=1);

/**
 * An inline SVG whose `<text>` uses a family that only exists through an author
 * `@font-face` cannot become an image (the image document cannot load the
 * font). When that is the only reason, it keeps the page document through the
 * SVG artwork block instead of falling back to core/html.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$transform = static function (string $svg): array {
    $out = ( new HtmlTransformer() )->transform(
        '<!doctype html><html><head><style>'
        . '@font-face{font-family:"Brand Serif";src:url(data:font/woff2;base64,AAAA) format("woff2")}'
        . '.logo{width:50px;height:50px;background:#333}'
        . '</style></head><body><main><h1>Logo</h1><div class="logo">' . $svg . '</div><p>Texto.</p></main></body></html>'
    )->toArray();
    $found = array();
    $walk = static function (array $blocks) use (&$walk, &$found): void {
        foreach ( $blocks as $block ) {
            $name = (string) ( $block['blockName'] ?? '' );
            if ( 'core/html' === $name || str_ends_with($name, '/svg-artwork') || 'core/image' === $name ) {
                $found[] = $block;
            }
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk($out['blocks'] ?? array());

    return array( 'blocks' => $found, 'serialized' => (string) ( $out['serialized_blocks'] ?? '' ) );
};

// 1. Reduced footer logo: author web font is the only blocker.
$logo = $transform('<svg viewBox="0 0 50 50" xmlns="http://www.w3.org/2000/svg" role="presentation" aria-hidden="true"><text x="5" y="30" font-size="18" fill="#fff" font-family="Brand Serif">VE</text></svg>');
$block = $logo['blocks'][0] ?? array();
$svg = (string) ( $block['attrs']['svg'] ?? '' );
$assert(1 === count($logo['blocks']) && str_ends_with((string) ( $block['blockName'] ?? '' ), '/svg-artwork'), '1: the web-font SVG becomes the SVG artwork block', $logo['serialized']);
$assert(! str_contains($logo['serialized'], '<!-- wp:html'), '2: no core/html block is emitted', $logo['serialized']);
$assert(! str_contains($logo['serialized'], 'materialized-svg'), '3: the SVG is not externalized as an image', $logo['serialized']);
$assert(str_contains($svg, 'font-family="Brand Serif"') && str_contains($svg, '>VE</text>'), '4: the text keeps the author font and its content', $svg);

// 2. A second blocker (an external reference) still keeps the honest floor.
$external = $transform('<svg viewBox="0 0 50 50" xmlns="http://www.w3.org/2000/svg"><use href="external.svg#mark"/><text x="5" y="30" font-family="Brand Serif">VE</text></svg>');
$assert(! str_ends_with((string) ( $external['blocks'][0]['blockName'] ?? '' ), '/svg-artwork'), '5: an external reference is not admitted by the web-font exception', $external['serialized']);

// 3. Without the author font the SVG stays a native image.
$system = $transform('<svg viewBox="0 0 50 50" xmlns="http://www.w3.org/2000/svg"><text x="5" y="30" font-size="18" fill="#fff" font-family="Georgia">VE</text></svg>');
$assert('core/image' === ( $system['blocks'][0]['blockName'] ?? '' ), '6: a system-font SVG still becomes core/image', $system['serialized']);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "svg author font artwork: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "svg author font artwork: {$passes} passed" . PHP_EOL);
