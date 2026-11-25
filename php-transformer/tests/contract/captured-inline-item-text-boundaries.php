<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/box-tree-evidence-fixture.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Bedford's contacts compute to inline without authoring display at all.
$html = '<html><body><footer><div id="siteInfo">'
    . '<span class="site-address" data-blocks-engine-node-id="a">Address</span>'
    . '<span class="site-phone" data-blocks-engine-node-id="p">Phone</span>'
    . '<a class="site-email" data-blocks-engine-node-id="e" href="mailto:a@b.test">Email</a>'
    . '</div></footer></body></html>';
$inline = array('styles' => array('display' => 'inline'));
foreach (array('inline', 'absent', 'partial', 'atomic', 'responsive') as $case) {
    $nodes = array('a' => $inline, 'p' => $inline, 'e' => $inline);
    if ('partial' === $case) unset($nodes['p']);
    if ('atomic' === $case) $nodes['p']['styles']['display'] = 'inline-block';
    if ('responsive' === $case) $nodes['p']['viewports']['mobile']['styles']['display'] = 'block';
    $files = array(array('path' => 'index.html', 'kind' => 'html', 'content' => $html));
    if ('absent' !== $case) $files[] = box_tree_fixture_sidecar('index.html', $html, $nodes);
    $result = (new ArtifactCompiler())->compile(array('files' => $files))->toArray();
    $markup = $result['serialized_blocks'];
    $expected = 'inline' === $case ? 1 : 3;
    if ($expected !== substr_count($markup, '<!-- wp:paragraph ')) {
        throw new RuntimeException($case . ': unexpected paragraph boundaries: ' . $markup);
    }
    if ('inline' === $case && 'AddressPhoneEmail' !== strip_tags(preg_replace('/<!--.*?-->/s', '', $markup))) {
        throw new RuntimeException('Captured inline adjacency changed: ' . $markup);
    }
}
echo "PASS: captured inline item text boundaries\n";
