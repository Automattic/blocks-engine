<?php
declare(strict_types=1);

/**
 * A headerless table whose cells hold block structure - a menu list, a search
 * form - is page chrome laid out in a grid, not tabular data. It lowers to
 * native columns so each cell keeps its own id and class and every source
 * rule keyed on the cell (`#menu-cell ul li a`, `.finder`) still has a
 * subject. A core/table would flatten those cells to anonymous rich text.
 * A headerless table of plain text cells stays a core/table.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert   = static function (bool $condition, string $label, string $detail = '') use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};
$blocksNamed = static function (array $blocks, string $name) use (&$blocksNamed): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            $found[] = $block;
        }
        $found = array_merge($found, $blocksNamed($block['innerBlocks'] ?? array(), $name));
    }
    return $found;
};

$css = <<<'CSS'
#top-bar{background:rgba(0,0,0,.3)}
#menu-cell ul{list-style:none;margin:0;padding:0}
#menu-cell ul li{display:inline-block}
#menu-cell ul li a{color:#ffffff;text-transform:uppercase}
#top-bar .finder{max-width:210px}
CSS;
$html = <<<'HTML'
<div id="top-bar"><table><tbody><tr>
<td id="menu-cell"><ul class="menu-list">
<li><a href="/">Home</a></li>
<li><a href="/about">About</a></li>
<li><a href="/contact">Contact</a></li>
</ul></td>
<td class="finder"><form method="get"><input type="text" name="q" placeholder="Search"></form></td>
</tr></tbody></table></div>
<table class="price-list"><tbody>
<tr><td>Small</td><td>$4</td></tr>
<tr><td>Large</td><td>$6</td></tr>
</tbody></table>
HTML;

$result = ( new HtmlTransformer() )->transform('<style>' . $css . '</style>' . $html)->toArray();
$blocks = $result['blocks'] ?? array();
$markup = (string) ($result['serialized_blocks'] ?? '');

$columns = $blocksNamed($blocks, 'core/column');
$tables  = $blocksNamed($blocks, 'core/table');
$assert(2 === count($columns), 'the chrome table lowers to one column per cell', (string) count($columns));
$assert(1 === count($tables), 'only the plain-text table stays a core/table', (string) count($tables));
$assert(str_contains((string) ($tables[0]['attrs']['className'] ?? ''), 'price-list'), 'the remaining core/table is the plain-text one');

$menuCell   = $columns[0] ?? array();
$finderCell = $columns[1] ?? array();
$assert('menu-cell' === ($menuCell['attrs']['anchor'] ?? null), 'the menu cell keeps its id', (string) json_encode($menuCell['attrs'] ?? null));
$assert(1 === preg_match('/(?:^|\s)finder(?:\s|$)/', (string) ($finderCell['attrs']['className'] ?? '')), 'the search cell keeps its class', (string) json_encode($finderCell['attrs'] ?? null));
$assert(1 === preg_match('/<div id="menu-cell" class="wp-block-column\b/', $markup), 'the menu cell id is in saved markup');
$assert(1 === count($blocksNamed($menuCell['innerBlocks'] ?? array(), 'core/navigation')), 'the menu list converts natively inside its cell');
$assert(! preg_match('/<td[^>]*>\s*<(?:ul|form)\b/', $markup), 'no list or form is flattened into a table cell');

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "structured layout table columns: ok\n";
