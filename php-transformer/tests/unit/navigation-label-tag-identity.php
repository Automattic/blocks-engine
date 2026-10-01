<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<style>p.item-label{font-family:Inter;font-size:16px;line-height:19.2px}</style>'
    . '<header><nav><a href="/home"><p class="item-label">Home</p></a><a href="/about"><p class="item-label">About</p></a><a href="/services"><p class="item-label">Services</p></a></nav></header>', array()
)->toArray();
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column($result['assets'], 'content'));
if (1 !== preg_match('/blocks-engine-source-p-[a-z0-9-]+/', $markup, $marker)
    || !str_contains($css, $marker[0])
    || !str_contains($markup, 'blocks-engine-label-typography')
    || !str_contains($markup, 'wp:navigation-link')) {
    fwrite(STDERR, "Native navigation labels must carry their projected source type identity and typography box\n");
    exit(1);
}
echo "Navigation label tag identity passed\n";
