<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assertions = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL . $detail . PHP_EOL);
        exit(1);
    }
};

// A form degraded to readable content keeps the authored text around its
// controls: form structure is the controls and their containers, everything
// else is content at its source position.
$markup = (string) ((new HtmlTransformer())->transform(
    '<form class="enquiry"><p class="intro">We reply within a day.</p><div class="field"><label for="name">Name</label><input id="name" name="name"></div><div class="note"><p>Your data stays private.</p></div><button type="submit">Send</button></form>'
)->toArray()['serialized_blocks'] ?? '');

$intro = strpos($markup, 'We reply within a day.');
$field = strpos($markup, 'id="name"');
$note = strpos($markup, 'Your data stays private.');
$submit = strpos($markup, 'type="submit"');
$assert(false !== $intro, 'An intro paragraph inside a readable form survives conversion.', $markup);
$assert(false !== $note, 'A note container inside a readable form survives conversion.', $markup);
$assert(false !== $field && false !== $submit, 'The readable control and the submit control are still converted.', $markup);
$assert($intro < $field && $field < $note && $note < $submit, 'Flow content keeps its source position among the controls.', $markup);
$assert(1 === substr_count($markup, '>Name<') + substr_count($markup, '>Name (required)<'), 'A control label is carried once, by its control, not again as flow content.', $markup);

$wrapped = (string) ((new HtmlTransformer())->transform(
    '<form><div class="row"><label class="field"><span class="caption">Message</span><textarea name="m"></textarea></label></div><button type="submit">Send</button></form>'
)->toArray()['serialized_blocks'] ?? '');
$assert(1 >= substr_count($wrapped, 'Message'), 'Text inside a wrapping label belongs to its control and is not converted again as flow content.', $wrapped);

echo 'Readable form flow content tests: ' . $assertions . ' passed' . PHP_EOL;
