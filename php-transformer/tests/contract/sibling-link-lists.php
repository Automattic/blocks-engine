<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Links</title><style>
body{background:#222;margin:0}
.box{width:300px;margin:0 auto;padding:12px;text-align:center}
.links{display:flex;justify-content:center;gap:.5rem;padding:0;margin:0 0 .5rem;list-style:none}
.links>li>a{display:block}
.links.i1>li>a,.links.i2>li>a,.links.i3>li>a{color:#fff;text-decoration:underline}
</style></head><body><main><footer><div class="box">
<ul class="links i1"><li><a href="#a">Offer</a></li></ul>
<ul class="links i2"><li><a href="#b">Order</a></li></ul>
<ul class="links i3"><li><a href="https://example.com/">Terms</a></li></ul>
</div></footer></main></body></html>
HTML;
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = $result['serialized_blocks'];
// Each one-link box stays its own list, with its own styling scope; a single
// link is not a menu, so none of them becomes a navigation entity.
foreach (array('i1', 'i2', 'i3') as $class) {
    if (!preg_match('/<!-- wp:list \{[^\n]*"className":"[^"]*\b' . $class . '\b/', $markup)) {
        throw new RuntimeException('Missing editable source list scope: ' . $class);
    }
}
if (str_contains($markup, '<!-- wp:navigation')) {
    throw new RuntimeException('A one-link list became a navigation menu.');
}
if ($path = getenv('SIBLING_LINK_LISTS_ARTIFACT')) {
    $css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
    file_put_contents($path, json_encode(array('source' => $html, 'native' => '<!doctype html><style>' . $css . '</style>' . $markup), JSON_THROW_ON_ERROR));
}
echo "Sibling link lists contract passed.\n";
