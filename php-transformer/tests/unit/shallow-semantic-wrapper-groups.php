<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Semantic tags supported by core/group must not trigger companion transport
// merely because the source wrapper chain is not made exclusively of divs.
// Include a deeper sibling branch: its height must not force the shared
// semantic ancestors into transport before descendant normalization runs.
$html = '<h1>Terms</h1><p>Editable policy</p>';
$result = (new HtmlTransformer())->transform('<main id="document" class="blocks-engine-source-main-fixture-3"><section id="policy" class="blocks-engine-source-section-fixture-3">' . $html . '</section></main>')->toArray();
$main = $result['blocks'][0] ?? array();
$section = $main['innerBlocks'][0] ?? array();
if ('core/group' !== ($main['blockName'] ?? '')
    || 'main' !== ($main['attrs']['tagName'] ?? '')
    || 'core/group' !== ($section['blockName'] ?? '')
    || 'section' !== ($section['attrs']['tagName'] ?? '')
    || array('core/heading', 'core/paragraph') !== array_column($section['innerBlocks'] ?? array(), 'blockName')
    || !str_contains($result['serialized_blocks'], 'id="document"')
    || !str_contains($result['serialized_blocks'], 'id="policy"')
    || array() !== ($result['source_reports']['generated_blocks'] ?? array())
) {
    fwrite(STDERR, "FAIL: shallow semantic wrappers must retain native editable structure and source identity\n");
    exit(1);
}
$branch = '<p>Deep editable policy</p>';
for ($i = 0; $i < 4; ++$i) {
    $branch = '<div id="policy-level-' . $i . '">' . $branch . '</div>';
}
$deep = (new HtmlTransformer())->transform('<main id="deep-document"><section id="deep-policy">' . $html . $branch . '</section></main>')->toArray();
if ('core/group' !== ($deep['blocks'][0]['blockName'] ?? '')
    || 'main' !== ($deep['blocks'][0]['attrs']['tagName'] ?? '')
    || 'core/group' !== ($deep['blocks'][0]['innerBlocks'][0]['blockName'] ?? '')
    || 'section' !== ($deep['blocks'][0]['innerBlocks'][0]['attrs']['tagName'] ?? '')
) {
    fwrite(STDERR, "FAIL: descendant height must not force semantic ancestors into companion transport\n");
    exit(1);
}
echo "PASS: semantic wrappers retain native Groups\n";
