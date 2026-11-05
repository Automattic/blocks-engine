<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Issue #4464: an unrelated mobile button must not own either captured button's paint.
$button = <<<'HTML'
<a class="maglink" href="/our-coop/"><div class="rmwidget widget-button" data-id="b1" style="z-index:310;border-radius:100px;left:0;top:0;width:155px;height:21px"><div class="common-button" data-id="b1" style="flex-direction:row"><div class="icon" style="display:none"></div><div class="text" style="display:inline-block;line-height:21px;height:21px">Our Cooperative</div></div></div></a>
HTML;
$html = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Buttons</title>
<style>
.rmwidget.widget-button .common-button[data-id="b1"]{background-color:rgba(255,255,255,0);border-radius:100px;border-width:2px;border-color:rgb(153, 5, 143);color:rgb(153, 5, 143);font-family:Montserrat;font-weight:700;font-size:12px}
.common-button{display:flex;border-style:solid}
.common-button .text{padding:0;margin:0;border:none;background-color:transparent;font-family:inherit;font-weight:inherit;color:inherit;font-size:inherit}
.data-blocks-engine-capture-mobile-document{display:none!important}
@media(max-width:767px){.data-blocks-engine-capture-desktop-document{display:none!important}.data-blocks-engine-capture-mobile-document{display:contents!important}}
</style>
<style media="(max-width:767px)">
:where(.data-blocks-engine-capture-mobile-document) .rmwidget.widget-button .common-button[data-id="zz"]{background-color:rgba(255,255,255,0);border-radius:5px;border-width:0px;border-color:rgb(0,0,0);color:rgb(255,255,255)}
</style></head><body>
HTML;
foreach (array('desktop', 'mobile') as $device) {
    $html .= '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-document-scope><main>' . $button . '</main></div>';
}
$html .= '</body></html>';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray();
preg_match_all('/<!-- wp:button (\{.*?\}) -->/', $result['serialized_blocks'], $matches);
if (2 !== count($matches[1])) {
    throw new RuntimeException('Both captured links must become core/button blocks.');
}
foreach ($matches[1] as $attributes) {
    $style = json_decode($attributes, true, 512, JSON_THROW_ON_ERROR)['style'] ?? array();
    foreach (array('width' => '2px', 'style' => 'solid', 'color' => 'rgb(153, 5, 143)', 'radius' => '100px') as $property => $expected) {
        if ($expected !== ($style['border'][$property] ?? null)) {
            throw new RuntimeException('Unmatched media rule changed button border ' . $property . ': ' . $attributes);
        }
    }
    if ('rgb(153, 5, 143)' !== ($style['color']['text'] ?? null)) {
        throw new RuntimeException('Unmatched media rule changed button text color: ' . $attributes);
    }
}
echo "Unmatched media button paint passed\n";
