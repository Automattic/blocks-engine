<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

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
$css = static function (array $result): string {
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'author-css' === ($asset['source'] ?? '') ) {
            return (string) ($asset['content'] ?? '');
        }
    }
    return '';
};

$result = (new HtmlTransformer())->transform(
    '<style>#page-shell{min-width:980px;background:#fff}.page-strip{min-width:980px;padding:24px}.product-card{min-width:42rem;width:30rem}</style>'
    . '<div id="page-shell"><header class="page-strip"><p>Header</p></header><main class="page-strip"><section><p>Body</p></section><div class="product-card"><p>Card</p></div></main><footer class="page-strip"><p>Footer</p></footer></div>'
)->toArray();
$authorCss = $css($result);

$assert(! str_contains($authorCss, '#page-shell{min-width:980px') && ! str_contains($authorCss, '.page-strip{min-width:980px'), 'desktop canvas minimum widths are removed from page shell and section strips');
$assert(substr_count($authorCss, 'min-width:0') === 2 && substr_count($authorCss, 'max-width:100%') === 2, 'page shell and section strips receive bounded responsive geometry');
$assert(str_contains($authorCss, '#page-shell{background:#fff;min-width:0;max-width:100%}') && str_contains($authorCss, '.page-strip{padding:24px;min-width:0;max-width:100%}'), 'desktop paint and section spacing survive the responsive projection');
$assert(str_contains($authorCss, '.product-card{min-width:42rem;width:30rem}'), 'authored content minimum widths remain unchanged');

$unmatched = (new HtmlTransformer())->transform('<style>.desktop-shell{min-width:980px}</style><main><p>Content</p></main>')->toArray();
$assert(str_contains($css($unmatched), '.desktop-shell{min-width:980px}'), 'unmatched author selectors retain their minimum widths');

$ambiguous = (new HtmlTransformer())->transform(
    '<style>.wide{min-width:980px}</style><div class="wide"><section><p>Shell</p></section></div><div><p class="wide">Content</p></div>'
)->toArray();
$assert(str_contains($css($ambiguous), '.wide{min-width:980px}'), 'mixed shell and content selectors retain the authored minimum width');
$assert(
    in_array('responsive_geometry_ambiguous_min_width', array_column($ambiguous['diagnostics'] ?? array(), 'code'), true),
    'mixed shell and content minimum-width selectors remain intact and emit a diagnostic'
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Responsive canvas geometry unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Responsive canvas geometry unit tests: {$passes} passed\n");
