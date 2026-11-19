<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A column item's width and preferred ratio determine its main-axis height,
// even when there is no explicit height declaration. An `!important` column
// direction (Bootstrap's `.flex-column`) is the same column axis (#6750).
foreach (array(
    array('flex-direction:column', '.art{width:100%;aspect-ratio:1/.85}'),
    array('flex-direction:column', '.art{width:100%}@media(max-width:800px){.art{aspect-ratio:4/3}}'),
    array('flex-direction:column!important', '.art{aspect-ratio:16/9}'),
) as [$direction, $ratio]) {
    $result = (new HtmlTransformer())->transform('<style>.card{display:flex;' . $direction . '}.art{background:#999}' . $ratio . '</style><article class="card"><div class="art"></div><p>Caption</p></article>')->toArray();
    $markup = $result['serialized_blocks'];
    if (!str_contains($markup, 'art') || preg_match('/blocks-engine-empty-flex-(column-)?item/', $markup)) {
        throw new RuntimeException('A ratio-sized column item must not receive the zero-height compatibility box.');
    }
    if ('pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '')) {
        throw new RuntimeException('The ratio-sized carrier must remain a valid native block.');
    }
}
echo "Empty flex aspect ratio passed.\n";
