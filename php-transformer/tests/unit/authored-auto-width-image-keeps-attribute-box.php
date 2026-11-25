<?php
/**
 * An authored `width:auto` image without width/height attributes resolves to
 * its natural width once loaded. WordPress renders the attachment's
 * width/height attributes, which already reserve that box before load; no
 * projected rule may override them with `width:auto` (a lazy image would be
 * 0x0 until loaded). A height-constrained image still restates its free axis.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n{$detail}\n");
        exit(1);
    }
};
$transform = static function (string $style): array {
    $html = '<div style="width:1284px"><div class="wsite-image"><a href="/x.jpg"><img src="/x.jpg" alt="Picture" style="' . $style . '"></a></div></div>';
    $out = (new HtmlTransformer())->transform($html)->toArray();
    $css = implode("\n", array_column(array_filter($out['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    return array($out['serialized_blocks'], $css);
};
$imageWidthRules = static function (string $css): array {
    preg_match_all('/[^{}]*\bimg\b[^{}]*\{[^}]*(?<![-\w])width:\s*auto[^}]*\}/', $css, $matches);
    return $matches[0];
};

[$blocks, $css] = $transform('max-width:100%;width:auto');
$assert(str_contains($blocks, '<!-- wp:image'), 'the authored image lowers to core/image', $blocks);
$assert(array() === $imageWidthRules($css), 'no projected rule overrides the image width attributes with width:auto', $css);
$assert(1 === preg_match('/\bimg\b[^{}]*\{[^}]*max-width:100%/', $css), 'the authored max-width still reaches the image', $css);

[, $css] = $transform('height:200px;width:auto');
$assert(array() !== $imageWidthRules($css), 'a height-constrained image still restates its free width axis', $css);

echo "authored auto-width image keeps attribute box: passed\n";
