<?php
declare(strict_types=1);

// A single disclosure item whose own CSS is a flex column (a role=button
// trigger with padding and an icon, then its region) stays a layout. Only the
// accordion recognizer races an element's own author layout: core/details'
// plain summary cannot carry the trigger's box or icon, so claiming the item
// as core/details loses the trigger padding and the icon.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$item = static fn(int $n, string $question, string $answer): string => '<div><div class="faq-item">'
    . '<div id="q-' . $n . '" class="heading" role="button" tabindex="0" aria-expanded="false" aria-controls="q-' . $n . '-content" aria-label="Expand question: ' . $question . '"><h4 class="question">' . $question . '</h4><div class="icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M4 8l8 8 8-8" fill="currentColor"></path></svg></div></div>'
    . '<div id="q-' . $n . '-content" class="content" role="region" hidden><div class="clip"><p>' . $answer . '</p></div></div>'
    . '</div><hr class="rule"></div>';
$page = '<!doctype html><html><head><style>.faq-item{display:flex;flex-direction:column}.faq-item .heading{display:flex;justify-content:space-between;padding-block:24px;cursor:pointer}.faq-item .icon{display:flex;margin-left:24px}.faq-item .content{display:grid;grid-template-rows:0fr}</style></head><body><main><section>'
    . '<div class="faq" data-blocks-engine-capture-exclusive-disclosures="true"><h2>Questions</h2><hr class="rule">'
    . $item(1, 'How do I book?', 'Use the contact form.') . $item(2, 'Which genres?', 'Rock and pop.')
    . '</div></section></main></body></html>';
$fail = static function (string $message, string $markup): void { fwrite(STDERR, "FAIL: {$message}\n{$markup}\n"); exit(1); };

$markup = (string) (new HtmlTransformer())->transform($page)->toArray()['serialized_blocks'];
if (str_contains($markup, 'wp:details')) $fail('a flex disclosure item is not claimed as core/details', $markup);
if (2 !== preg_match_all('/class="wp-block-group heading[^"]*"[^>]*padding-top:24px;padding-bottom:24px/', $markup)) $fail('each trigger keeps its padded heading box', $markup);
if (2 !== preg_match_all('/class="wp-block-group icon[^"]*"><!-- wp:image .*?materialized-svg/s', $markup)) $fail('each trigger keeps its icon', $markup);
if (!str_contains($markup, 'How do I book?') || !str_contains($markup, 'Use the contact form.')) $fail('question and answer text survive', $markup);
echo "own layout disclosure item tests: passed\n";
