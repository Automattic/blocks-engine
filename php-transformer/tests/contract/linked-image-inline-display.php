<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

$core = '.wp-block-image img{vertical-align:bottom;height:auto;max-width:100%}.wp-block-image > figure > a{display:inline-block}.aligncenter{display:table;margin-left:auto;margin-right:auto}';
$image = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="500" height="226"></svg>');
$markup = '<div class="wp-block-image"><figure class="aligncenter size-large"><a href="/destination" target="_blank"><img width="500" height="226" class="wp-image-21358" src="' . $image . '" alt=""></a></figure></div>';
$fixtures = array();
foreach (array('inline' => '', 'block' => '.wp-block-image img{display:block}') as $expected => $rule) {
    $source = '<style>' . $core . $rule . '</style>' . $markup;
    $out = (new HtmlTransformer())->transform($source)->toArray();
    $css = implode("\n", array_column($out['assets'], 'content'));
    $dom = new DOMDocument();
    @$dom->loadHTML($out['serialized_blocks']);
    $style = (new StaticCssCascade($dom, $css))->resolve($dom->getElementsByTagName('img')->item(0), array('display', 'vertical-align'), array());
    if ($expected !== ($style['display'] ?? '') || 'bottom' !== ($style['vertical-align'] ?? '')) {
        throw new RuntimeException('Linked image lost source presentation: ' . json_encode($style));
    }
    $fixtures[$expected] = array('source' => $source, 'native' => '<style>' . $css . '</style>' . $out['serialized_blocks']);
}
if ($path = getenv('LINKED_INLINE_ARTIFACT')) {
    file_put_contents($path, json_encode($fixtures, JSON_THROW_ON_ERROR));
}
echo "Linked image inline display contract passed.\n";
