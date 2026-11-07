<?php
declare(strict_types=1);

// A disclosure group whose items follow a leading heading is still recognized
// as core/accordion: the heading stays an ordinary heading before the
// accordion and the CSS-collapsed, inert panels start closed. Non-items
// interleaved with or trailing the items do not form an accordion, and a
// heading-led run of native <details> stays core/details (#4765).

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$item = static fn(string $question, string $answer): string => '<div class="faq-item">'
    . '<button class="faq-q" type="button" aria-expanded="false">' . $question . '</button>'
    . '<div class="faq-a" inert=""><div><p>' . $answer . '</p></div></div></div>';
$page = static fn(string $group): string => '<!doctype html><html><head><style>'
    . '.faq-item{border-bottom:1px solid #ccc}.faq-q{appearance:none;width:100%;background:transparent;border:none;text-align:left;padding:18px 36px 18px 0;font-weight:600}'
    . '.faq-a{display:grid;grid-template-rows:0fr}.faq-q[aria-expanded="true"] + .faq-a{grid-template-rows:1fr}.faq-a > div{overflow:hidden}'
    . '</style></head><body><main><div class="faq-group">' . $group . '</div></main></body></html>';
$first = $item('What is the first question?', 'This is the first answer, hidden until opened.');
$second = $item('What is the second question?', 'This is the second answer, hidden until opened.');
$transform = static fn(string $group): string => (string) (new HtmlTransformer())->transform($page($group))->toArray()['serialized_blocks'];
$fail = static function (string $message, string $markup): void { fwrite(STDERR, "FAIL: {$message}\n{$markup}\n"); exit(1); };

foreach (array(
    'leading heading' => array('<h3>General</h3>', '/<!-- wp:heading \{"level":3\} --><h3 class="wp-block-heading">General<\/h3>/'),
    'leading heading and intro' => array('<h3>General</h3><p>Common questions.</p>', '/General<\/h3>.*Common questions\./s'),
) as $label => [$leading, $leadingPattern]) {
    $markup = $transform($leading . $first . $second);
    if (1 !== substr_count($markup, '<!-- wp:accordion -->') && 1 !== substr_count($markup, '<!-- wp:accordion {')) $fail("{$label}: the items become one core/accordion", $markup);
    if (2 !== substr_count($markup, '<!-- wp:accordion-item')) $fail("{$label}: both items are accordion items", $markup);
    if (1 !== preg_match($leadingPattern, $markup)) $fail("{$label}: the leading siblings stay ordinary blocks", $markup);
    if (1 !== preg_match('/General<\/h3>.*<!-- wp:accordion[ -]/s', $markup)) $fail("{$label}: the leading siblings stay before the accordion", $markup);
    if (str_contains($markup, 'openByDefault')) $fail("{$label}: the accordion starts collapsed", $markup);
    // The toggle's only sibling is the panel: it holds the answer, never the question (#4794).
    preg_match_all('/<!-- wp:accordion-panel.*?<!-- \/wp:accordion-panel -->/s', $markup, $panels);
    if (2 !== count($panels[0])) $fail("{$label}: each item has one panel", $markup);
    foreach ($panels[0] as $index => $panel) {
        $ordinal = 0 === $index ? 'first' : 'second';
        if (! str_contains($panel, "This is the {$ordinal} answer")) $fail("{$label}: the {$ordinal} panel holds its answer", $markup);
        if (str_contains($panel, 'question?') || str_contains($panel, 'wp:button')) $fail("{$label}: the {$ordinal} panel does not repeat its question button", $markup);
    }
}

foreach (array(
    'interleaved' => $first . '<h3>More</h3>' . $second,
    'trailing' => '<h3>General</h3>' . $first . $second . '<p>Still stuck? Contact us.</p>',
    'native details' => '<h3>General</h3><details><summary>First?</summary><p>One.</p></details><details><summary>Second?</summary><p>Two.</p></details>',
) as $label => $group) {
    if (str_contains($transform($group), '<!-- wp:accordion')) $fail("{$label}: non-items outside a leading run do not form an accordion", $transform($group));
}

// A link inside an answer is panel content, not navigation: it must not make
// the enclosing containers defer to their children and skip the accordion
// (#4869). An outer wrapper of such groups yields one accordion per group,
// never one accordion whose items are the groups.
$linked = $item('What is the first question?', 'This answer has <a href="/demo">a link</a>.');
$group = static fn(string $heading): string => '<div class="faq-group"><h3>' . $heading . '</h3>' . $linked . $second . '</div>';
$transformSection = static fn(string $body): string => (string) (new HtmlTransformer())->transform('<!doctype html><html><head><style>'
    . '.faq-section{padding:40px}.faq-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px}.faq-a{display:grid;grid-template-rows:0fr}.faq-a > div{overflow:hidden}'
    . '</style></head><body><main><section class="faq-section"><h2>Questions</h2>' . $body . '</section></main></body></html>')->toArray()['serialized_blocks'];
$accordions = static fn(string $markup): int => (int) preg_match_all('/<!-- wp:accordion( \{| -->)/', $markup);
foreach (array(
    'linked answer' => array($group('General'), 1),
    'outer wrapper of linked groups' => array('<div class="faq-grid">' . $group('General') . $group('Billing') . '</div>', 2),
) as $label => [$body, $expected]) {
    $markup = $transformSection($body);
    if ($expected !== $accordions($markup)) $fail("{$label}: each group becomes its own core/accordion", $markup);
    if (2 * $expected !== substr_count($markup, '<!-- wp:accordion-item')) $fail("{$label}: each group has two accordion items", $markup);
    if (1 === preg_match('/toggle-title">(General|Billing)</', $markup)) $fail("{$label}: a group heading is not an accordion question", $markup);
}

fwrite(STDOUT, "accordion leading heading tests: passed\n");
