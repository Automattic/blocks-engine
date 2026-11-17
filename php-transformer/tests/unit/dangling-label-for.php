<?php
declare(strict_types=1);

// Issue #6453: in a whole document, a label whose `for` names no element
// associates nothing. It is caption text and lowers to a core paragraph, not
// the generated authored-label host kept for real external associations.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    ++$count;
    if (!$condition) throw new RuntimeException($message);
};
$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html)->toArray();
$names = static function (array $blocks) use (&$names): array {
    $found = array();
    foreach ($blocks as $block) {
        $found[] = (string) $block['blockName'];
        $found = array_merge($found, $names($block['innerBlocks'] ?? array()));
    }
    return $found;
};
$document = static fn (string $body): string => '<!DOCTYPE html><html><head></head><body>' . $body . '</body></html>';

$empty = $transform($document('<label for="name"></label>'));
$assert(!in_array('custom/authored-label', $names($empty['blocks']), true), 'an empty dangling label emits no authored-label');
$assert(array() === $empty['source_reports']['generated_blocks'], 'an empty dangling label registers no companion block');

$caption = $transform($document('<form><div class="field"><label class="caption" for="service">Service</label><div class="value">School Catering</div></div></form>'));
$assert(!in_array('custom/authored-label', $names($caption['blocks']), true), 'a dangling label in a whole document is not an authored-label');
$assert(!in_array('authored-label', array_column($caption['source_reports']['generated_blocks'], 'name'), true), 'a dangling label registers no authored-label definition');
$assert(1 === preg_match('/<!-- wp:paragraph [^>]*--><p class="caption blocks-engine-synthetic-paragraph">Service<\/p>/', $caption['serialized_blocks']), 'the dangling caption stays editable text in a core paragraph with its source class');

$associated = $transform($document('<input id="choice" type="checkbox"><label for="choice">Choice</label>'));
$assert(in_array('custom/authored-label', $names($associated['blocks']), true), 'a label naming a control in its document keeps its association host');

$fragment = $transform('<label for="outside">Outside</label>');
$assert('custom/authored-label' === $fragment['blocks'][0]['blockName'], 'a fragment keeps a for whose control may live outside it');

echo "Dangling label for regression: {$count} passed\n";
