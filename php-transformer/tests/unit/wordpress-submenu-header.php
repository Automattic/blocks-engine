<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Real captured navigation subtree with SSI parts expanded and captured CSS.
// Core's mobile overlay replaces the captured existing-panel runtime.
$html = file_get_contents(dirname(__DIR__) . '/fixtures/wordpress-submenu-header.html');
$result = (new HtmlTransformer())->transform($html, array(
    'runtime_dom_selectors' => array('.wp-block-navigation__responsive-container-open'),
))->toArray();
$markup = $result['serialized_blocks'];
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
echo "WordPress submenu header passed\n";
