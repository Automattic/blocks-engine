<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

// Classic image markup: wp-block-image belongs to the outer div, not figure.
$markup = '<div class="x"><div class="wp-block-image"><figure class="aligncenter size-large"><a href="/image"><img width="500" height="226" src="/image.png" alt=""></a></figure></div></div>';
$core = '.wp-block-image{margin:0 0 1em}.wp-block-image .aligncenter{display:table;margin-left:auto;margin-right:auto}.wp-block-image img{vertical-align:bottom}.wp-block-image > figure > a{display:inline-block}';
foreach (array(
    '.x .wp-block-image > a > img{display:block}',
    '.x .wp-block-image > a{display:block!important}',
    '.x .wp-block-image > a > img{display:block!important}@media(min-width:1px){.x .wp-block-image > a > img{display:block!important}}',
) as $rule) {
    $out = (new HtmlTransformer())->transform('<style>' . $core . $rule . '</style>' . $markup)->toArray();
    $css = '';
    foreach ($out['assets'] ?? array() as $asset) {
        if ('css' === ($asset['kind'] ?? '')) $css .= $asset['content'] ?? '';
    }
    $dom = new DOMDocument();
    @$dom->loadHTML($out['serialized_blocks']);
    // The source sheet is also loaded by native WordPress site plans.
    $projectedCopy = str_replace('.x .wp-block-image', ':where(.blocks-engine-image).wp-block-image', $rule);
    $cascade = new StaticCssCascade($dom, $core . $rule . $projectedCopy . $css);
    foreach (array('img', 'a') as $tag) {
        $element = $dom->getElementsByTagName($tag)->item(0);
        if (!$element instanceof DOMElement) throw new RuntimeException('Missing converted ' . $tag);
        $style = $cascade->resolve($element, array('display'), array());
        $display = $style['display'] ?? 'inline';
        if ('block' === $display) throw new RuntimeException('Source-false selector gained a converted ' . $tag . ' subject: ' . $css);
    }
    // With an inline, bottom-aligned 226px image the inline-block link has a
    // line box baseline, rather than the margin-edge baseline of a block img.
    $image = $dom->getElementsByTagName('img')->item(0);
    $style = $cascade->resolve($image, array('vertical-align', 'height'), array());
    if ('bottom' !== ($style['vertical-align'] ?? '') || '226px' !== ($style['height'] ?? '')) {
        throw new RuntimeException('Converted image lost its source line-box geometry: ' . json_encode($style));
    }
}
// A rule that really selected the source image must not be neutralized.
$out = (new HtmlTransformer())->transform('<style>.x img{display:block}</style>' . $markup)->toArray();
$css = '';
foreach ($out['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? '')) $css .= $asset['content'] ?? '';
}
$dom = new DOMDocument();
@$dom->loadHTML($out['serialized_blocks']);
$style = (new StaticCssCascade($dom, $css))->resolve($dom->getElementsByTagName('img')->item(0), array('display'), array());
if ('block' !== ($style['display'] ?? '')) throw new RuntimeException('A source-true image rule was neutralized');
echo "Linked image source selector truth contract passed.\n";
