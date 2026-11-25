<?php
declare(strict_types=1);

// Issue #6453: in a whole document, a label whose `for` names no element
// associates nothing. Beside the value it captions it is caption text: a core
// paragraph carries the label's classes and text as core RichText (no
// structural <label> markup inside it), in the box the source label took. An empty one carries nothing. A
// dangling label alone in its box stays authored copy, and real associations
// keep the authored-label host.

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
$assert(!in_array('custom/authored-label', $names($caption['blocks']), true), 'a dangling caption beside its value is not an authored-label');
$assert(!in_array('authored-label', array_column($caption['source_reports']['generated_blocks'], 'name'), true), 'a dangling caption registers no authored-label definition');
// The source label is inline: in its block-flow wrapper it sits on a line
// whose strut is the wrapper's line-height, so the caption paragraph keeps an
// inline box rather than a block box sized by the label's own line-height.
$assert(1 === preg_match('/<!-- wp:paragraph [^>]*--><p class="caption blocks-engine-synthetic-paragraph">Service<\/p>/', $caption['serialized_blocks']), 'an inline dangling caption is an inline core paragraph carrying the label classes');
$boxed = $transform($document('<style>.caption{display:block;line-height:1}</style><form><div class="field"><label class="caption" for="service">Service</label><div class="value">School Catering</div></div></form>'));
$assert(1 === preg_match('/<!-- wp:paragraph [^>]*--><p class="caption blocks-engine-synthetic-paragraph blocks-engine-source-box-paragraph">Service<\/p>/', $boxed['serialized_blocks']), 'a dangling caption the source lays out as a block keeps the block-box paragraph');
$responsive = $transform($document('<style>@media (min-width:640px){.caption{display:block}}</style><form><div class="field"><label class="caption" for="service">Service</label><div class="value">School Catering</div></div></form>'));
$assert(1 === preg_match('/<p class="caption blocks-engine-synthetic-paragraph blocks-engine-source-box-paragraph">Service<\/p>/', $responsive['serialized_blocks']), 'a caption that is a block at any viewport keeps the block-box paragraph');
$assert(0 === $caption['source_reports']['editability_report']['metrics']['structural_rich_text_attribute_count'], 'the caption paragraph carries no structural markup in its RichText content');

$alone = $transform($document('<form><input id="f"><label for="f">First</label><div><label for="nowhere">Fields marked * are required</label></div></form>'));
$assert(1 === preg_match('/authored-label \{"htmlFor":"nowhere"/', $alone['serialized_blocks']), 'a dangling label alone in its box stays authored copy');

$associated = $transform($document('<input id="choice" type="checkbox"><label for="choice">Choice</label>'));
$assert(in_array('custom/authored-label', $names($associated['blocks']), true), 'a label naming a control in its document keeps its association host');

$fragment = $transform('<div><label for="outside">Outside</label><span>value</span></div>');
$assert(in_array('custom/authored-label', $names($fragment['blocks']), true), 'a fragment keeps a for whose control may live outside it');

echo "Dangling label for regression: {$count} passed\n";
