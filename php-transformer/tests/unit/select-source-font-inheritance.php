<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<style>html{line-height:1.5}body{font-family:Arial,sans-serif}select{font:inherit;font-size:16px}</style>'
    . '<form method="post"><label for="topic">Topic</label><select id="topic" name="topic"><option>One</option></select><button>Send</button></form>'
)->toArray();
$styles = $result['fallbacks'][0]['presentation_graph']['controls'][0]['control']['styles'] ?? array();
if ('Arial,sans-serif' !== ($styles['font_family'] ?? null)
    || '1.5' !== ($styles['line_height'] ?? null)
    || '16px' !== ($styles['font_size'] ?? null)
) {
    throw new RuntimeException('Select must inherit its source font, not the provider dropdown shell: ' . json_encode($styles));
}
echo "Select source font inheritance passed\n";
