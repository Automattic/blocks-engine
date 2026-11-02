<?php
declare(strict_types=1);

// A legacy <font> wrapper is a passive inline mark for images, like <span> (#3985).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cases = array(
    'sole image becomes core/image'       => array('<p><font><img src="pic.png" width="120" height="160"></font></p>', '<!-- wp:image'),
    'image with caption keeps font paint' => array('<p><font size="-1" color="#ff0000"><img src="pic.png" width="120" height="160"><br>Caption</font></p>', 'color:#ff0000'),
);
foreach ($cases as $label => [$source, $expected]) {
    $markup = (string) ((new HtmlTransformer())->transform($source)->toArray()['serialized_blocks'] ?? '');
    if (str_contains($markup, '<!-- wp:html') || !str_contains($markup, $expected)) {
        fwrite(STDERR, "FAIL: {$label}\n{$markup}\n");
        exit(1);
    }
}
echo "font-wrapped-inline-image: passed\n";
