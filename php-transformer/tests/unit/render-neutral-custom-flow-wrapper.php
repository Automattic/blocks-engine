<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<style>.h{background-color:#123456;padding:16px}</style><size-watcher data-var="x"><header class="h"><a href="/">Logo</a><nav><ul><li><a href="/a">A</a></li></ul></nav></header></size-watcher>';
$result = ( new HtmlTransformer() )->transform($html)->toArray();
$serialized = $result['serialized_blocks'] ?? '';
$css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$names = array();
$walk = static function (array $blocks) use (&$walk, &$names): void {
    foreach ( $blocks as $block ) {
        $names[] = $block['blockName'];
        $walk($block['innerBlocks'] ?? array());
    }
};
$walk($result['blocks'] ?? array());
$unsupported = array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_unsupported_element' === ($finding['diagnostic_code'] ?? ''));
if ( array() !== $unsupported || in_array('core/html', $names, true) || ! in_array('core/group', $names, true) || ! in_array('core/navigation', $names, true) || ! str_contains($serialized, 'Logo') || ! str_contains($serialized, '/a') || ! str_contains($serialized, '<header') || ! str_contains($serialized, 'h ') || ! str_contains($css, '.h{background-color:#123456;padding:16px}') ) {
    fwrite(STDERR, "FAIL: neutral size-watcher must emit native header/content with .h presentation and no unsupported fallback\n" . json_encode($result, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
// The policy is structural, not a special case for the fixture's tag name.
$renamed = ( new HtmlTransformer() )->transform(str_replace('size-watcher', 'layout-observer', $html))->toArray();
if ( ($renamed['serialized_blocks'] ?? '') !== $serialized ) {
    fwrite(STDERR, "FAIL: equivalent neutral autonomous hosts must lower identically\n");
    exit(1);
}
echo "Render-neutral custom flow wrapper tests passed\n";
