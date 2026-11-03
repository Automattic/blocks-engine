<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$links = '<li><a href="https://facebook.com/example" aria-label="Facebook"><svg width="16" height="16"></svg></a></li>'
    . '<li><a href="https://instagram.com/example" aria-label="Instagram"><svg width="16" height="16"></svg></a></li>';
$cases = array(
    array('.social{display:flex;justify-content:center}.social li{width:44px;height:49px}', 'center', 'huge'),
    array('.social{display:flex;justify-content:flex-end}.social a{width:36px;height:36px}', 'right', 'large'),
    array('.social{display:flex;justify-content:flex-start}.social svg{width:24px;height:24px}', 'left', 'normal'),
    array('.social{display:flex;justify-content:center}', 'center', 'small'),
);
foreach ($cases as [$css, $alignment, $size]) {
    $result = (new HtmlTransformer())->transform('<style>' . $css . '</style><ul class="social">' . $links . '</ul>')->toArray();
    $block = $result['blocks'][0] ?? array();
    $assert('core/social-links' === ($block['blockName'] ?? ''), 'Social row remains a native social-links block');
    $assert($alignment === ($block['attrs']['layout']['justifyContent'] ?? ''), 'Resolved row alignment uses native layout justification: ' . json_encode($block['attrs'] ?? null));
    $assert($size === ($block['attrs']['size'] ?? ''), 'Resolved item/glyph geometry selects the nearest icon preset: ' . json_encode($block['attrs'] ?? null));
    $assert(str_contains($result['serialized_blocks'] ?? '', 'has-' . $size . '-icon-size'), 'Saved list carries the selected size');
    $assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), 'Social row remains WordPress-valid');
}

$result = (new HtmlTransformer())->transform('<div style="text-align:center"><ul class="social">' . $links . '</ul></div>')->toArray();
$block = $result['blocks'][0] ?? array();
while ('core/social-links' !== ($block['blockName'] ?? '') && !empty($block['innerBlocks'])) {
    $block = $block['innerBlocks'][0];
}
$assert('center' === ($block['attrs']['layout']['justifyContent'] ?? ''), 'Inherited inline alignment uses native layout justification');
echo "social-links-alignment-size: ok\n";
