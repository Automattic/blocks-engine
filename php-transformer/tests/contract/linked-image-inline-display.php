<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

$core = '.wp-block-image img{vertical-align:bottom;height:auto;max-width:100%}.wp-block-image > figure > a{display:inline-block}.aligncenter{display:table;margin-left:auto;margin-right:auto}';
$image = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="500" height="226"></svg>');
$markup = '<div class="wp-block-image"><figure class="aligncenter size-large"><a href="/destination" target="_blank"><img width="500" height="226" class="wp-image-21358" src="' . $image . '" alt=""></a></figure></div>';
$fixtures = array();
foreach (array(
    'inline' => array('inline', 'bottom', 'inline-block', ''),
    'block' => array('block', 'bottom', 'inline-block', '.wp-block-image img{display:block}'),
    'baseline' => array('inline', 'baseline', 'inline', '.wp-block-image img{vertical-align:baseline}.wp-block-image > figure > a{display:inline;vertical-align:baseline}'),
) as $case => [$expected, $alignment, $linkDisplay, $rule]) {
    $source = '<style>' . $core . $rule . '</style>' . ('baseline' === $case ? str_replace('aligncenter size-large', 'size-large', $markup) : $markup);
    $out = (new HtmlTransformer())->transform($source)->toArray();
    $css = implode("\n", array_column($out['assets'], 'content'));
    $dom = new DOMDocument();
    @$dom->loadHTML($out['serialized_blocks']);
    // Load Core last: source formatting must win by specificity, not load order.
    $cascade = new StaticCssCascade($dom, $css . '.wp-block-image a{display:inline-block}.wp-block-image img{vertical-align:bottom}');
    $style = $cascade->resolve($dom->getElementsByTagName('img')->item(0), array('display', 'vertical-align'), array());
    $linkStyle = $cascade->resolve($dom->getElementsByTagName('a')->item(0), array('display'), array());
    if ($expected !== ($style['display'] ?? '') || $alignment !== ($style['vertical-align'] ?? '') || $linkDisplay !== ($linkStyle['display'] ?? '')) {
        throw new RuntimeException($case . ': linked image lost source presentation: ' . json_encode(array($style, $linkStyle)));
    }
    $fixtures[$case] = array('display' => $expected, 'alignment' => $alignment, 'linkDisplay' => $linkDisplay, 'source' => $source, 'native' => '<style>' . $css . '.wp-block-image a{display:inline-block}.wp-block-image img{vertical-align:bottom}</style>' . $out['serialized_blocks']);
}
if ($path = getenv('LINKED_INLINE_ARTIFACT')) {
    file_put_contents($path, json_encode($fixtures, JSON_THROW_ON_ERROR));
}
echo "Linked image inline display contract passed.\n";
