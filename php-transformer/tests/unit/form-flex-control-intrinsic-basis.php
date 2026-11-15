<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<form action="/subscribe" method="post"><div class="row">'
    . '<input id="email" type="email" name="email" style="width:100%">'
    . '<button id="intrinsic" style="margin-left:12px">Subscribe</button>'
    . '<button id="explicit" style="flex-basis:40%">Other</button>'
    . '</div></form>';
$result = (new HtmlTransformer())->transform($html, array('static_css' => '.row{display:flex;align-items:center}'))->toArray();
$members = $result['fallbacks'][0]['presentation_graph']['controls'] ?? array();
if ('auto' !== ($members[1]['control']['styles']['flex_basis'] ?? null)) {
    throw new RuntimeException('Native flex controls must retain intrinsic auto basis against provider full-row defaults.');
}
if ('40%' !== ($members[2]['control']['styles']['flex_basis'] ?? null)) {
    throw new RuntimeException('Explicit authored control sizing must win.');
}
echo "Form flex control intrinsic basis passed\n";
