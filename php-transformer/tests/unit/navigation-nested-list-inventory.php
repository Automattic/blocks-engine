<?php
declare(strict_types=1);

require (getenv('BE_TRANSFORMER_ROOT') ?: dirname(__DIR__, 2)) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = '<nav><div class="header-row"><a href="/"><h3>Northwind</h3></a>'
    . '<div><div><ul class="menu"><li><a href="/#services">Services</a></li>'
    . '<li><a href="/#gallery">Gallery</a></li>'
    . '<li><div><a href="https://instagram.com/example"><svg viewBox="0 0 20 20"><path d="M0 0h20v20H0z" /></svg></a></div></li>'
    . '<li><a href="/#contact">Contact</a></li>'
    . '</ul></div></div></div></nav>';
$markup = (new HtmlTransformer())->transform($source)->serializedBlocks;
foreach (array('Services', 'Gallery', 'Contact') as $label) {
    if (! str_contains($markup, '"label":"' . $label . '"')) {
        throw new RuntimeException('Nested list inventory lost ' . $label . ': ' . $markup);
    }
}
echo "navigation-nested-list-inventory passed\n";

$source = require dirname(__DIR__) . '/fixtures/nested-header-menu.php';
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = $result['serialized_blocks'];
if (3 !== substr_count($markup, '<!-- wp:navigation ') || ! str_contains($markup, '"overlayMenu":"always"')) {
    throw new RuntimeException('The controlled phone occurrence must survive beside its offscreen in-flow peer.');
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source, 'other.html' => str_replace('Editable section content.', 'Second route content.', $source))))->toArray()['source_reports']['wordpress_site_plan'];
if (2 !== count($plan['menus'])) throw new RuntimeException('Equivalent occurrences share one entity per distinct responsive destination set across routes.');
foreach ($plan['menus'] as $menu) {
    if (4 !== $menu['items']) throw new RuntimeException('Every entity must retain the three destinations and social item.');
}
foreach (array('Services', 'Gallery', 'Contact', 'Instagram') as $label) {
    if (! str_contains(implode('', array_column($plan['menus'], 'block_markup')), '"label":"' . $label . '"')) {
        throw new RuntimeException('The producer entity inventory lost ' . $label);
    }
}
// A rejected child menu remains content even when an unknown icon cannot be
// named through the existing social-service inventory.
$unknown = str_replace('https://instagram.com/example', 'https://example.org/profile', $source);
$unknownMarkup = (new HtmlTransformer())->transform($unknown)->serializedBlocks;
foreach (array('Services', 'Gallery', 'Contact', 'https://example.org/profile') as $content) {
    if (! str_contains($unknownMarkup, $content)) throw new RuntimeException('Rejected nested content was discarded: ' . $content);
}
echo "nested header producer/entity inventory passed\n";
