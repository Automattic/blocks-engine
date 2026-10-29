<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A format tag keeps its tag in RichText and its carrier nests inside it, so a
// restated parent-relative value would resolve against the tag and compound
// (`strong{font-weight:bolder}` rendering 900 instead of 700).
// Each case: the declaration the nested carrier must (true) or must not
// (false) restate.
$cases = array(
    array( 'b,strong{font-weight:bolder}', 'strong', 'font-weight:bolder', false ),
    array( '.lead{font-weight:bolder}', 'b', 'font-weight:bolder', false ),
    array( '.lead{font-size:1.5em}', 'small', 'font-size:1.5em', true ),
    array( 'small{font-size:smaller}', 'small', 'font-size:smaller', false ),
    array( '.lead{font-weight:700}', 'strong', 'font-weight:700', true ),
);
foreach ( $cases as list( $css, $tag, $declaration, $restated ) ) {
    $html = '<!doctype html><html><head><style>' . $css . '</style></head><body><p><' . $tag . ' class="lead">Lead</' . $tag . '> text</p></body></html>';
    $markup = (new HtmlTransformer())->transform($html)->toArray()['serialized_blocks'];
    if ( $restated !== str_contains($markup, $declaration) ) {
        throw new RuntimeException("$css: " . ($restated ? 'lost' : 'restated') . " $declaration in $markup");
    }
}

echo "Format tag relative values not compounded passed\n";
