<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// Exercise the public projection path: the hidden dialog's menu replaces the
// opener, so the landmark contains navigation, not the original button.
$fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/parity/html-nav-hidden-dialog-projects-to-control.json'), true, 512, JSON_THROW_ON_ERROR);
$html = str_replace(
    array('<div class="mobile-control-slot">', '</button></div>'),
    array('<nav class="control-landmark"><div class="mobile-control-slot">', '</button></div></nav>'),
    $fixture['input']['content']
);
$result = (new HtmlTransformer())->transform($html, $fixture['input']['options'])->toArray();
$markup = $result['serialized_blocks'];
if (str_contains($markup, '/layout-shell') || !str_contains($markup, '"tagName":"nav"') || !str_contains($markup, '"overlayMenu":"mobile"')) {
    throw new RuntimeException('Projected responsive navigation must retain native landmark and control-box Groups: ' . $markup);
}
$runtime = new Runtime();
$document = new DOMDocument();
@$document->loadHTML($runtime->serializeBlocks($runtime->parseBlocks($markup)));
$xpath = new DOMXPath($document);
if (1 !== $xpath->query('//nav[contains(@class,"control-landmark")]/div[contains(@class,"mobile-control-slot")]')->length) {
    throw new RuntimeException('Native save must retain the opener box ancestry.');
}

// Ordinary link menus must keep their established shell and style contract.
$fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/parity/html-nav-menu-flat-list.json'), true, 512, JSON_THROW_ON_ERROR);
$menu = (new HtmlTransformer())->transform($fixture['input']['content'])->toArray();
if ('custom/layout-shell' !== $menu['blocks'][0]['blockName']
    || 2 !== count($menu['blocks'][0]['innerBlocks'])
    || 'core/navigation' !== $menu['blocks'][0]['innerBlocks'][1]['blockName']) {
    throw new RuntimeException('Converted flat menus must retain their existing shell and sibling links.');
}
echo "Native navigation wrapper chain passed\n";
