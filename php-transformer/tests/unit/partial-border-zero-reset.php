<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<div class="hours" style="border-width:0;border-style:solid;border-color:#ddd;border-bottom-width:1px"><p>Tuesday — Friday</p></div>'
)->toArray();
$border = $result['blocks'][0]['attrs']['style']['border'] ?? array();
if ('0' !== ($border['width'] ?? null) || '1px' !== ($border['bottom']['width'] ?? null)) {
    throw new RuntimeException('A painted bottom border must retain the global zero-width reset on the other sides.');
}
if (!str_contains($result['serialized_blocks'], 'border-width:0;') || !str_contains($result['serialized_blocks'], 'border-bottom-width:1px')) {
    throw new RuntimeException('Native markup must not expose the UA medium border width on reset sides.');
}
echo "PASS: partial border preserves global zero-width reset\n";
