<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) $failures[] = $message;
};
$item = '<li class="item"><h3><button aria-expanded="false" aria-controls="answer" data-blocks-engine-capture-component-disclosure="0">Question</button></h3><div id="answer"><p>Answer text.</p></div></li>';
$transform = static fn (string $html): string => (new HtmlTransformer())->transform($html)->toArray()['serialized_blocks'];

// (b) The author signal can sit on the list or on the recognized wrapper.
foreach (array(
    '<ul class="accordion-items-container">' . $item . '</ul>',
    '<div class="sqs-block-accordion"><div class="sqs-block-content"><ul>' . $item . '</ul></div></div>',
) as $source) {
    $markup = $transform($source);
    $assert(1 === substr_count($markup, '<!-- wp:accordion '), 'one captured item becomes an accordion');
    $assert(1 === preg_match('/<!-- wp:accordion .*?<!-- wp:accordion-item\b/s', $markup), 'accordion contains its item');
    $assert(str_contains($markup, 'Question') && str_contains($markup, 'Answer text.'), 'label and panel survive');
    $assert(! str_contains($markup, '<!-- wp:html'), 'single item needs no HTML fallback');
}

// (c) Capture alone is not an accordion. Pair the negative with the positive
// boundary so this regression also detects a missing singleton implementation.
$withoutSignal = $transform('<ul>' . $item . '</ul>');
$withSignal = $transform('<ul class="accordion">' . $item . '</ul>');
$assert(! str_contains($withoutSignal, '<!-- wp:accordion') && str_contains($withSignal, '<!-- wp:accordion '), 'only the author-signalled singleton becomes an accordion');
$uncaptured = $transform('<ul class="accordion">' . str_replace(' data-blocks-engine-capture-component-disclosure="0"', '', $item) . '</ul>');
$assert(! str_contains($uncaptured, '<!-- wp:accordion'), 'author signal alone does not enable a singleton');
$panelCapture = $transform('<ul class="accordion">' . str_replace(array(' data-blocks-engine-capture-component-disclosure="0"', 'id="answer"'), array('', 'id="answer" data-blocks-engine-capture-component-disclosure="0"'), $item) . '</ul>');
$assert(! str_contains($panelCapture, '<!-- wp:accordion'), 'capture must belong to the toggle, not arbitrary panel content');

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "single captured disclosure accordion tests passed\n";
