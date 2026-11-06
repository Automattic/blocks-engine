<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

// #4482: the item wrapper owns the width; block boxes stretch down to
// multiple paint/content branches, with all label text outside normal flow.
$source = <<<'HTML'
<html><head><style>
nav{display:flex;position:relative}
.inline-block{display:inline-block}
.instance-container{position:relative}
.slot{position:absolute;left:370px;top:7px}
.data-list-item{position:absolute;height:100%}
.design-element-link-part{display:block;height:100%}
.element-main-box{position:relative;height:100%}
.element-background-layer{position:absolute;left:0;right:0;top:0;bottom:0}
.element-border-box,.element-content-box{position:relative;height:100%}
.content-container{position:relative;height:100%}
.absolute-fill{position:absolute;left:0;right:0;top:0;bottom:0}
.skin-instance-controller{width:100%;height:100%}
.bottom-border{position:absolute;box-sizing:border-box;width:100%;height:100%;left:0;top:0;border-style:solid;border-width:0 0 4px;border-color:rgb(96,112,119)}
.caption{position:absolute;left:20px;top:15px}
</style></head><body><header><nav>
<span class="slot inline-block instance-container" style="margin-left:10px;width:125px;height:53px"><div class="design-element element-size-box skin-element data-list-item" style="right:0px;z-index:2;left:0px"><a class="design-element-link-part no-decorations internal-link" href="/services/"><div class="element-main-box design-element-main-box" style="z-index:2"><div class="element-background-layer element-border-target"></div><div class="element-border-box element-border-target element-padding-box" style="padding:0px"><div class="element-content-box"><div class="content-container element-size-box" style="min-height:53px"><div class="absolute-fill element-content"><span class="inline-block instance-container skin-instance-controller"><div class="element-main-box"><div class="bottom-border"></div><span class="caption">Services</span></div></span></div></div></div></div></div></a></div></span>
<span class="slot inline-block instance-container" style="margin-left:10px;width:125px;height:53px"><div class="design-element element-size-box skin-element data-list-item" style="right:0px;z-index:2;left:0px"><a class="design-element-link-part no-decorations internal-link" href="/contact/"><div class="element-main-box design-element-main-box" style="z-index:2"><div class="element-background-layer element-border-target"></div><div class="element-border-box element-border-target element-padding-box" style="padding:0px"><div class="element-content-box"><div class="content-container element-size-box" style="min-height:53px"><div class="absolute-fill element-content"><span class="caption">Contact</span></div></div></div></div></div></a></div></span>
</nav></header><main><p>Body</p></main></body></html>
HTML;
$compile = static fn(string $html): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$wrappedArtifact = $compile($source);
// Inert capture support siblings make wrapper recognition descend to the
// anchors, rather than treating the outer sizing wrappers as menu items.
$source = str_replace(array('<div class="design-element element-size-box', '<a class="design-element-link-part'), array('<strong hidden class="title"></strong><div class="design-element element-size-box', '<strong hidden class="title"></strong><a class="design-element-link-part'), $source);
$source = str_replace(array('slot inline-block', 'design-element element-size-box'), array('nav slot inline-block', 'nav design-element element-size-box'), $source);
$source = str_replace('</style>', '[hidden]{display:none}</style>', $source);
$artifact = $compile($source);
$native = StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($artifact['serialized_blocks']);
$css = implode("\n", array_column($artifact['assets'], 'content'));
$dom = new DOMDocument();
@$dom->loadHTML($native);
$cascade = new StaticCssCascade($dom, $css);
$xpath = new DOMXPath($dom);
$find = static fn(string $class) => $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," ' . $class . ' ")]');
$items = $find('wp-block-navigation-item');
if (2 !== $items->length) throw new RuntimeException('Expected two native navigation items');
foreach ($items as $item) {
    $style = $cascade->resolve($item, array('width', 'flex-shrink'), array());
    if ('125px' !== ($style['width'] ?? '') || '0' !== ($style['flex-shrink'] ?? '')) throw new RuntimeException('Planned item width must be 125px without flex shrinking');
    if (str_contains($item->getAttribute('class'), 'blocks-engine-navigation-anchor-')) throw new RuntimeException('Regression fixture must exercise the anchor-as-item path, not a split anchor subject');
}
foreach ($find('wp-block-navigation-item__content') as $anchor) {
    if ('125px' !== ($cascade->resolve($anchor, array('width'), array())['width'] ?? '')) throw new RuntimeException('Anchor must carry source width');
}
foreach ($find('wp-block-navigation-item__label') as $label) {
    $style = $cascade->resolve($label, array('width', 'display'), array());
    if ('100%' !== ($style['width'] ?? '') || 'block' !== ($style['display'] ?? '')) throw new RuntimeException('Label must fill the definite anchor width');
}
$border = $find('bottom-border')->item(0);
if (!$border instanceof DOMElement) throw new RuntimeException('Active underline must survive');
$borderStyle = $cascade->resolve($border, array('width', 'border-width', 'border-color'), array());
if ('100%' !== ($borderStyle['width'] ?? '') || '0 0 4px' !== ($borderStyle['border-width'] ?? '')) throw new RuntimeException('Active bottom border must fill its sizing box');
// Follow the underline's containing boxes to the definite carrier. Auto block
// boxes and opposing zero insets stretch; no intrinsic/shrink-to-fit gap may
// interrupt the 125px width. This also catches retained source wrappers.
for ($box = $border->parentNode; $box instanceof DOMElement; $box = $box->parentNode) {
    $style = $cascade->resolve($box, array('width', 'display', 'position', 'left', 'right'), array());
    $width = $style['width'] ?? 'auto';
    if ('125px' === $width) break;
    $stretch = '100%' === $width || (in_array($width, array('auto', ''), true) && (
        in_array($style['position'] ?? '', array('absolute', 'fixed'), true)
            ? in_array($style['left'] ?? '', array('0', '0px'), true) && in_array($style['right'] ?? '', array('0', '0px'), true)
            : in_array($style['display'] ?? (in_array(strtolower($box->tagName), array('div', 'a'), true) ? 'block' : 'inline'), array('block', 'flex', 'grid'), true)
    ));
    if (!$stretch) throw new RuntimeException('Underline must stretch through every box to the 125px carrier: ' . $box->getAttribute('class'));
}
if (!$box instanceof DOMElement) throw new RuntimeException('Underline must have a definite 125px containing box');
// In-flow text already gives the label intrinsic width. A percentage-only
// wrapper or a non-stretching intermediate box is not evidence of 125px.
foreach (array(
    str_replace('.absolute-fill{position:absolute;', '.absolute-fill{position:relative;', str_replace('.caption{position:absolute;', '.caption{position:relative;', $source)),
    str_replace('width:125px', 'width:50%', $source),
    str_replace(array('width:125px', 'nav{display:flex;'), array('width:auto', 'nav{width:125px;display:flex;'), $source),
    str_replace('</style>', 'a{width:90px}</style>', $source),
    str_replace('right:0px;z-index:2;left:0px', 'width:80%;z-index:2;left:0px', $source),
) as $control) {
    $controlArtifact = $compile($control);
    $controlCss = implode("\n", array_column($controlArtifact['assets'], 'content'));
    if (preg_match('/__label\{display:block;width:100%\}/', $controlCss)) throw new RuntimeException('Intrinsic or unproven labels must not acquire width carry');
}
// The wrapper-as-item path still retains its established geometry too.
$wrappedDom = new DOMDocument();
@$wrappedDom->loadHTML(StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($wrappedArtifact['serialized_blocks']));
$wrappedCascade = new StaticCssCascade($wrappedDom, implode("\n", array_column($wrappedArtifact['assets'], 'content')));
foreach ((new DOMXPath($wrappedDom))->query('//*[contains(concat(" ",normalize-space(@class)," ")," wp-block-navigation-item ")]') as $item) {
    $placement = $wrappedCascade->resolve($item, array('height', 'left', 'top', 'width'), array());
    if ('53px' !== ($placement['height'] ?? '') || '370px' !== ($placement['left'] ?? '') || '7px' !== ($placement['top'] ?? '') || '125px' !== ($placement['width'] ?? '')) throw new RuntimeException('Wrapper item sizing and placement must remain unchanged');
}
echo "Absolute navigation labels retain their source sizing width\n";
