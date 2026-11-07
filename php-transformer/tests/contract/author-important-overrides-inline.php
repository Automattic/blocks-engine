<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A runtime loader writes a px box inline; an author `!important` rule
// overrides it, so the source never renders it. The import must not read the
// overridden box as the image's size. Inline `!important` still wins.
$image = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="84"></svg>');
$source = '<style>.fill{position:relative;overflow:hidden;aspect-ratio:16/9}.fill > img{width:100%!important;height:auto!important}</style>'
    . '<div class="fill"><img src="' . $image . '" alt="" style="position:relative;width:768px;height:645px"></div>'
    . '<div class="fill"><img src="' . $image . '" alt="" style="width:300px!important"></div>';
$out = (new HtmlTransformer())->transform($source)->toArray();
$markup = $out['serialized_blocks'];
$css = implode("\n", array_column($out['assets'], 'content'));
foreach ( array( '768', '645' ) as $overridden ) {
    if ( preg_match('/\b' . $overridden . 'px|"(?:width|height)":"?' . $overridden . '/', $markup . $css) ) {
        throw new RuntimeException("Overridden inline {$overridden}px reached the import:\n" . $markup);
    }
}
if ( ! str_contains($markup . $css, '300px') ) {
    throw new RuntimeException("Inline !important width was dropped:\n" . $markup);
}
echo "Author !important overrides inline contract passed.\n";
