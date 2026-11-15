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
// Shared ancestry still counts for mixed container/semantic runs. Compress
// those containers first, preserving the section as an editable native child.
$mixed = '<section id="mixed-region"><p>First</p><p>Second</p></section>';
for ($i = 0; $i < 3; ++$i) {
    $mixed = '<div id="local-' . $i . '">' . $mixed . '</div>';
}
for ($i = 0; $i < 5; ++$i) {
    $mixed = '<div id="ancestor-' . $i . '"><p>Sibling</p>' . $mixed . '</div>';
}
$mixedResult = (new HtmlTransformer())->transform($mixed)->toArray();
$cursor = $mixedResult['blocks'][0];
for ($i = 0; $i < 5; ++$i) {
    $cursor = $cursor['innerBlocks'][1];
}
if (!str_ends_with($cursor['blockName'] ?? '', '/layout-shell')
    || array('div', 'div', 'div') !== array_column($cursor['attrs']['wrappers'] ?? array(), 'tagName')
    || 'core/group' !== ($cursor['innerBlocks'][0]['blockName'] ?? '')
    || 'section' !== ($cursor['innerBlocks'][0]['attrs']['tagName'] ?? '')
) {
    fwrite(STDERR, "FAIL: mixed semantic ancestry must retain depth compression and native section identity\n");
    exit(1);
}
// Mixed semantic/container chains retain their established shell transport.
// In particular the inline SVG must remain a paragraph adjacent to the heading,
// not become nested inside newly introduced semantic Groups.
$icon = (new HtmlTransformer())->transform('<main><section class="features"><div class="feature"><svg class="benefit-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12l5 5L20 6"></path></svg><h3>Fast</h3><p>Quick care.</p></div></section></main>')->toArray();
if (!str_ends_with($icon['blocks'][0]['blockName'] ?? '', '/layout-shell')
    || array('core/paragraph', 'core/heading', 'core/paragraph') !== array_column($icon['blocks'][0]['innerBlocks'] ?? array(), 'blockName')
    || !str_contains($icon['blocks'][0]['innerBlocks'][0]['attrs']['content'] ?? '', 'benefit-icon')
) {
    fwrite(STDERR, "FAIL: mixed semantic SVG chains must retain established paragraph transport\n");
    exit(1);
}
echo "PASS: semantic wrappers retain native Groups\n";
