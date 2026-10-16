<?php
declare(strict_types=1);

// A disclosure list whose own CSS makes it a flex column, beside a sibling
// intro, is still recognized as core/accordion. Its author-owned layout must
// not route it past the accordion recognizer into one core/details per item,
// which drops the trigger box and icon (#2926).

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$item = static fn(int $n, string $question, string $answer): string => '<div style="background:#0a1630;border:1px solid #182c55;border-radius:12px;overflow:hidden">'
    . '<button type="button" aria-expanded="false" class="trigger" aria-controls="panel-' . $n . '"><span>' . $question . '</span><span aria-hidden="true" class="icon">+</span></button>'
    . '<p class="answer" id="panel-' . $n . '" hidden role="region" data-blocks-engine-capture-hydrated-disclosure="true" data-blocks-engine-capture-local-disclosure="true">' . $answer . '</p></div>';
$page = static fn(string $listCss): string => '<!doctype html><html><head><style>.trigger{display:flex;width:100%;align-items:center;justify-content:space-between;gap:16px;padding:20px}.icon{color:#21d9c0}.answer{padding:0 20px 20px}' . $listCss . '</style></head><body><main><section><div>'
    . '<p class="intro">Straight answers.</p><div class="faq stack">'
    . $item(1, 'How much does it cost?', 'Fixed quotes.') . $item(2, 'Do I own it?', 'Yes.')
    . '</div></div></section></main></body></html>';
$fail = static function (string $message, string $markup): void { fwrite(STDERR, "FAIL: {$message}\n{$markup}\n"); exit(1); };

foreach (array('flex column' => '.stack{display:flex;flex-direction:column;gap:16px}', 'grid' => '.stack{display:grid;gap:16px}', 'plain' => '') as $label => $listCss) {
    $markup = (string) (new HtmlTransformer())->transform($page($listCss))->toArray()['serialized_blocks'];
    if (!str_contains($markup, '<!-- wp:accordion ') && !str_contains($markup, '<!-- wp:accordion -->')) $fail("{$label}: the disclosure list becomes core/accordion", $markup);
    if (str_contains($markup, 'wp:details')) $fail("{$label}: no item falls back to core/details", $markup);
    if (2 !== substr_count($markup, '<!-- wp:accordion-item')) $fail("{$label}: both items are accordion items", $markup);
    if (!str_contains($markup, 'Straight answers.') || 1 !== preg_match('/Straight answers\..*<!-- wp:accordion[ -]/s', $markup)) $fail("{$label}: the sibling intro stays before the accordion", $markup);
    if (1 !== preg_match('/<!-- wp:accordion-item \{[^\n]*"radius":"12px"/', $markup)) $fail("{$label}: the item border radius is kept", $markup);
    if (1 !== preg_match('/<!-- wp:accordion-panel \{[^\n]*"padding":\{[^}]*"right":"20px"/', $markup)) $fail("{$label}: the panel padding is kept", $markup);
    if (!str_contains($markup, '+')) $fail("{$label}: the icon glyph is kept", $markup);
}

fwrite(STDOUT, "accordion flex list with sibling tests: passed\n");
