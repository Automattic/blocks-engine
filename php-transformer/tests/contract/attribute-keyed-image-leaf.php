<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

// A static data predicate keys an image's presentation. The native image does
// not carry the attribute; its marker moves to the figure, and the image's own
// declarations must reach the img through that marker.
$image = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="84"></svg>');
$source = '<style>.fill{position:relative;overflow:hidden;aspect-ratio:16/9}'
    . '.fill > img[data-fill="width"]{width:100%!important;height:auto!important;top:50%!important;transform:translateY(-50%)!important}</style>'
    . '<div class="fill"><img data-fill="width" src="' . $image . '" alt=""></div>';
$out = (new HtmlTransformer())->transform($source)->toArray();
$css = implode("\n", array_column($out['assets'], 'content'));
$dom = new DOMDocument();
@$dom->loadHTML($out['serialized_blocks']);
$style = (new StaticCssCascade($dom, '.wp-block-image img{height:auto;max-width:100%}' . $css))
    ->resolve($dom->getElementsByTagName('img')->item(0), array('transform'), array());
if ('translateY(-50%)' !== preg_replace('/\s*!important$/', '', (string) ($style['transform'] ?? ''))) {
    throw new RuntimeException('Attribute-keyed image declarations did not reach the native image: ' . json_encode($style));
}
echo "Attribute-keyed image leaf contract passed.\n";
