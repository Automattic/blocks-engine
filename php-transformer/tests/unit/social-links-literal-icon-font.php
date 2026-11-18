<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(file_get_contents(dirname(__DIR__) . '/fixtures/social-links-literal-icon-font.html'))->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));
$markup = $result['serialized_blocks'];
if (str_contains($markup, 'style="font-size:24px"') || !str_contains($markup, '"size":"has-normal-icon-size"')) {
    throw new RuntimeException('The list must use core size attributes, not transport-only inline typography.');
}
foreach (array('background-color:#e85a1c', 'width:70px', 'height:70px', 'border-radius:50%', 'font-size:24px', '>a>svg{width:1em;height:1em}') as $paint) {
    if (!str_contains($css, $paint)) throw new RuntimeException('Missing source icon box projection: ' . $paint);
}
if (!str_contains($css, '>a{background-color:#e85a1c')) {
    throw new RuntimeException('Source paint must target the generated anchor, not the removed glyph div.');
}
echo "Literal social icon-font save contract passed.\n";
