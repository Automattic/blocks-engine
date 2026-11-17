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

// Dawn's section chrome on the same placeholder: an isolating stacking context
// and a finite slide-in reveal move or fade the box but paint nothing.
$withChrome = static function (string $css, string $classes) use ($source): array {
    $html = str_replace('</head>', '<style>' . $css . '</style></head>', $source);
    $html = (string) preg_replace('/(<product-recommendations[^>]*class=")/', '$1' . $classes . ' ', $html, 1);
    return (new HtmlTransformer())->transform($html)->toArray();
};
$reveal = ':root{--animation-slide-in:slideIn .6s cubic-bezier(0,0,.3,1) forwards}'
    . '.isolate{position:relative;z-index:0}'
    . '@media(prefers-reduced-motion:no-preference){.scroll-trigger.animate--slide-in{opacity:.01;transform:translateY(2rem)}'
    . '.scroll-trigger:not(.scroll-trigger--offscreen).animate--slide-in{animation:var(--animation-slide-in);animation-delay:calc(var(--animation-order) * 75ms)}'
    . '@keyframes slideIn{0%{transform:translateY(2rem);opacity:.01}to{transform:translateY(0);opacity:1}}}';
if (array() !== $withChrome($reveal, 'isolate scroll-trigger animate--slide-in')['fallbacks']) {
    throw new RuntimeException('Isolation and an entrance reveal must not keep a paint-free placeholder on the fallback path.');
}
foreach (array(
    '.chrome{position:absolute}',
    '.chrome{position:fixed;top:0}',
    '.chrome{animation:spin 1s linear infinite;transform:rotate(0)}@keyframes spin{to{transform:rotate(360deg)}}',
    '.chrome{background:red}',
    '.chrome{box-shadow:0 0 1px #000}',
) as $css) {
    if (!in_array('product-recommendations', array_column($withChrome($css, 'chrome')['fallbacks'], 'tag'), true)) {
        throw new RuntimeException('Out-of-flow, looping motion, or painted hosts must retain fallback: ' . $css);
    }
}
echo "Empty configured host chrome passed.\n";
