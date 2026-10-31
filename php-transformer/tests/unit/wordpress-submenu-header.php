<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Route -> navigation part -> responsive-container part, as in the capture.
// Exercise include expansion AND capture projection, not just HTML lowering.
$root = dirname(__DIR__) . '/fixtures/wordpress-submenu-header';
$files = array();
foreach (array('index.html', 'parts/navigation.html', 'parts/navigation-panel.html', 'parts/menu-content.html', 'parts/menu-fragment-1.html') as $path) {
    $files[$path] = file_get_contents($root . '/' . $path);
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$markup = $result['source_reports']['compiled_site']['pages'][0]['block_markup'] ?? '';
if ('' === $markup) throw new RuntimeException('compiled route markup must be present');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$assert(1 === substr_count($markup, '<!-- wp:navigation '), 'whole landmark is one navigation');
$assert(1 === substr_count($markup, '<!-- wp:navigation-submenu '), 'Links is an editable submenu');
$assert(6 === substr_count($markup, '<!-- wp:navigation-link '), 'all desktop and submenu destinations survive');
$topLevelMarkup = explode('<!-- wp:navigation-submenu ', $markup)[0];
$assert(3 === substr_count($topLevelMarkup, '<!-- wp:navigation-link '), 'three top-level links precede Links submenu');
$assert(!str_contains($markup, '<!-- wp:paragraph '), 'items do not become paragraphs');
$assert(!str_contains($markup, '"tagName":"nav"'), 'landmark is not left as a group around the inner menu');
preg_match('/<!-- wp:navigation (\{.*?\}) -->/s', $markup, $match);
$attrs = json_decode($match[1] ?? '', true) ?: array();
$assert('mobile' === ($attrs['overlayMenu'] ?? ''), 'native mobile overlay survives');
$assert(false !== ($attrs['showSubmenuIcon'] ?? true), 'Core renders the submenu indicator');
$assert('24px' === ($attrs['style']['spacing']['blockGap'] ?? ''), 'captured item gap survives');
$assert(!str_contains($markup, 'data-blocks-engine-capture-dialog'), 'no obsolete capture dialog wiring survives');
foreach (array('Blog', 'Foto', 'Qué es esto?', 'Links') as $label) {
    $assert(str_contains($markup, $label), 'menu label survives: ' . $label);
}
$assert('0.75rem' === ($attrs['style']['typography']['fontSize'] ?? ''), 'source item size survives');
$assert('#ffffff' === ($attrs['customOverlayTextColor'] ?? '') && '#6e6e6e' === ($attrs['customOverlayBackgroundColor'] ?? ''), 'source overlay colors survive');
$assert(str_contains($markup, 'blocks-engine-navigation-submenu-background-'), 'submenu keeps its source background');
$assert(str_contains($attrs['metadata']['blocksEngineNavigationOpener']['svg'] ?? '', 'M4 7.5h16'), 'source hamburger presentation survives');
// Capture replay may apply resting.html to the existing panel itself. Cover
// that boundary shape as well as the full artifact include path above.
$source = $files['parts/navigation.html'];
foreach (array('navigation-panel.html', 'menu-content.html', 'menu-fragment-1.html') as $part) {
    $source = str_replace('<!--#include virtual="/parts/' . $part . '" -->', $files['parts/' . $part], $source);
}
$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="utf-8" ?><body>' . $source . '</body>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
foreach (iterator_to_array($document->getElementsByTagName('div')) as $panel) {
    if (!$panel->hasAttribute('data-blocks-engine-capture-existing-panel')) continue;
    $native = $panel->firstElementChild;
    $panel->setAttribute('class', $native->getAttribute('class'));
    $panel->setAttribute('data-blocks-engine-capture-existing-panel', json_encode(array('resting' => array('style' => null, 'class' => $native->getAttribute('class'), 'hidden' => false))));
    $panel->setAttribute('id', 'modal-2');
    $panel->setAttribute('data-blocks-engine-capture-dialog-panel', 'modal-2');
    $panel->setAttribute('data-blocks-engine-capture-resting-panel', '');
    foreach ($document->getElementsByTagName('button') as $button) {
        if ($button->hasAttribute('data-blocks-engine-capture-dialog-trigger')) {
            $button->setAttribute('data-blocks-engine-capture-dialog-trigger', 'modal-2');
            $button->setAttribute('aria-controls', 'modal-2');
        }
        if ($button->hasAttribute('data-blocks-engine-capture-dialog-close')) $button->setAttribute('data-blocks-engine-capture-dialog-close', 'modal-2');
    }
    while ($native->firstChild) $panel->insertBefore($native->firstChild, $native);
    $panel->removeChild($native);
}
$projected = (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform($document->saveHTML(), array('runtime_dom_selectors' => array('[data-blocks-engine-capture-dialog-trigger]', '[data-blocks-engine-capture-dialog-panel]')))->toArray()['serialized_blocks'];
$assert(1 === substr_count($projected, '<!-- wp:navigation '), 'resting replay panel is one native navigation');
$assert(1 === substr_count($projected, '<!-- wp:navigation-submenu '), 'resting replay retains editable submenu');
$assert(!str_contains($projected, '<!-- wp:paragraph '), 'resting replay items are not paragraphs');
echo "WordPress submenu header passed\n";
