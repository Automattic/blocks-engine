<?php
declare(strict_types=1);

// A label beside a form-less input lowers to editable blocks whether the input
// sits in an inline or a block wrapper. RichText cannot carry the control, so
// an inline run must not become a core/html fallback (#6130).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$names = static function (array $blocks) use (&$names): array {
    $found = array();
    foreach ($blocks as $block) {
        $found[] = (string) ($block['blockName'] ?? '');
        $found = array_merge($found, $names($block['innerBlocks'] ?? array()));
    }
    return $found;
};
$expected = null;
foreach (array('<span><input id="mls"></span>', '<div><input id="mls"></div>') as $wrapper) {
    $result = (new HtmlTransformer())->transform('<div class="mls-number-wrapper"><span class="span-label">MLS Number:</span> ' . $wrapper . '</div>')->toArray();
    $blocks = $names($result['blocks']);
    if (in_array('core/html', $blocks, true) || !str_contains((string) $result['serialized_blocks'], 'MLS Number:')) {
        fwrite(STDERR, "FAIL: {$wrapper} keeps its label as editable blocks: " . implode(' ', $blocks) . "\n");
        exit(1);
    }
    if (null !== $expected && $expected !== $blocks) {
        fwrite(STDERR, "FAIL: {$wrapper} lowers like the block wrapper: " . implode(' ', $blocks) . "\n");
        exit(1);
    }
    $expected = $blocks;
}
echo "inline wrapper form control: ok\n";
