<?php
declare(strict_types=1);

/**
 * A layout shell saves each wrapper attribute so the editor reads back the
 * value it stored. A value holding entity-shaped text (a captured snapshot of
 * markup carrying `&quot;`) otherwise loses its ampersand on save: Gutenberg's
 * serializer leaves `&name;` unescaped, so the saved HTML no longer matches
 * the post body and the block opens invalid.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LayoutShellBlockGenerator;

$definition = ( new LayoutShellBlockGenerator() )->definition('neutral/layout-shell');
$dir = sys_get_temp_dir() . '/layout-shell-entity-' . bin2hex(random_bytes(4));
mkdir($dir);
file_put_contents($dir . '/index.js', (string) $definition['assets']['index.js']);
$value = '{"html":"<ul data-s=\"{&quot;k&quot;:1}\">A &amp; B &#38; C &x</ul>"}';
$runner = <<<'JS'
const fs = require('fs'); let save;
// Gutenberg's attribute escaping (@wordpress/escape-html escapeAttribute).
const escape = v => String(v).replace(/&(?!([a-z0-9]+|#[0-9]+|#x[a-f0-9]+);)/gi, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const render = node => node && node.type ? '<' + node.type + Object.keys(node.props || {}).map(k => ' ' + k + '="' + escape(node.props[k]) + '"').join('') + '>' + (node.children || []).map(render).join('') + '</' + node.type + '>' : '';
const InnerBlocks = function () {}; InnerBlocks.Content = null;
global.window = { wp: { blocks: { registerBlockType: (n, d) => { save = d.save; } }, blockEditor: { InnerBlocks }, element: { createElement: (type, props, ...children) => ({ type, props, children }) } } };
eval(fs.readFileSync(process.argv[2], 'utf8'));
console.log(render(save({ attributes: { wrappers: [ { tagName: 'div', attributes: { 'data-snapshot': process.argv[3] } } ] } })));
JS;
file_put_contents($dir . '/run.js', $runner);
$html = (string) shell_exec('node ' . escapeshellarg($dir . '/run.js') . ' ' . escapeshellarg($dir . '/index.js') . ' ' . escapeshellarg($value) . ' 2>&1');
array_map('unlink', glob($dir . '/*') ?: array());
rmdir($dir);
$saved = preg_match('/data-snapshot="([^"]*)"/', $html, $match) ? html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
if ( $saved !== $value ) {
    fwrite(STDERR, "FAIL: the saved attribute reads back as the stored value\nexpected: {$value}\nsaved:    " . var_export($saved, true) . "\nhtml: {$html}\n");
    exit(1);
}
echo "OK: layout shell entity attribute round-trip passed\n";
