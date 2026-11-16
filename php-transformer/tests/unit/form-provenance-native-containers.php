<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// Geometry annotations must not turn ordinary form rows and chains into
// generated shells. Exercise both branching and single-child graph shapes.
foreach (array(
    '<div class="column"><input name="name"></div>',
    '<div class="column"><input name="name"></div><div class="column" style=""><input name="email"></div>',
) as $children) {
    foreach (array(false, true) as $annotated) {
        $html = '<form><div class="row">' . $children . '</div></form>';
        if ($annotated) {
            $index = 0;
            $html = preg_replace_callback('/<div/', static function () use (&$index): string {
                return '<div data-blocks-engine-node-id="n' . ++$index . '"';
            }, $html);
        }
        $result = (new HtmlTransformer())->transform($html)->toArray();
        $readable = $result['fallbacks'][0]['readable_blocks'];
        $markup = (new Runtime())->serializeBlocks($readable);
        if (str_contains($markup, '/layout-shell') || str_contains($markup, 'wp:html')) {
            throw new RuntimeException('Provenance-only form containers must stay native: ' . $markup);
        }
        $row = $readable[0]['innerBlocks'][0];
        if ('core/group' !== $row['blockName'] || count($row['innerBlocks']) !== substr_count($children, '<div')) {
            throw new RuntimeException('Native row must retain its editable column structure.');
        }
        foreach ($row['innerBlocks'] as $column) {
            if ('core/group' !== $column['blockName'] || !str_contains($column['attrs']['className'] ?? '', 'column')) {
                throw new RuntimeException('Native columns must retain source identity.');
            }
        }
    }
}
echo "Form provenance native containers passed\n";
