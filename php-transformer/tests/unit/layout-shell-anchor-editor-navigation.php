<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LayoutShellBlockGenerator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// A source card saved as an anchor layout shell must not navigate the editor
// canvas when an inner block (its icon image, say) is clicked for selection
// (issue #5920): the edit view cancels the anchor's navigation, keeps its
// href, and the saved markup stays the authored link without a handler.
$definition = ( new LayoutShellBlockGenerator() )->definition('neutral/layout-shell');
$dir = sys_get_temp_dir() . '/layout-shell-anchor-' . bin2hex(random_bytes(4));
mkdir($dir);
file_put_contents($dir . '/index.js', (string) $definition['assets']['index.js']);
$cases = array(
    'single anchor' => array( array( 'tagName' => 'a', 'attributes' => array( 'class' => 'group flex', 'href' => 'https://example.test/faqs/location' ) ) ),
    'anchor chain' => array( array( 'tagName' => 'div', 'attributes' => array( 'class' => 'card' ) ), array( 'tagName' => 'a', 'attributes' => array( 'href' => '/menu' ) ) ),
    'div' => array( array( 'tagName' => 'div', 'attributes' => array( 'class' => 'card' ) ) ),
);
$runner = <<<'JS'
const fs = require('fs'); let def;
const el = (type, props, ...children) => ({ type, props: props || {}, children });
const InnerBlocks = function () {}; InnerBlocks.Content = function () {};
global.window = { wp: {
    blocks: { registerBlockType: (n, d) => { def = d; } },
    blockEditor: { InnerBlocks, useBlockProps: () => ({ className: 'block-editor-block-list__block wp-block' }), useInnerBlocksProps: (p) => p },
    element: { createElement: el },
} };
eval(fs.readFileSync(process.argv[2], 'utf8'));
const anchors = (node, out = []) => { if (node && node.type === 'a') out.push(node); (node && node.children || []).forEach((c) => anchors(c, out)); return out; };
const cases = JSON.parse(process.argv[3]); const out = {};
for (const k of Object.keys(cases)) {
    const edited = anchors(def.edit({ attributes: { wrappers: cases[k] } }));
    const saved = anchors(def.save({ attributes: { wrappers: cases[k] } }));
    out[k] = {
        edited: edited.map((a) => { let prevented = false; if (a.props.onClick) a.props.onClick({ preventDefault() { prevented = true; } }); return { href: a.props.href, prevented }; }),
        saved: saved.map((a) => ({ href: a.props.href, handler: 'onClick' in a.props })),
    };
}
console.log(JSON.stringify(out));
JS;
file_put_contents($dir . '/run.js', $runner);
$raw = shell_exec('node ' . escapeshellarg($dir . '/run.js') . ' ' . escapeshellarg($dir . '/index.js') . ' ' . escapeshellarg((string) json_encode($cases)) . ' 2>&1');
array_map('unlink', glob($dir . '/*') ?: array());
rmdir($dir);
$result = json_decode((string) $raw, true);
if ( ! is_array($result) ) {
    fwrite(STDERR, "node did not return a result: $raw\n");
    exit(1);
}
$expected = array(
    'single anchor' => array( 'edited' => array( array( 'href' => 'https://example.test/faqs/location', 'prevented' => true ) ), 'saved' => array( array( 'href' => 'https://example.test/faqs/location', 'handler' => false ) ) ),
    'anchor chain' => array( 'edited' => array( array( 'href' => '/menu', 'prevented' => true ) ), 'saved' => array( array( 'href' => '/menu', 'handler' => false ) ) ),
    'div' => array( 'edited' => array(), 'saved' => array() ),
);
$failures = array();
foreach ( $expected as $case => $want ) {
    if ( ( $result[ $case ] ?? null ) !== $want ) {
        $failures[] = $case . ': expected ' . json_encode($want) . ', got ' . json_encode($result[ $case ] ?? null);
    }
}
if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "OK: layout shell anchors cancel editor navigation and keep their saved href\n";
