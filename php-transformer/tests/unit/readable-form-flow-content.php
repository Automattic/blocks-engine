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

// A form or fieldset the author lays out as a flex column stays CSS-owned like
// any author layout container. A core flex layout would add
// align-items:flex-start and shrink every field to its content width.
$cardResult = (new HtmlTransformer())->transform(
    '<form style="display:flex;flex-direction:column;gap:18px;padding:28px"><fieldset style="display:flex;flex-direction:column;gap:8px;border:0"><legend>Topic</legend><label><input type="radio" name="t" value="a">A</label><label><input type="radio" name="t" value="b">B</label></fieldset><label style="display:flex;flex-direction:column;gap:6px">Message<textarea name="m"></textarea></label><button type="submit">Send</button></form>'
)->toArray();
$card = (string) ($cardResult['serialized_blocks'] ?? '');
preg_match_all('/<!-- wp:group (\{.*?\}) -->/', $card, $groups);
$assert(2 <= count($groups[1]) && str_contains($groups[1][0], '"tagName":"form"'), 'The flex form and its fieldset each convert to a group.', $card);
foreach (array_slice($groups[1], 0, 2) as $json) {
    $attrs = json_decode($json, true);
    $assert(! isset($attrs['layout']) && str_contains((string) ($attrs['className'] ?? ''), 'blocks-engine-css-owned-layout'), 'An author flex form container keeps its layout CSS-owned.', $json);
}
$assert(str_contains(json_encode($cardResult['assets'] ?? array(), JSON_UNESCAPED_SLASHES), 'flex-direction:column !important;gap:18px'), 'The form carries its authored column and gap.', $card);

// A form kept as a runtime island ships its readable tree for a provider to
// put on the page. That tree's generated layout classes need their rules in
// the page stylesheet, though the page markup holds only the island.
$islandResult = (new HtmlTransformer())->transform(
    '<form method="post" style="display:flex;flex-direction:column;gap:22px;padding:28px;border:1px solid #ccc;border-radius:24px"><div style="display:flex;gap:16px"><label style="display:flex;flex-direction:column;gap:6px">Name<input name="n"></label><label style="display:flex;flex-direction:column;gap:6px">Email<input type="email" name="email"></label></div><label style="display:flex;flex-direction:column;gap:6px">Message<textarea name="m"></textarea></label><button type="submit">Send</button></form>'
)->toArray();
$islandRoot = (string) ($islandResult['fallbacks'][0]['readable_blocks'][0]['attrs']['className'] ?? '');
$islandCss = implode("\n", array_column(array_filter($islandResult['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert(1 === preg_match('/be-inline-geometry-[0-9a-f]{16}/', $islandRoot, $islandClass), 'The island form readable root carries a generated layout class.', $islandRoot);
$assert(str_contains($islandCss, '.' . ($islandClass[0] ?? '') . '{display:flex !important;flex-direction:column !important;gap:22px !important}'), 'The readable root layout rule reaches the page stylesheet.', $islandCss);

echo 'Readable form flow content tests: ' . $assertions . ' passed' . PHP_EOL;
