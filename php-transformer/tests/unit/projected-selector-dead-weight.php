<?php
declare(strict_types=1);

/**
 * Projected theme CSS must not ship selector text that can never match:
 * - a route-set subject gate keeps its `:root…` alternative only where the
 *   subject could be the document root, not past a combinator;
 * - a Core layer bridge (`:root:root:root :is(…)`) omits pseudo-element arms,
 *   which are invalid inside `:is()` and match nothing there.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if ( ! $condition ) throw new RuntimeException($message . ('' !== $detail ? "\n" . $detail : ''));
};

// Route-set subject gates.
$route = '.card>.title{color:red}.solo{color:blue}.card .title:after{content:"x"}';
$result = (new CssStylesheetTransformer())->scopeRouteVariants(
    array(),
    array('a' => array($route), 'b' => array($route), 'c' => array()),
    array(), null, array(), null,
    array('a' => 'be-r-a', 'b' => 'be-r-b', 'c' => 'be-r-c'),
    null,
    static fn (array $classes): string => 'be-rs-test'
);
$css = $result['stylesheet'];
$assert(str_contains($css, '.card>.title:where(:root.be-rs-test *){color:red}'), 'A subject past a combinator is never the root: it keeps only the descendant gate', $css);
$assert(str_contains($css, '.card .title:where(:root.be-rs-test *):after{content:"x"}'), 'The descendant-only gate stays before a pseudo-element', $css);
$assert(str_contains($css, '.solo:where(:root.be-rs-test,:root.be-rs-test *){color:blue}'), 'A lone compound may be the root and keeps both alternatives', $css);

// Core layer bridges.
$html = '<!DOCTYPE html><html><head><title>t</title><style>@layer utilities{.row{display:flex;gap:12px}.btn,.btn:after{border:2px solid #1f2a44;padding:12px 24px;color:#1f2a44}}</style></head>'
    . '<body><main><h1>Links</h1><div class="row"><a href="https://example.com/a" class="btn">Inquire</a><a href="https://example.com/b" class="btn">More</a></div></main></body></html>';
$result = (new HtmlTransformer())->transform($html, array())->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert(1 === preg_match_all('/:root:root:root :is\(([^{}]*)\)\{[^}]*padding:revert-layer/', $css, $bridges), 'The layered control keeps its Core bridge', $css);
foreach ( $bridges[1] as $bridge ) {
    foreach ( CssStylesheetTransformer::splitSelectorList($bridge) ?? array() as $arm ) {
        $assert(! CssStylesheetTransformer::namesPseudoElement($arm), 'A bridge must not ship a pseudo-element arm :is() can never match', $bridge);
    }
    $assert(str_contains($bridge, 'wp-block-button__link'), 'The bridge still targets the control link', $bridge);
}

echo "Projected selector dead weight contract passed\n";
