<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LayoutShellBlockGenerator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Runs the generated editor script under node with stubbed wp globals and asks
// the registered List View label function for the label of several shells.
$definition = ( new LayoutShellBlockGenerator() )->definition('neutral/layout-shell');
$dir = sys_get_temp_dir() . '/layout-shell-label-' . bin2hex(random_bytes(4));
mkdir($dir);
file_put_contents($dir . '/index.js', (string) $definition['assets']['index.js']);
$cases = array(
    'entrance' => array( array( 'tagName' => 'div', 'attributes' => array( 'class' => 'transition-all duration-700 opacity-100 translate-y-0', 'data-dla-viewport-entrance' => '{}' ) ) ),
    'entrance in section' => array( array( 'tagName' => 'section', 'attributes' => array( 'class' => 'transition-all', 'data-dla-viewport-entrance' => '{}' ) ) ),
    'utility only' => array( array( 'tagName' => 'div', 'attributes' => array( 'class' => 'flex items-center gap-4 mt-2 md:flex-row w-full text-lg border border-border rounded-2xl' ) ) ),
    'utility then name' => array( array( 'tagName' => 'div', 'attributes' => array( 'class' => 'flex transition-all hero-card' ) ) ),
    'plain name' => array( array( 'tagName' => 'div', 'attributes' => array( 'class' => 'pricing-table' ) ) ),
    'text-based name' => array( array( 'tagName' => 'div', 'attributes' => array( 'class' => 'text-block' ) ) ),
    'list item' => array( array( 'tagName' => 'li', 'attributes' => array( 'class' => 'flex' ) ) ),
    'chain' => array( array( 'tagName' => 'div', 'attributes' => array( 'class' => 'wp-block-group mt-2 gallery-frame' ) ), array( 'tagName' => 'div', 'attributes' => array( 'class' => 'space-y-16' ) ) ),
);
$runner = <<<'JS'
const fs = require('fs'); let label;
global.window = { wp: { blocks: { registerBlockType: (n, d) => { label = d.__experimentalLabel; } }, blockEditor: {}, element: { createElement() {} } } };
eval(fs.readFileSync(process.argv[2], 'utf8'));
const cases = JSON.parse(process.argv[3]);
const out = {}; for (const k of Object.keys(cases)) out[k] = label({ wrappers: cases[k] }, { context: 'list-view' });
console.log(JSON.stringify(out));
JS;
file_put_contents($dir . '/run.js', $runner);
$raw = shell_exec('node ' . escapeshellarg($dir . '/run.js') . ' ' . escapeshellarg($dir . '/index.js') . ' ' . escapeshellarg((string) json_encode($cases)) . ' 2>&1');
array_map('unlink', glob($dir . '/*') ?: array());
rmdir($dir);
$labels = json_decode((string) $raw, true);
if ( ! is_array($labels) ) {
    fwrite(STDERR, "node did not return labels: $raw\n");
    exit(1);
}
$expected = array(
    'entrance' => 'Scroll reveal',
    'entrance in section' => 'Section: Scroll reveal',
    'utility only' => 'Layout shell (1 wrappers)',
    'utility then name' => 'Layout: Hero Card',
    'plain name' => 'Layout: Pricing Table',
    'text-based name' => 'Layout: Text Block',
    'list item' => 'List item',
    'chain' => 'Layout: Gallery Frame',
);
$failures = array();
foreach ( $expected as $case => $want ) {
    if ( ( $labels[ $case ] ?? null ) !== $want ) {
        $failures[] = $case . ': expected "' . $want . '", got "' . ( $labels[ $case ] ?? 'none' ) . '"';
    }
}
if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "OK: layout shell labels passed\n";
