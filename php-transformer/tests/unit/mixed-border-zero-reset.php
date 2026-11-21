<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<style>*{border-width:0;border-style:solid;border-color:#ddd}.row{display:flex;border-bottom-width:1px}</style>'
    . '<div class="row"><p>Opening hours</p><p>By request</p></div>'
)->toArray();
$border = $result['blocks'][0]['attrs']['style']['border'] ?? array();
if ('0' !== ($border['width'] ?? null) || '1px' !== ($border['bottom']['width'] ?? null)) {
    throw new RuntimeException('A one-sided border must retain the global zero-width reset in native block support.');
}
$markup = $result['serialized_blocks'] ?? '';
if (!str_contains($markup, 'border-width:0') || !str_contains($markup, 'border-bottom-width:1px')) {
    throw new RuntimeException('Saved native markup must not restore medium borders on the other three sides.');
}
fwrite(STDOUT, "mixed border zero reset passed\n");
