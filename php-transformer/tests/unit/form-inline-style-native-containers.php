<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// A newsletter field chain (Squarespace) styles each wrapper inline. The
// inline style is the wrapper's own presentation, which a native Group
// carries, so the chain must not fold into a generated layout-shell.
$form = static fn (string $outer, string $inner): string => '<form class="newsletter-form"><div class="newsletter-form-body">'
    . '<div class="newsletter-form-fields-wrapper form-fields" style="' . $outer . '">'
    . '<div id="email-1" class="newsletter-form-field-wrapper form-item field email" style="' . $inner . '">'
    . '<label class="title" for="email-1-field">Email Address</label>'
    . '<input id="email-1-field" class="field-element" name="email" type="email">'
    . '</div></div></div></form>';

$readable = static function (string $html): array {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $blocks = $result['fallbacks'][0]['readable_blocks'];
    return array($blocks, (new Runtime())->serializeBlocks($blocks), json_encode($result, JSON_UNESCAPED_SLASHES));
};

[$blocks, $markup, $json] = $readable($form('vertical-align: middle', 'vertical-align: bottom'));
if (str_contains($markup, '/layout-shell')) {
    throw new RuntimeException('Inline-styled form wrapper chains must stay native Groups: ' . $markup);
}
$wrappers = array();
$collect = static function (array $blocks) use (&$collect, &$wrappers): void {
    foreach ($blocks as $block) {
        $class = (string) ($block['attrs']['className'] ?? '');
        if (str_contains($class, 'newsletter-form-fields-wrapper') || str_contains($class, 'newsletter-form-field-wrapper')) {
            $wrappers[] = array($block['blockName'], $class, $block['attrs']['anchor'] ?? null);
        }
        $collect($block['innerBlocks'] ?? array());
    }
};
$collect($blocks);
if (2 !== count($wrappers) || 'core/group' !== $wrappers[0][0] || 'core/group' !== $wrappers[1][0] || 'email-1' !== $wrappers[1][2]) {
    throw new RuntimeException('Each source wrapper must stay one native Group with its identity: ' . json_encode($wrappers));
}
// The inline declarations move to each Group's geometry carrier.
foreach (array('vertical-align:middle', 'vertical-align:bottom') as $declaration) {
    if (!str_contains($json, $declaration)) {
        throw new RuntimeException('The wrapper\'s inline style must reach its Group carrier: ' . $declaration);
    }
}

// A custom property can feed descendants and the carrier does not restate it.
[, $markup] = $readable($form('vertical-align: middle', '--gap: 3px'));
if (!str_contains($markup, '/layout-shell')) {
    throw new RuntimeException('A wrapper declaring a custom property keeps its source style in shell transport.');
}
echo "Form inline-style native containers passed\n";
