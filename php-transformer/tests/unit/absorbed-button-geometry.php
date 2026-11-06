<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>w</title><style>.rmwidget.widget-button .common-button[data-id="b1"]{border-radius:100px;border-width:2px;border-color:rgb(153,5,143);font-family:Arial;font-weight:700;color:rgb(153,5,143);font-size:12px}
.rmwidget{position:relative}
.common-button{position:absolute;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;border-style:solid}
.common-button .text{padding:0;margin:0;border:none;background-color:transparent;font-family:inherit;font-weight:inherit;color:inherit;font-size:inherit}</style></head><body><main><a class="maglink" href="/coop/"><div class="rmwidget widget-button" data-id="b1" style="border-radius:100px;width:155px;height:21px"><div class="common-button" data-id="b1" style="flex-direction:row"><div class="text" style="display:inline-block;line-height:21px;height:21px">Our Cooperative</div></div></div></a></main></body></html>
HTML;
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && 'editor' !== ($asset['stylesheet_target'] ?? '')), 'content'));
if (1 !== substr_count($markup, '<!-- wp:button ') || str_contains($markup, '<!-- wp:html')) {
    throw new RuntimeException('The absorbed pill must remain one native button: ' . $markup);
}
if (!preg_match('/>\\.wp-block-button__link\\{box-sizing:content-box;width:155px;height:21px;padding:0;flex-direction:row;align-items:center;justify-content:center\\}/', $css)) {
    throw new RuntimeException('The painted link must own the absorbed dimensions: ' . $css);
}
// An authored border-box surface must not gain the border a second time.
$borderBoxSource = str_replace('border-style:solid}', 'border-style:solid;box-sizing:border-box;padding:3px 7px}', $source);
$borderBox = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $borderBoxSource)))->toArray();
$borderBoxCss = implode("\n", array_column($borderBox['assets'], 'content'));
if (!str_contains($borderBoxCss, 'box-sizing:border-box;width:155px;height:21px;padding:3px 7px')) {
    throw new RuntimeException('Authored surface box sizing and padding must survive absorption: ' . $borderBoxCss);
}
$plain = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<a href="/" style="border:2px solid red;border-radius:100px">Plain</a>')))->toArray();
$plainCss = implode("\n", array_column($plain['assets'], 'content'));
if (str_contains($plainCss, 'box-sizing:content-box;') || str_contains($plainCss, 'padding:0;flex-direction:')) {
    throw new RuntimeException('A button without an absorbed sized surface must not receive surface geometry.');
}
// The inner flex surface has left:0/top:0, but the absorbed wrapper owns
// placement. Two pills must remain out of flow inside their containing bar.
$positionedSource = str_replace('.rmwidget{position:relative}', '.bar{position:relative;height:60px}.rmwidget{position:absolute}', $source);
$positionedSource = str_replace('<main>', '<main><div class="bar">', $positionedSource);
$positionedSource = str_replace('border-radius:100px;width:155px', 'border-radius:100px;left:700px;top:10px;width:155px', $positionedSource);
$secondButton = '<a class="maglink" href="/about/"><div class="rmwidget widget-button" data-id="b1" style="border-radius:100px;left:1000px;top:10px;width:118px;height:21px"><div class="common-button" data-id="b1" style="flex-direction:row"><div class="text" style="display:inline-block;line-height:21px;height:21px">About us</div></div></div></a>';
$positionedSource = str_replace('</main>', $secondButton . '</div><p>Body text after the bar.</p></main>', $positionedSource);
$positioned = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $positionedSource)))->toArray();
$positionedMarkup = $positioned['serialized_blocks'];
$positionedCss = implode("\n", array_column(array_filter($positioned['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && 'editor' !== ($asset['stylesheet_target'] ?? '')), 'content'));
if (2 !== substr_count($positionedMarkup, '<!-- wp:button ') || str_contains($positionedMarkup, '<!-- wp:html')) {
    throw new RuntimeException('Positioned pills must remain two native buttons: ' . $positionedMarkup);
}
foreach (array('700px', '1000px') as $left) {
    if (!str_contains($positionedCss, '>.wp-block-button__link{position:absolute;top:10px;left:' . $left . '}')) {
        throw new RuntimeException('The painted link must own the wrapper position, not the inner surface offsets: ' . $positionedCss);
    }
}
if (in_array('--fixture', $argv, true)) {
    echo json_encode(array('source' => $source, 'markup' => $markup, 'css' => $css, 'positioned' => array('source' => $positionedSource, 'markup' => $positionedMarkup, 'css' => $positionedCss)));
} else {
    echo "Absorbed button geometry passed\n";
}
