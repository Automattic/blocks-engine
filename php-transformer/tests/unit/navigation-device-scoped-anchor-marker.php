<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<style>';
foreach (array('desktop', 'mobile') as $device) {
    $html .= '[data-blocks-engine-capture-device-document="' . $device . '"] #comp-abc .style-typo__menuItem{padding:10px;border:1px solid #333;background:tan}';
}
$html .= '</style>';
foreach (array('desktop', 'mobile') as $device) {
    $html .= '<div data-blocks-engine-capture-device-document="' . $device . '"><nav id="comp-abc"><ul>';
    foreach (array('Home' => '/', 'About' => '/about') as $label => $url) {
        $html .= '<li><div class="menuItemWrapper"><a class="style-typo__menuItem" href="' . $url . '">' . $label . '</a></div></li>';
    }
    $html .= '</ul></nav></div>';
}
$result = (new HtmlTransformer())->transform($html, array())->toArray();
$markup = $result['serialized_blocks'] ?? '';
if (!preg_match_all('/<!-- wp:navigation-link (\{.*?\})\s*\/-->/s', $markup, $links)) {
    throw new RuntimeException('Expected native navigation links: ' . $markup);
}
foreach ($links[1] as $json) {
    $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $anchorClasses = $attrs['metadata']['blocksEngineNavigationAnchor']['className'] ?? '';
    if (!preg_match('/blocks-engine-attribute-[a-f0-9]+-\d+/', $anchorClasses, $marker)) {
        throw new RuntimeException('Expected device-scoped attribute marker on the anchor: ' . $json);
    }
    if (str_contains($attrs['className'] ?? '', 'blocks-engine-attribute-')) {
        throw new RuntimeException('Anchor attribute marker must not paint the LI: ' . $json);
    }
    if (str_contains($attrs['className'] ?? '', 'menuItemWrapper')) {
        throw new RuntimeException('Intermediate box identity must not move to the LI: ' . $json);
    }
    if ('menuItemWrapper' !== ($attrs['metadata']['blocksEngineNavigationAnchor']['boxes'][0]['className'] ?? '')) {
        throw new RuntimeException('Intermediate box must retain its source identity: ' . $json);
    }
    $css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
    if (!preg_match('/[^{}]*wp-block-navigation-item__content[^{}]*\{[^}]*padding:10px[^}]*border:1px solid #333[^}]*background:tan/', $css)) {
        throw new RuntimeException('Rendered anchors must still receive the authored box CSS.');
    }
}
echo "navigation-device-scoped-anchor-marker: passed\n";
