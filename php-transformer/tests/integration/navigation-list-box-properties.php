<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$flatten = static function(array $blocks) use (&$flatten): array {
    $out = array();
    foreach ($blocks as $block) { $out[] = $block; array_push($out, ...$flatten($block['innerBlocks'] ?? array())); }
    return $out;
};
libxml_use_internal_errors(true);
$out = array();
foreach (require dirname(__DIR__) . '/fixtures/navigation-list-box-properties.php' as $name => $source) {
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $nav = array_values(array_filter($flatten($result['blocks']), static fn(array $b): bool => 'core/navigation' === $b['blockName']))[0];
    $css = '';
    foreach ($result['assets'] as $asset) if ('css' === ($asset['kind'] ?? '')) $css .= $asset['content'];
    $dom = new DOMDocument();
    $dom->loadHTML($source);
    $list = $dom->getElementsByTagName('ul')->item(0);
    // Preserve source ancestors, replacing only the lowered menu with Core's
    // native host/container shape. No source CSS is used by the candidate.
    foreach (iterator_to_array($dom->getElementsByTagName('style')) as $style) $style->parentNode->removeChild($style);
    $parent = $list->parentNode;
    $replacement = $dom->createElement('nav');
    $replacement->setAttribute('class', 'wp-block-navigation ' . $nav['attrs']['className']);
    $list->setAttribute('class', 'wp-block-navigation__container');
    $parent->replaceChild($replacement, $list);
    $replacement->appendChild($list);
    $style = $dom->createElement('style');
    // Core's navigation row wraps unless the block's flex layout says nowrap.
    $wrap = 'nowrap' === ($nav['attrs']['layout']['flexWrap'] ?? '') ? 'nowrap' : 'wrap';
    $style->appendChild($dom->createTextNode('.wp-block-navigation{display:flex;flex-wrap:' . $wrap . '}.wp-block-navigation-item{display:flex;flex-shrink:0}' . $css));
    $dom->getElementsByTagName('head')->item(0)->appendChild($style);
    foreach ($dom->getElementsByTagName('li') as $item) $item->setAttribute('class', 'wp-block-navigation-item');
    $out[$name] = array('source' => $source, 'candidate' => $dom->saveHTML());
}
echo json_encode($out, JSON_THROW_ON_ERROR);
