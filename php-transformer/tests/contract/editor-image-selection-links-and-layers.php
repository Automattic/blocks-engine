<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LayoutShellBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// Issue #5920: an author must be able to select an imported image in the
// editor and replace it. Two things stopped that on the IMB Catering site:
//
// 1. A link card (`<a class="card"><div><svg/></div>…</a>`) becomes a layout
//    shell whose editor renders the source anchor live. Gutenberg cancels link
//    activation in its canvas only for `#` links clicked directly, so a click
//    on the card's icon image followed the href and navigated the canvas
//    iframe to the linked page: every later image on the page was gone.
// 2. A scrim (`absolute inset-0`) over an image that fills its parent is a
//    block whose box covers the image block, so a click on the image always
//    selected the scrim.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

// 1. The layout shell's editor anchor cancels navigation; other wrappers keep
//    their default action.
$definition = (new LayoutShellBlockGenerator())->definition('neutral/layout-shell');
$dir = sys_get_temp_dir() . '/editor-image-selection-' . bin2hex(random_bytes(4));
mkdir($dir);
file_put_contents($dir . '/index.js', (string) $definition['assets']['index.js']);
file_put_contents($dir . '/run.js', <<<'JS'
const fs = require('fs'); let settings;
const createElement = (type, props, ...children) => ({ type, props: props || {}, children });
global.window = { wp: {
    blocks: { registerBlockType: (name, definition) => { settings = definition; } },
    blockEditor: { InnerBlocks: {}, useBlockProps: () => ({ className: 'block-editor-block-list__block' }), useInnerBlocksProps: (props) => props },
    element: { createElement },
} };
eval(fs.readFileSync(process.argv[2], 'utf8'));
const click = (node) => {
    let prevented = false;
    if (node.props.onClick) node.props.onClick({ preventDefault() { prevented = true; } });
    return prevented;
};
const out = {};
for (const [name, wrappers] of Object.entries({
    single: [ { tagName: 'a', attributes: { class: 'card', href: '/booking/' } } ],
    outer: [ { tagName: 'a', attributes: { href: '/booking/' } }, { tagName: 'div', attributes: { class: 'inner' } } ],
    inner: [ { tagName: 'div', attributes: { class: 'outer' } }, { tagName: 'A', attributes: { href: '/booking/' } } ],
    plain: [ { tagName: 'div', attributes: { class: 'card' } } ],
})) {
    const tree = settings.edit({ attributes: { wrappers } });
    const nodes = []; for (let node = tree; node && node.type; node = node.children[0]) nodes.push(node);
    out[name] = nodes.map((node) => ({ tag: String(node.type).toLowerCase(), prevented: click(node) }));
}
console.log(JSON.stringify(out));
JS);
$raw = shell_exec('node ' . escapeshellarg($dir . '/run.js') . ' ' . escapeshellarg($dir . '/index.js') . ' 2>&1');
array_map('unlink', glob($dir . '/*') ?: array());
rmdir($dir);
$clicks = json_decode((string) $raw, true);
$assert(is_array($clicks), 'The layout shell editor script renders its wrappers: ' . $raw);
foreach ($clicks as $case => $nodes) {
    foreach ($nodes as $node) {
        $assert(('a' === $node['tag']) === $node['prevented'], $case . ': a click inside an editor ' . $node['tag'] . ' wrapper ' . ('a' === $node['tag'] ? 'does not navigate the canvas.' : 'keeps its default action.'));
    }
}

// 2. In the editor only, a scrim over a fill image moves into flow after it.
$html = '<!doctype html><html><head><style>'
    . 'img{display:block;max-width:100%}.relative{position:relative}.absolute{position:absolute}.inset-0{inset:0}.h-full{height:100%}.w-full{width:100%}.aspect-video{aspect-ratio:16/9}.scrim{background:rgba(0,0,0,.4)}'
    . '</style></head><body><main><div class="relative aspect-video w-full">'
    . '<img class="h-full w-full" src="https://example.com/spread.jpg" alt="Food spread">'
    . '<div class="absolute inset-0 scrim"><p>Our story video is coming soon</p></div>'
    . '</div><div class="relative aspect-video w-full"><img class="h-full w-full" src="https://example.com/plain.jpg" alt="Plain"></div></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)));
$blocks = $result->toArray()['serialized_blocks'];
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(1 === preg_match_all('/<figure class="[^"]*\bblocks-engine-covered-fill-image\b/', $blocks), 'Only the fill image under a positioned layer is marked as covered.');
$assert(1 === preg_match('/<figure class="[^"]*\bblocks-engine-covered-fill-image\b[^"]*"><img[^>]*spread\.jpg/', $blocks), 'The covered image is the one the scrim paints over.');
$assets = array_column($plan['assets'], null, 'target_path');
$editor = '';
$frontend = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) {
    $content = (string) ($assets[$row['target_path']]['content'] ?? '');
    if ('frontend' !== $row['stylesheet_target']) $editor .= $content;
    if ('editor' !== $row['stylesheet_target']) $frontend .= $content;
}
$rule = ':root .editor-styles-wrapper figure.blocks-engine-covered-fill-image~.block-editor-block-list__block{position:static!important;inset:auto!important}';
$assert(str_contains($editor, $rule), 'The editor puts the layer after a covered image in flow, so a click on the image selects it.');
$assert(!str_contains($frontend, 'blocks-engine-covered-fill-image'), 'The front end keeps the captured layer over the image.');
