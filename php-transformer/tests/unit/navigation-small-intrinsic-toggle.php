<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// The source's small icon supplies the control's intrinsic box. A fallback
// touch-target minimum must not enlarge it and push the following page down.
$result = (new HtmlTransformer())->transform(
    '<style>.glyph{width:20px;height:20px}</style><header>'
    . '<button aria-label="Menu" aria-expanded="false"><span class="glyph"></span></button>'
    . '<nav aria-label="Site" style="display:none"><a href="/">Home</a></nav></header>'
)->toArray();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
if (!str_contains($result['serialized_blocks'], '<!-- wp:navigation')) {
    throw new RuntimeException('Expected native editable navigation');
}
if (str_contains($css, 'min-height:44px!important') || str_contains($css, 'min-width:44px!important')) {
    throw new RuntimeException('Intrinsic icon box must not acquire a fallback touch-target floor');
}
if (!str_contains($css, 'width:20px!important') || !str_contains($css, 'height:20px!important')) {
    throw new RuntimeException('Core toggle must retain the source intrinsic dimensions');
}
echo "Small intrinsic navigation toggle passed\n";
