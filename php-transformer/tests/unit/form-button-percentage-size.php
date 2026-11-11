<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cases = array(
    'inline maximum' => array('style="max-width:30%;padding:0 32px"', ''),
    'class maximum' => array('class="submit"', '.submit{max-width:30%;padding:0 32px}'),
    'responsive maximum' => array('class="submit"', '.submit{padding:0 32px}@media(min-width:768px){.submit{max-width:30%}}'),
    'variable maximum' => array('style="--cap:30%;max-width:var(--cap);padding:0 32px"', ''),
    'calculated width' => array('style="width:calc(30% - 8px)"', ''),
    'minimum width' => array('style="min-width:30%"', ''),
    'percentage height' => array('style="height:50%"', ''),
);
foreach ($cases as $name => [$attributes, $css]) {
    $html = '<form method="get" action="/subscribe"><div style="display:flex;width:752px"><input name="email" type="email" style="flex:1"><button type="submit" ' . $attributes . '>Registrarse</button></div></form>';
    $result = (new HtmlTransformer())->transform($html, array('static_css' => $css))->toArray();
    $markup = $result['serialized_blocks'];
    if (!str_contains($markup, 'wp:custom/authored-button') || str_contains($markup, 'wp-block-button__link')) {
        throw new RuntimeException($name . ': percentage-sized control gained a containing block.');
    }
    if (!str_contains($markup, '>Registrarse</button>') || ($result['source_reports']['wp_block_validity']['status'] ?? '') !== 'pass') {
        throw new RuntimeException($name . ': editable native button must retain its label and valid save shape.');
    }
}
$plain = (new HtmlTransformer())->transform('<form action="/subscribe"><input name="email"><button type="submit">Subscribe</button></form>')->toArray();
if (!str_contains($plain['serialized_blocks'], 'wp-block-button__link')) throw new RuntimeException('Controls without relative sizes retain core/button.');
echo "Percentage-sized form buttons keep their native containing block.\n";
