<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform((string) file_get_contents(dirname(__DIR__) . '/fixtures/brand-link-button-semantics.html'), array())->toArray();
$buttons = array();
$visit = static function (array $blocks) use (&$visit, &$buttons): void {
    foreach ($blocks as $block) {
        if ('core/button' === ($block['blockName'] ?? '')) $buttons[] = $block['attrs']['url'] ?? '';
        $visit($block['innerBlocks'] ?? array());
    }
};
$visit($result['blocks']);
$failures = array();
if (array('/products', '/contact') !== $buttons) $failures[] = 'Only the two authored .btn surfaces must become core/button; the brand must not.';
$html = $result['serialized_blocks'];
if (!preg_match('/<a\b[^>]*class="brand"[^>]*href="#top"[^>]*>(.*?)<\/a>/s', $html, $brand)
    || !str_contains($brand[1], '<img ')
    || !preg_match('/<b\\b[^>]*>Acme<\/b><small\\b[^>]*>Steel Buildings<\/small>/', $brand[1])) {
    $failures[] = 'The brand keeps one source-classed link, materialized SVG, and both label lines.';
}
if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "Brand link button semantics passed\n";
