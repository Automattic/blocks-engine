<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// An ordinary utility-styled div is not necessarily a high-value style
// boundary. Recognition must still see that its caption is out of flow.
$html = '<style>.flex{display:flex}.relative{position:relative}.items-center{align-items:center}.frame{width:100%;aspect-ratio:3/4;overflow:hidden}.photo{width:100%;height:100%;object-fit:cover}.absolute{position:absolute}.p-6{padding:24px}</style><div class="reveal relative flex items-center"><div class="relative frame"><img src="/photo.jpg" class="photo" alt="Portrait"></div><div class="absolute p-6"><p>A quiet afternoon.</p><p>The founder</p></div></div>';
$result = (new HtmlTransformer())->transform($html, array())->toArray();
$serialized = $result['serialized_blocks'];
if (str_contains($serialized, 'wp:media-text')) {
    throw new RuntimeException('An out-of-flow caption must not fabricate a second media/text pane');
}
if (!str_contains($serialized, 'wp:image') || !str_contains($serialized, 'A quiet afternoon.')) {
    throw new RuntimeException('The image and editable caption must survive ordinary source layout lowering');
}
echo "media-text-stylesheet-overlay: ok\n";
