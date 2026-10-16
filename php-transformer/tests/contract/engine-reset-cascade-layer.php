<?php
declare(strict_types=1);

/** Engine before-author resets must not lose to layered author CSS (#2862). */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$css = static function (string $authorCss): string {
    $html = '<style>' . $authorCss . '</style><ul class="grid"><li class="p-8"><span>icon</span><h3 class="mt-6">Title</h3><p class="mt-2">Body</p></li></ul>';
    $result = ( new HtmlTransformer() )->transform($html)->toArray();
    return implode("\n", array_map(static fn (array $a): string => 'css' === ($a['kind'] ?? '') ? (string) ($a['content'] ?? '') : '', $result['assets'] ?? array()));
};
$base = '.grid{display:grid}.p-8{padding:2rem}.mt-6{margin-top:1.5rem}.mt-2{margin-top:.5rem}';

$layered = $css('@layer base,utilities;@layer utilities{' . $base . '}');
$assert(1 === preg_match('/@layer\s+blocks-engine-reset\s*,\s*base\s*,\s*utilities\s*;/', $layered), 'layer order names the engine layer before base');
$assert(1 === preg_match('/@layer blocks-engine-reset\{[^@]*blocks-engine-css-owned-flow[^@]*\}/', $layered), 'css-owned-flow reset is emitted inside the engine layer');

$plain = $css($base);
$assert(! str_contains($plain, 'blocks-engine-reset') && ! str_contains($plain, '@layer'), 'unlayered author CSS output is unchanged');
$assert(str_contains($plain, 'blocks-engine-css-owned-flow'), 'unlayered output keeps the plain reset');
echo "ok\n";
