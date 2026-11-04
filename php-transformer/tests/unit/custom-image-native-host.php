<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Capture variants retain independent CSS identities and sizing boxes, while
// their media must remain editable with the native image block.
$result = (new HtmlTransformer())->transform('<style>media-frame{display:inline-block;overflow:hidden;width:100%;height:100%}</style><a href="/about/"><media-frame id="photo--mobile" data-image-info="{}"><img src="photo.jpg" width="320" height="180" alt="Photo"></media-frame></a>')->toArray();
$markup = $result['serialized_blocks'];
foreach (array('<!-- wp:image ', 'id="photo--mobile"', 'href="/about/"', 'photo.jpg', 'data-image-info="{}"') as $expected) {
    if (!str_contains($markup, $expected)) {
        fwrite(STDERR, 'Missing native media or retained host identity: ' . $expected . PHP_EOL);
        exit(1);
    }
}
if (str_contains($markup, '/responsive-media')) {
    fwrite(STDERR, 'Image-only custom host still uses responsive-media' . PHP_EOL);
    exit(1);
}
echo "Custom image native host regression passed\n";
