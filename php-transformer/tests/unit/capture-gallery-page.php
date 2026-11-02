<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// Neutral reproduction from #3989: capture ids are not authored gallery names.
$html = file_get_contents(dirname(__DIR__) . '/fixtures/capture-gallery-page.html');
if (false === $html) throw new RuntimeException('Missing capture gallery fixture.');
foreach (array(
    'capture id' => $html,
    'capture class' => str_replace('id="data-blocks-engine-capture-gallery-0"', 'class="data-blocks-engine-capture-gallery-0 page"', str_replace('class="page" ', '', $html)),
    // The same loss is reachable with real author identity, so the generator
    // must also refuse roots holding authored content outside the slide list.
    'authored id' => str_replace('data-blocks-engine-capture-gallery-0', 'gallery', $html),
) as $label => $source) {
    $markup = (new Runtime())->serializeBlocks((new HtmlTransformer())->transform($source)->toArray()['blocks']);
    foreach (array('Journey', 'Step one', 'Step two', 'Quotes heading') as $text) {
        if (!str_contains($markup, $text)) throw new RuntimeException($label . ' lost authored text: ' . $text);
    }
    if (str_contains($markup, '/authored-carousel')) {
        throw new RuntimeException($label . ' must not turn the page wrapper into an authored carousel.');
    }
}
fwrite(STDOUT, "Capture gallery page regression passed.\n");
