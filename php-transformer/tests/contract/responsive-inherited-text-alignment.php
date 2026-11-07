<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A centred row whose text cell switches to left alignment at a breakpoint.
// Lifting the row's resting `center` onto the generated paragraph as
// `has-text-align-center` would freeze the narrow-viewport value and silence
// the cell's media rule at wide viewports.
$fixture = static function (string $cellRules): string {
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Footer</title><style>'
        . '.row{display:flex;flex-wrap:wrap;align-items:center;text-align:center;max-width:1200px;margin:0 auto}'
        . '.cell{flex:1 1 100%;color:#999;font-size:14px}'
        . '@media (min-width:1024px){.cell{flex-basis:0%}}'
        . $cellRules
        . '</style></head><body><footer><section><div class="row">'
        . '<div class="cell"><div class="details"><p style="margin:0"><span>Copyright notice text</span></p></div></div>'
        . '<div class="cell"></div>'
        . '</div></section></footer></body></html>';
};

$failures = array();
foreach ( array(
    'responsive cell' => array( '@media (min-width:1024px){.details{text-align:left}}', false ),
    'static cell control' => array( '.details{font-weight:400}', true ),
) as $case => [$rules, $expectLift] ) {
    $markup = (string) (( new HtmlTransformer() )->transform($fixture($rules))->toArray()['serialized_blocks'] ?? '');
    if ( 1 !== preg_match('/<!-- wp:paragraph(?: (\{.*?\}))? -->\s*<p\b([^>]*)>/', $markup, $match) ) {
        $failures[] = "$case: paragraph is present\n$markup";
        continue;
    }
    $attrs = json_decode($match[1] ?? '{}', true) ?? array();
    $lifted = 'center' === ($attrs['style']['typography']['textAlign'] ?? '') && str_contains($match[2], 'has-text-align-center');
    if ( $expectLift !== $lifted ) {
        $failures[] = $case . ': ' . ( $expectLift ? 'inherited centre is lifted' : 'resting centre is not frozen over the responsive rule' ) . "\n$markup";
    }
    if ( ! $expectLift && 1 !== preg_match('/@media[^{]*min-width:\s*1024px[^{]*\{[^}]*text-align:\s*left/', (string) json_encode(( new HtmlTransformer() )->transform($fixture($rules))->toArray(), JSON_UNESCAPED_SLASHES)) ) {
        $failures[] = "$case: responsive left alignment stays in the generated stylesheet";
    }
}

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Responsive inherited text alignment contracts passed.\n";
