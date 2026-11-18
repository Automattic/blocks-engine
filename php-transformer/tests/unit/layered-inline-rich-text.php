<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Footer</title>
<style>@layer utilities{.fs{font-size:var(--fs)}.lh{line-height:var(--lh)!important}}body{margin:0;font:18px/1.5 sans-serif}footer{text-align:center;padding:24px}</style></head>
<body><main><h1>Store</h1><p>Products.</p></main>
<footer><p><span class="fs" style="--fs:12px">2026 © Store</span><span class="fs" style="--fs:12px">    </span><a class="fs lh" style="--fs:12px;--lh:0.25" href="/support/">Customer Service    </a><a class="fs lh" style="--fs:12px;--lh:0.25" href="mailto:x@example.com">Report Abuse</a></p></footer>
</body></html>
HTML;
$result = (new HtmlTransformer())->transform($html, array())->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
if ( ! str_contains($result['serialized_blocks'], '<a class="fs lh"') ) {
    throw new RuntimeException('Inline links must remain rich-text descendants, not replacement blocks');
}
if ( ! str_contains($css, 'font-size:var(--fs)') || ! str_contains($css, '.lh{line-height:var(--lh)!important}') ) {
    throw new RuntimeException('Layered inline typography must remain owned by the authored stylesheet');
}
if ( str_contains($css, 'font-size:revert-layer') || str_contains($css, 'line-height:revert-layer') ) {
    throw new RuntimeException('Inline rich-text subjects must not receive a block-default layer bridge');
}
echo "Layered inline rich-text contract passed\n";
