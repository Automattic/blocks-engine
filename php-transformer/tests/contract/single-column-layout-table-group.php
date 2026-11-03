<?php
declare(strict_types=1);

// Single-column layout tables lower to a core/group carrying the table box, cell spacing, padding and content cell height included; data tables stay core/table (#3986, #4025, #4059, #4111).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cases = array(
    'centered cell width'     => array('<center><table><tr><td width="300">Cell</td></tr></table></center>', '{"style":{"spacing":{"padding":{"top":"3px","right":"3px","bottom":"3px","left":"3px"}}},"layout":{"type":"constrained","contentSize":"300px","justifyContent":"center"}'),
    'left table cell width'   => array('<table><tr><td width="300">Cell</td></tr></table>', '"layout":{"type":"constrained","contentSize":"300px","justifyContent":"left"}'),
    'centered shrink-to-fit'  => array('<table align="center"><tr><td>Cell</td></tr></table>', '"layout":{"type":"flex","orientation":"vertical","justifyContent":"center"}'),
    'stacked page regions'    => array('<table width="600"><tr><td>Header</td></tr><tr><td>Body</td></tr></table>', '"contentSize":"596px","justifyContent":"left"}} -->|<!-- wp:group {"style":{"spacing":{"padding":{"top":"1px","right":"1px","bottom":"1px","left":"1px"}}}} -->|<!-- wp:group {"style":{"spacing":{"padding":{"top":"1px","right":"1px","bottom":"1px","left":"1px"},"margin":{"top":"2px"}}}} -->'),
    'authored zero spacing'   => array('<table width="600" cellspacing="0" cellpadding="0"><tr><td>Header</td></tr><tr><td>Body</td></tr></table>', '<!-- wp:group {"layout":{"type":"constrained","contentSize":"600px","justifyContent":"left"}} --><div class="wp-block-group is-layout-constrained wp-block-group-is-layout-constrained"><!-- wp:group -->'),
    'right-aligned div box'   => array('<div align="right"><table><tr><td>Top</td></tr><tr><td>Bottom</td></tr></table></div>', '"layout":{"type":"flex","orientation":"vertical","justifyContent":"right"}'),
    'empty sized cell spacer' => array('<table width="600"><tr><td>Top</td></tr><tr><td height="642"></td></tr><tr><td>Bottom</td></tr></table>', '<!-- wp:group {"style":{"spacing":{"margin":{"top":"2px"}}}} --><div class="wp-block-group" style="margin-top:2px"><!-- wp:spacer {"height":"642px"} -->'),
    'taller cell centers'     => array('<table width="300"><tr><td height="57">Title</td></tr><tr><td>Body</td></tr></table>', '<!-- wp:group {"style":{"spacing":{"padding":{"top":"1px","right":"1px","bottom":"1px","left":"1px"}},"dimensions":{"minHeight":"57px"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch","verticalAlignment":"center"}} --><div class="wp-block-group is-layout-flex wp-block-group-is-layout-flex" style="min-height:57px;padding-top:1px;padding-right:1px;padding-bottom:1px;padding-left:1px"><!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph'),
    'row valign bottom'       => array('<table width="300"><tr valign="bottom"><td height="80">Bottom</td></tr></table>', '<!-- wp:group {"style":{"dimensions":{"minHeight":"78px"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch","verticalAlignment":"bottom"}} -->'),
    'top cell keeps its flow' => array('<table width="300"><tr><td height="80" valign="top">Top</td></tr></table>', '<!-- wp:group {"style":{"dimensions":{"minHeight":"78px"}}} --><div class="wp-block-group" style="min-height:78px"><!-- wp:paragraph'),
    'centered paragraph'      => array('<center><p>Para</p></center>', '<!-- wp:paragraph {"align":"center"} -->'),
    'data table stays native' => array('<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>', '<!-- wp:table'),
    'unboxed one-column table stays native' => array('<table><tr><td>Ada</td></tr><tr><td>Grace</td></tr></table>', '<!-- wp:table'),
);
foreach ($cases as $label => [$source, $expected]) {
    $markup = (string) ((new HtmlTransformer())->transform($source)->toArray()['serialized_blocks'] ?? '');
    $layoutCase = '<!-- wp:table' !== $expected;
    $missing = array_filter(explode('|', $expected), static fn(string $needle): bool => !str_contains($markup, $needle));
    if (array() !== $missing || str_contains($markup, '<!-- wp:html') || ($layoutCase && str_contains($markup, '<!-- wp:table'))) {
        fwrite(STDERR, "FAIL: {$label}\n{$markup}\n");
        exit(1);
    }
}
echo "single-column-layout-table-group: passed\n";
