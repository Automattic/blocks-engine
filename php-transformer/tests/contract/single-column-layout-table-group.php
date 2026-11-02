<?php
declare(strict_types=1);

// Single-column layout tables lower to a core/group carrying the table box; data tables stay core/table (#3986).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cases = array(
    'centered cell width'     => array('<center><table><tr><td width="300">Cell</td></tr></table></center>', '"layout":{"type":"constrained","contentSize":"300px","justifyContent":"center"}'),
    'left table cell width'   => array('<table><tr><td width="300">Cell</td></tr></table>', '"layout":{"type":"constrained","contentSize":"300px","justifyContent":"left"}'),
    'centered shrink-to-fit'  => array('<table align="center"><tr><td>Cell</td></tr></table>', '"layout":{"type":"flex","orientation":"vertical","justifyContent":"center"}'),
    'stacked page regions'    => array('<table width="600"><tr><td>Header</td></tr><tr><td>Body</td></tr></table>', '<!-- wp:group {"layout":{"type":"constrained","contentSize":"600px","justifyContent":"left"}} --><div class="wp-block-group is-layout-constrained wp-block-group-is-layout-constrained"><!-- wp:group -->'),
    'centered paragraph'      => array('<center><p>Para</p></center>', '<!-- wp:paragraph {"align":"center"} -->'),
    'data table stays native' => array('<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>', '<!-- wp:table'),
    'unboxed one-column table stays native' => array('<table><tr><td>Ada</td></tr><tr><td>Grace</td></tr></table>', '<!-- wp:table'),
);
foreach ($cases as $label => [$source, $expected]) {
    $markup = (string) ((new HtmlTransformer())->transform($source)->toArray()['serialized_blocks'] ?? '');
    $layoutCase = '<!-- wp:table' !== $expected;
    if (!str_contains($markup, $expected) || str_contains($markup, '<!-- wp:html') || ($layoutCase && str_contains($markup, '<!-- wp:table'))) {
        fwrite(STDERR, "FAIL: {$label}\n{$markup}\n");
        exit(1);
    }
}
echo "single-column-layout-table-group: passed\n";
