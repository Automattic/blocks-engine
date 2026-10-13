<?php
declare(strict_types=1);

/**
 * A layout table has no implicit phone breakpoint, so its native columns opt
 * out of Core's 782px stacking. When the source's own responsive CSS collapses
 * every cell of a row to a block box under a media condition, the row stacks
 * under exactly that condition instead. A table without that evidence stays
 * side by side at every width.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$failures = array();
$assert   = static function (bool $condition, string $label, string $detail = '') use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};
$cssFor = static fn (array $result): string => implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_values(array_filter(
        is_array($result['assets'] ?? null) ? $result['assets'] : array(),
        static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')
    ))
));
$columnsBlocks = static function (array $blocks) use (&$columnsBlocks): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( 'core/columns' === ($block['blockName'] ?? '') ) {
            $found[] = $block;
        }
        $found = array_merge($found, $columnsBlocks($block['innerBlocks'] ?? array()));
    }
    return $found;
};

$table = static fn (string $class): string => <<<HTML
<div class="row-wrap" style="margin:0 -15px">
<table class="{$class}-columns"><tbody><tr>
<td class="{$class}-cell" style="width:25%;padding:0 15px"><p>Aside</p></td>
<td class="{$class}-cell" style="width:75%;padding:0 15px"><p>Body copy.</p></td>
</tr></tbody></table>
</div>
HTML;
$css = <<<'CSS'
.grid-columns,.fixed-columns{border-collapse:collapse;table-layout:fixed;width:100%}
.grid-cell,.fixed-cell{vertical-align:top}
@media screen and (max-width: 767px){
  td.grid-cell{float:none;display:block;width:100% !important;box-sizing:border-box}
}
CSS;

$result = ( new HtmlTransformer() )->transform('<style>' . $css . '</style>' . $table('grid') . $table('fixed'))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$styles = $cssFor($result);
$rows   = $columnsBlocks($result['blocks'] ?? array());

$assert(2 === count($rows), 'both layout tables lower to native columns', (string) count($rows));
foreach ( $rows as $row ) {
    $assert(false === ($row['attrs']['isStackedOnMobile'] ?? null), 'layout tables keep the Core stacking opt-out');
}

$stacking = static function (array $row) use ($styles): string {
    foreach ( preg_split('/\s+/', (string) ($row['attrs']['className'] ?? '')) as $class ) {
        if ( '' !== $class && 1 === preg_match('/@media screen and \(max-width: 767px\)\{[^@]*\.' . preg_quote($class, '/') . '>\.wp-block-column\{flex-basis:100%!important\}/', $styles) ) {
            return $class;
        }
    }
    return '';
};
$grid  = $rows[0] ?? array();
$fixed = $rows[1] ?? array();
$assert(str_contains((string) ($grid['attrs']['className'] ?? ''), 'grid-columns'), 'first row comes from the collapsing table');
$assert('' !== $stacking($grid), 'cells the source collapses to blocks stack under the source media condition', $styles);
$assert(str_contains($styles, 'flex-wrap:wrap!important'), 'stacked row wraps its columns under the source condition');
$assert('' === $stacking($fixed), 'a table without responsive cell collapse stays side by side');
$assert(! str_contains($styles, '@media (max-width:781px)'), 'stacking does not borrow Core\'s phone breakpoint');

$validity = ( new Runtime() )->validateBlockSerialization($markup);
$assert(array() === ($validity['findings'] ?? array()), 'serialization stays valid', (string) json_encode($validity['findings'] ?? array()));

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "layout table source stacking passed\n";
