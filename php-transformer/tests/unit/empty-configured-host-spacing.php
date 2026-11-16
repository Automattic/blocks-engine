<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Regression for #6312: the runtime placeholder has space, but no paint.
$source = (string) file_get_contents(__DIR__ . '/../fixtures/empty-configured-host-spacing.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
if (array() !== $result['fallbacks']) {
    throw new RuntimeException('The neutral spacing fixture must have zero fallbacks.');
}
$markup = $result['serialized_blocks'];
if (!preg_match('/<!-- wp:group[^\n]*related-padding[^\n]*-->\s*<div[^>]*>\s*<\/div>\s*<!-- \/wp:group -->/', $markup)) {
    throw new RuntimeException('The placeholder must become an empty core/group carrying its source class.');
}
$css = (string) preg_replace('/\s+/', '', implode("\n", array_column($result['assets'], 'content')));
if (!str_contains($css, 'min-width:750px') || !str_contains($css, 'padding-top:64px') || !str_contains($css, 'padding-bottom:64px')) {
    throw new RuntimeException('The empty group must carry the responsive padding.');
}
$diagnostics = array_filter($result['diagnostics'] ?? array(), static fn (array $row): bool => 'html_custom_element_runtime_placeholder' === ($row['code'] ?? '') && 'info' === ($row['severity'] ?? '') && 'product-recommendations' === ($row['tag'] ?? ''));
if (1 !== count($diagnostics)) {
    throw new RuntimeException('The spaced placeholder must record an informational diagnostic.');
}
echo "Empty configured host spacing passed.\n";
