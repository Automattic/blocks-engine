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

$variableWidth = (new HtmlTransformer())->transform(
    '<style>:root{--site-width:980px}body:not(.responsive) #site-root{min-width:var(--site-width)}</style><div id="site-root"><main><p>Content</p></main></div>'
)->toArray();
$assert(
    str_contains($css($variableWidth), 'body:not(.responsive) #site-root{min-width:0;max-width:100%}'),
    'desktop canvas minimum widths expressed through source custom properties receive bounded responsive geometry'
);

$percentageHeight = (new HtmlTransformer())->transform(
    '<style>footer{height:auto}.footer-frame{height:100%!important}.footer-grid{display:grid;grid-template-rows:1fr min-content;height:100%}.footer-background{position:absolute;inset:0;height:100%}.definite-frame{height:320px}.definite-frame>.fill{height:100%}.media-fill{height:100%;object-fit:cover}.mixed-fill{height:100%}@media(max-width:600px){.mobile-footer-frame{height:100%}}</style>'
    . '<footer><div class="footer-frame"><div class="footer-grid"><nav>Links</nav><p>Copyright</p></div><div class="footer-background"></div></div></footer>'
    . '<section><div class="mobile-footer-frame"><p>Mobile links</p><p>Mobile copyright</p></div></section>'
    . '<div class="definite-frame"><div class="fill">Card</div><img class="media-fill" src="card.jpg" alt=""></div>'
    . '<footer><div class="mixed-fill">Safe shell</div></footer><div class="definite-frame"><div class="mixed-fill">Definite fill</div></div>'
)->toArray();
$percentageHeightCss = $css($percentageHeight);
$assert(str_contains($percentageHeightCss, '.footer-frame{height:auto!important}') && str_contains($percentageHeightCss, '.footer-grid{display:grid;height:auto;grid-template-rows:min-content min-content}'), 'indefinite-height footer wrappers and fractional row tracks collapse without changing declaration priority');
$assert(str_contains($percentageHeightCss, '@media(max-width:600px){.mobile-footer-frame{height:auto}}'), 'responsive structural variants receive the same percentage-height projection');
$assert(str_contains($percentageHeightCss, '.footer-background{position:absolute;inset:0;height:100%}') && str_contains($percentageHeightCss, '.definite-frame>.fill{height:100%}') && str_contains($percentageHeightCss, '.media-fill{height:100%;object-fit:cover}'), 'positioned layers, definite-height components, and replaced media retain authored fill geometry');
$assert(str_contains($percentageHeightCss, '.mixed-fill{height:100%}'), 'mixed structural and height-owning selectors retain their authored percentage height');
$assert(in_array('responsive_geometry_ambiguous_percentage_height', array_column($percentageHeight['diagnostics'] ?? array(), 'code'), true), 'mixed percentage-height selectors emit a bounded ambiguity diagnostic');

if ( $failures > 0 ) {
    fwrite(STDERR, "Responsive canvas geometry unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Responsive canvas geometry unit tests: {$passes} passed\n");
