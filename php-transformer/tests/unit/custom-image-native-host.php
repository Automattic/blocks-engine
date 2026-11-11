<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Capture variants retain independent CSS identities and sizing boxes, while
// their media must remain editable with the native image block.
$result = (new HtmlTransformer())->transform('<style>media-frame{display:inline-block;overflow:hidden;width:100%;height:100%}</style><a href="/about/"><media-frame id="photo--mobile" data-image-info="{}"><img src="photo.jpg" width="320" height="180" alt="Photo" style="object-fit:contain;object-position:20% 30%"></media-frame></a>')->toArray();
$markup = $result['serialized_blocks'];
foreach (array('<!-- wp:image ', 'id="photo--mobile"', 'href="/about/"', 'photo.jpg') as $expected) {
    if (!str_contains($markup, $expected)) {
        fwrite(STDERR, 'Missing native media or retained host identity: ' . $expected . PHP_EOL);
        exit(1);
    }
}
if (str_contains($markup, '/responsive-media')) {
    fwrite(STDERR, 'Image-only custom host still uses responsive-media' . PHP_EOL);
    exit(1);
}
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
if (!str_contains($css, '{object-position:20% 30%}') || !str_contains($css, 'img{object-position:inherit}')) {
    fwrite(STDERR, 'Native media lost its custom-host crop focus' . PHP_EOL);
    exit(1);
}
// Only the added link inside a retained custom host is boxless. Ordinary
// linked images need their anchor box to retain author margins (#3449).
$scopedLink = '.blocks-engine-synthetic-image-figure-inline.blocks-engine-custom-host-image.blocks-engine-linked-image-figure>a{display:contents}';
if (!str_contains($markup, 'blocks-engine-custom-host-image')
    || !str_contains($css, $scopedLink)
    || str_contains($css, '.blocks-engine-synthetic-image-figure-inline.blocks-engine-linked-image-figure>a{display:contents}')) {
    throw new RuntimeException('Custom-host link sizing must not change ordinary linked images.');
}
// A retained media box is not permission to rewrite a control's children.
$control = (new HtmlTransformer())->transform('<button type="button"><media-frame data-image-info="{}"><img src="photo.jpg" alt="Photo"></media-frame></button>')->toArray();
if (!str_contains($control['serialized_blocks'], '/responsive-media')) {
    throw new RuntimeException('Interactive image hosts must retain their original carrier.');
}
$capturedBlock = (new HtmlTransformer())->transform('<media-frame data-image-info="{}" style="display:block;overflow:hidden"><img src="photo.jpg" alt="Photo"></media-frame>')->toArray();
if (!str_contains($capturedBlock['serialized_blocks'], '<!-- wp:image ')
    || str_contains($capturedBlock['serialized_blocks'], '/responsive-media')) {
    throw new RuntimeException('Static metadata-backed block hosts must expose native media.');
}
echo "Custom image native host regression passed\n";
