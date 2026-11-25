<?php
declare(strict_types=1);

// When the item container sits inside an outer wrapper (wrapper > root >
// items), both collapse into one core/accordion. The accordion keeps the
// root's classes beside the wrapper's so root-scoped rules (custom
// properties for item padding and divider colour) keep matching (#7327).

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$item = static fn(int $n, string $question, string $answer, bool $open): string => '<div class="faq-item">'
    . '<button class="faq-toggle" aria-expanded="' . ($open ? 'true' : 'false') . '" aria-controls="p' . $n . '" id="b' . $n . '"><div class="faq-row"><h3>' . $question . '</h3>'
    . '<svg class="faq-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor"/><path d="M7.5 12h9" stroke="currentColor"/></svg></div></button>'
    . '<div id="p' . $n . '" role="region" aria-labelledby="b' . $n . '" class="faq-panel"' . ($open ? '' : ' hidden') . '><p>' . $answer . '</p></div></div>';
$html = '<!doctype html><html><head><meta charset="utf-8"><title>Accordion root</title><style>'
    . 'body{margin:0;font-family:sans-serif}.faq-wrap{max-width:600px;margin:40px auto}'
    . '.faq-root.faq-root--line{--faq-pad:24px;--faq-divider:#07658b}'
    . '.faq-item{border-bottom:1px solid var(--faq-divider,transparent)}'
    . '.faq-toggle{all:unset;box-sizing:border-box;width:100%;cursor:pointer}'
    . '.faq-row{display:flex;align-items:center;justify-content:space-between;padding:var(--faq-pad,12px) 0}'
    . '.faq-row h3{margin:0;font-size:18px}.faq-icon{width:24px;height:24px}'
    . '.faq-panel{padding:0 0 var(--faq-pad,12px)}.faq-panel[hidden]{display:none}'
    . '</style></head><body><main><h1>FAQ</h1><div class="faq-wrap"><div class="faq-root faq-root--line" role="region">'
    . $item(1, 'How long does drying take?', 'Usually four to six hours.', true)
    . $item(2, 'Do you move furniture?', 'Yes, light furniture.', false)
    . $item(3, 'Is it pet safe?', 'Yes.', false)
    . '</div></div></main></body></html>';

$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = (string) $result['serialized_blocks'];
$fail = static function (string $message) use ($markup): void { fwrite(STDERR, "FAIL: {$message}\n{$markup}\n"); exit(1); };

$blocks = (array) $result['blocks'];
$accordions = array();
$walk = static function (array $blocks) use (&$walk, &$accordions): void {
    foreach ($blocks as $block) {
        if ('core/accordion' === ($block['blockName'] ?? null)) $accordions[] = $block;
        $walk($block['innerBlocks'] ?? array());
    }
};
$walk($blocks);
if (1 !== count($accordions)) $fail('the wrapper and root become one core/accordion');
$classes = preg_split('/\s+/', (string) ($accordions[0]['attrs']['className'] ?? ''));
foreach (array('faq-wrap', 'faq-root', 'faq-root--line') as $class) {
    if (! in_array($class, $classes, true)) $fail("the accordion carries the merged source class {$class}");
}
// The root-scoped rule's compound selector matches the accordion's classes.
if (! str_contains(json_encode($result['assets'], JSON_UNESCAPED_SLASHES) ?: '', '.faq-root.faq-root--line')) $fail('the root-scoped rule keeps its selector');
if (3 !== preg_match_all('/<div class="wp-block-accordion-item faq-item[\s"]/', $markup)) $fail('every item keeps its faq-item class');

fwrite(STDOUT, "accordion nested root classes tests: passed\n");
