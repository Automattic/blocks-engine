<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$fixture = file_get_contents(dirname(__DIR__) . '/fixtures/generated-component-purchase-form.html');
$assert(false !== $fixture, 'Issue #6379 fixture is readable');
$generated = static function (array $blocks) use (&$generated): array {
    $found = array();
    foreach ($blocks as $block) {
        if (str_starts_with($block['blockName'] ?? '', 'custom/')) $found[] = $block;
        $found = array_merge($found, $generated($block['innerBlocks'] ?? array()));
    }
    return $found;
};

$result = (new HtmlTransformer())->transform($fixture)->toArray();
foreach ($generated($result['blocks'] ?? array()) as $block) {
    $assert(!str_contains($block['attrs']['content'] ?? '', '<form'), 'Generated custom blocks must not swallow the purchase form');
}
$forms = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $fallback): bool =>
    'html_commerce_controls_fallback' === ($fallback['diagnostic_code'] ?? null)
    && 'commerce_add_to_cart_form' === ($fallback['binding']['role'] ?? null)
));
$assert(1 === count($forms), 'Purchase form keeps its commerce_add_to_cart_form fallback binding');
$assert('' !== trim($forms[0]['binding']['search_block_markup'] ?? ''), 'Purchase binding has searchable block markup');

foreach (array(
    'plain form' => str_replace(array('action="/cart/add"', 'name="add"', 'Add to cart'), array('action="/submit"', 'name="submit"', 'Submit'), $fixture),
    'no form' => str_replace(array('<form action="/cart/add">', '</form>'), '', $fixture),
) as $case => $html) {
    $negative = (new HtmlTransformer())->transform($html)->toArray();
    $assert(array() !== $generated($negative['blocks'] ?? array()), $case . ': non-purchase components still generate custom blocks');
}

echo "Generated component purchase form tests passed\n";
