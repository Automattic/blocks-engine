<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$image = '<img src="image.jpg" width="473" height="315" alt="Captured image">';
// Plain block wrappers around this fixed-size image have coincident border
// boxes. Explicit initial values must not allocate one group per level.
$cases = array(
    'four plain wrappers' => array('', str_repeat('<div>', 4) . $image . str_repeat('</div>', 4), 0),
    'explicit neutral styles' => array('', str_repeat('<div style="display:block;position:static;opacity:1;overflow:visible;transform:none;box-shadow:none">', 4) . $image . str_repeat('</div>', 4), 0),
    'rounded clipping' => array('', '<div style="overflow:hidden;border-radius:12px">' . $image . '</div>', 1),
    'padding' => array('', '<div style="padding:8px">' . $image . '</div>', 1),
    'descendant selector' => array('.w > img{object-fit:cover}', '<div class="w">' . $image . '</div>', 1),
    'descendant below promoted group' => array('.w > .child > img{object-fit:cover}', '<div class="w"><div class="child" style="padding:1px">' . $image . '</div></div>', 2),
    'author class can transfer' => array('.w{opacity:1}', '<div class="w">' . $image . '</div>', 0),
    'shadow' => array('.w{box-shadow:0 0 4px red}', '<div class="w">' . $image . '</div>', 1),
    'aria boundary' => array('', '<div aria-label="Photo">' . $image . '</div>', 1),
    'layout boundary' => array('', '<div style="display:flex;gap:8px;align-items:center">' . $image . '</div>', 1),
);
foreach ($cases as $label => [$css, $html, $groups]) {
    $result = (new HtmlTransformer())->transform('<style>' . $css . '</style>' . $html)->toArray();
    $markup = $result['serialized_blocks'];
    if ($groups !== substr_count($markup, '<!-- wp:group ') || 1 !== substr_count($markup, '<figure')) {
        throw new RuntimeException($label . ': expected ' . $groups . ' groups and one figure: ' . $markup);
    }
    if ('author class can transfer' === $label && ! preg_match('/<figure[^>]*class="[^"]*\bw\b/', $markup)) {
        throw new RuntimeException('The surviving figure retains the projected author class.');
    }
    if ('pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '')) {
        throw new RuntimeException($label . ': block validity must pass.');
    }
}
echo "coincident-image-wrappers: ok\n";
