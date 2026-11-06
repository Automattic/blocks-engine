<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Native text chains must not change the existing single media boundary.
$result = (new HtmlTransformer())->transform(
    '<section id="media"><div id="frame"><img src="https://example.com/photo.jpg" alt="Photo"></div></section>'
)->toArray();
$block = $result['blocks'][0] ?? array();
if (!str_ends_with((string) ($block['blockName'] ?? ''), '/layout-shell')
    || array('section', 'div') !== array_column($block['attrs']['wrappers'] ?? array(), 'tagName')
    || 'core/image' !== ($block['innerBlocks'][0]['blockName'] ?? '')
) {
    throw new RuntimeException('Media wrapper chains must retain one boundary around the native image.');
}
echo "Native wrapper media boundary passed\n";
