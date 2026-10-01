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
$fixture = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/parity/html-nav-toggle-desktop-visible-placement.json'), true, 512, JSON_THROW_ON_ERROR);
$composite = (new HtmlTransformer())->transform($fixture['input']['content'], $fixture['input']['options'] ?? array())->toArray();
if (str_contains($composite['serialized_blocks'], 'blocks-engine-label-typography')) {
    fwrite(STDERR, "Composite navigation surfaces must not duplicate their projected box inside the text label\n");
    exit(1);
}
echo "Navigation label tag identity and composite surface boundary passed\n";
