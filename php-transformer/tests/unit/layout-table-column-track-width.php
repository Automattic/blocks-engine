<?php
declare(strict_types=1);

/**
 * Layout-table columns must keep authored cell track sizes. A fixed-layout
 * row whose sidebar cell is 215px (from CSS, not an inline percent) has to
 * become a 215px core/column so the remaining cell keeps the leftover track
 * instead of splitting 50/50.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assertions = 0;
$failures   = array();
$assert     = static function (bool $condition, string $label, string $detail = '') use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};

$cssFor = static function (array $result): string {
    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_values(array_filter(
            is_array($result['assets'] ?? null) ? $result['assets'] : array(),
            static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')
        ))
    ));
};

$html = <<<'HTML'
<style>
.rail{width:215px}
@media (max-width:767px){
.rail{width:100%}
td{display:block;width:100%}
}
</style>
<table style="width:100%;table-layout:fixed">
<tbody><tr>
<td valign="top"><p>Post copy.</p>
<table><tbody><tr><td>nested</td></tr></tbody></table>
</td>
<td class="rail"><p>Side</p></td>
</tr></tbody>
</table>
HTML;

$result  = ( new HtmlTransformer() )->transform($html)->toArray();
$markup  = (string) ($result['serialized_blocks'] ?? '');
$css     = $cssFor($result);
$columns = $result['blocks'][0] ?? array();
$assert('core/columns' === ($columns['blockName'] ?? null), 'fixed-layout nested table lowers to columns');
$assert(2 === count($columns['innerBlocks'] ?? array()), 'two cells become two columns');
$assert(
    ! isset($columns['innerBlocks'][0]['attrs']['width']),
    'unsized cell does not receive an invented equal-split width',
    (string) ($columns['innerBlocks'][0]['attrs']['width'] ?? '')
);
$assert(
    '215px' === ($columns['innerBlocks'][1]['attrs']['width'] ?? null),
    'css pixel cell width becomes the column width',
    (string) ($columns['innerBlocks'][1]['attrs']['width'] ?? '')
);
$assert(str_contains($markup, 'flex-basis:215px'), 'saved column markup uses the pixel track as flex-basis');
$assert(
    str_contains($css, '.wp-block-columns.blocks-engine-layout-table-columns>.wp-block-column[style*="flex-basis"]{flex-grow:0}'),
    'sized layout-table columns do not grow into the leftover track'
);

$attrTable = ( new HtmlTransformer() )->transform(
    '<table style="width:100%;table-layout:fixed"><tbody><tr>'
    . '<td>Main<table><tbody><tr><td>inner</td></tr></tbody></table></td>'
    . '<td width="180">Side</td>'
    . '</tr></tbody></table>'
)->toArray();
$assert(
    '180px' === ($attrTable['blocks'][0]['innerBlocks'][1]['attrs']['width'] ?? null),
    'html width attribute becomes a pixel column track',
    (string) ($attrTable['blocks'][0]['innerBlocks'][1]['attrs']['width'] ?? '')
);

$percentCss = ( new HtmlTransformer() )->transform(
    '<style>.primary{width:70%}.secondary{width:30%}</style>'
    . '<table class="cols"><tbody><tr>'
    . '<td class="primary">Main<table><tbody><tr><td>inner</td></tr></tbody></table></td>'
    . '<td class="secondary">Side</td>'
    . '</tr></tbody></table>'
)->toArray();
$assert(
    '70%' === ($percentCss['blocks'][0]['innerBlocks'][0]['attrs']['width'] ?? null)
        && '30%' === ($percentCss['blocks'][0]['innerBlocks'][1]['attrs']['width'] ?? null),
    'css percent cell widths become column widths',
    (string) ($percentCss['blocks'][0]['innerBlocks'][0]['attrs']['width'] ?? '') . ',' . (string) ($percentCss['blocks'][0]['innerBlocks'][1]['attrs']['width'] ?? '')
);

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'layout table column track width passed (' . $assertions . " assertions)\n";
