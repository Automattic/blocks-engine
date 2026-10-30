<?php
declare(strict_types=1);

/**
 * A table whose every row is one cell spanning the whole declared grid is a
 * stack of rows, not a spanning data table. A page header laid out this way
 * (title row, nav row, each a single `colspan` cell) must lower to native
 * blocks instead of core/html.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$blocks = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();
$firstBlock = static fn (array $result): string => (string) ($result['blocks'][0]['blockName'] ?? '');

$header = <<<'HTML'
<table class="header">
<tbody>
<tr><td colspan="4"><h1 class="title">Site Name</h1><p class="subtitle">Tagline here<span id="cursor">|</span></p></td></tr>
<tr><td colspan="4"><div class="nav-split"><div class="nav-left"></div><div class="nav-right"><a href="/blog/">blog</a> <a href="/video/">video</a> <button type="button">&gt;_</button></div></div></td></tr>
</tbody>
</table>
HTML;

$result = $blocks($header);
$markup = (string) ($result['serialized_blocks'] ?? '');
$assert(! str_contains($markup, '<!-- wp:html'), 'a stacked single-cell colspan header table does not fall back to core/html');
$assert(! str_contains($markup, '<!-- wp:table'), 'a stacked single-cell colspan header table is not a data table');
$assert(str_contains($markup, '<!-- wp:heading'), 'the header title stays a native heading');
$assert(strpos($markup, 'Tagline here') < strpos($markup, 'href="/blog/"'), 'rows keep their source order');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), 'the lowered header stays editor-valid');

// Boundaries that must not move.
$assert('core/table' === $firstBlock($blocks('<table><tr><td>One</td></tr><tr><td>Two</td></tr></table>')), 'a one-column table without colspan still becomes core/table');
// Spanning and ragged data tables stay native: core/table cells carry spans.
foreach ( array(
    '<table><tr><td colspan="3">One</td></tr><tr><td colspan="2">Two</td></tr></table>' => '<td colspan="2">Two</td>',
    '<table><tr><th colspan="2">Head</th></tr><tr><td colspan="2">Body</td></tr></table>' => '<th colspan="2">Head</th>',
    '<table><tr><td colspan="2">Merged</td></tr><tr><td>A</td><td>B</td></tr></table>' => '<td colspan="2">Merged</td>',
    '<table><tr><td rowspan="2">Tall</td><td>X</td></tr><tr><td colspan="1">Next</td></tr></table>' => '<td rowspan="2">Tall</td><td>X</td></tr><tr><td>Next</td>',
    '<table><tr><td>A</td><td>B</td></tr><tr><td>C</td></tr></table>' => '<tr><td>C</td></tr>',
) as $source => $cells ) {
    $spanning = ( new HtmlTransformer() )->transform($source)->toArray();
    $assert('core/table' === ($spanning['blocks'][0]['blockName'] ?? null) && str_contains((string) ($spanning['serialized_blocks'] ?? ''), $cells), 'a spanning or ragged data table stays core/table with its cell spans', $source);
    $assert('pass' === ($spanning['source_reports']['wp_block_validity']['status'] ?? ''), 'a spanning or ragged native table stays editor-valid', $source);
}

echo "Stacked single-cell colspan table lowering passed.\n";
