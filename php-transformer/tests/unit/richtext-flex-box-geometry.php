<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A phrasing element can be both a flex item and a flex container. Keeping
// its display and dimensions without its flex constraints changes the logo's
// size and the height of every subsequent row in a stacked footer.
$html = '<footer><a href="/"><span style="display:flex;width:44px;height:44px;flex-shrink:0;align-items:center;justify-content:center;gap:2px;box-sizing:border-box;padding:2px;border:1px solid gold"><span style="display:flex;width:100%;height:100%;align-items:center;justify-content:center">Logo</span></span><span>Name</span></a></footer>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML($result['serialized_blocks']);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$found = false;
foreach ($document->getElementsByTagName('mark') as $mark) {
    $style = $mark->getAttribute('style');
    if (!str_contains($style, 'width:44px')) continue;
    $found = true;
    foreach (array('flex-shrink:0', 'align-items:center', 'justify-content:center', 'gap:2px', 'box-sizing:border-box') as $declaration) {
        if (!str_contains($style, $declaration)) {
            throw new RuntimeException('Rich-text flex box lost ' . $declaration . ': ' . $style);
        }
    }
}
if (!$found) throw new RuntimeException('Expected an editable rich-text logo carrier.');
echo "Rich-text flex box geometry passed\n";
