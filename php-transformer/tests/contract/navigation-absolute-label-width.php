<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

// #4482: the only width lives below an absolute layer, not on the link.
$source = <<<'HTML'
<html><head><style>
nav{display:flex;position:relative}
.slot{position:absolute;left:370px;top:7px;height:53px}
.element-main-box{position:relative;height:100%}
.element-size-box{position:absolute;width:125px;height:53px;left:0;top:0}
.content-container{position:absolute;width:125px;height:53px;left:0;top:0}
.bottom-border{position:absolute;box-sizing:border-box;width:100%;height:100%;left:0;top:0;border-style:solid;border-width:0 0 4px;border-color:rgb(96,112,119)}
.caption{position:absolute;left:20px;top:15px}
</style></head><body><header><nav>
<div class="slot"><a href="/services/"><div class="element-main-box"><div class="element-size-box"><div class="content-container"><div class="bottom-border"></div><span class="caption">Services</span></div></div></div></a></div>
<div class="slot"><a href="/contact/"><div class="element-main-box"><div class="element-size-box"><div class="content-container"><span class="caption">Contact</span></div></div></div></a></div>
</nav></header><main><p>Body</p></main></body></html>
HTML;
$compile = static fn(string $html): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
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
    $width = $cascade->resolve($item, array('width'), array())['width'] ?? '';
    if ('125px' !== $width) throw new RuntimeException('Planned item width must be 125px, got ' . $width);
}
foreach ($find('wp-block-navigation-item__content') as $anchor) {
    if ('125px' !== ($cascade->resolve($anchor, array('width'), array())['width'] ?? '')) throw new RuntimeException('Anchor must carry source width');
}
foreach ($find('content-container') as $box) {
    if ('53px' !== ($cascade->resolve($box, array('height'), array())['height'] ?? '')) throw new RuntimeException('Source label height must remain 53px');
}
$border = $find('bottom-border')->item(0);
if (!$border instanceof DOMElement) throw new RuntimeException('Active underline must survive');
// The underline fills a definite 125px content box, itself carried by the
// 125px item. Its percentage can no longer resolve against a zero-width box.
$borderStyle = $cascade->resolve($border, array('width'), array());
$parentStyle = $cascade->resolve($border->parentNode, array('width'), array());
if ('100%' !== ($borderStyle['width'] ?? '') || '125px' !== ($parentStyle['width'] ?? '')) throw new RuntimeException('Active bottom border must span the planned 125px box');
// Ordinary in-flow content and explicit link sizing must not be replaced by
// inferred descendant geometry.
foreach (array(
    str_replace('position:absolute;width:125px', 'position:relative;width:125px', $source),
    str_replace('</style>', 'a{width:90px}</style>', $source),
    str_replace('width:125px', 'width:50%', $source),
) as $control) {
    $controlArtifact = $compile($control);
    $controlCss = implode("\n", array_column($controlArtifact['assets'], 'content'));
    if (preg_match('/__label\{display:block;width:100%\}/', $controlCss)) throw new RuntimeException('Unproven or explicitly sized labels must not acquire width carry');
}
echo "Absolute navigation labels retain their source sizing width\n";
