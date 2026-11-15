<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<!doctype html><html><head><meta charset="utf-8"><title>Notice</title>
<style>body{margin:0;font:16px sans-serif}.box{position:relative;padding:24px}.close{position:absolute;top:8px;right:8px;width:32px;height:32px;border:0;background:none}</style></head>
<body><main><div class="box"><h1>Added to cart</h1><p>Your item is in the cart.</p>
<control-wrapper data-control=""><button type="button" class="close" aria-label="Close"><svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor"/></svg></button></control-wrapper>
</div></main></body></html>';
$transformer = new HtmlTransformer();
$control = $transformer->transform(str_replace('control-wrapper', 'span', $html))->toArray();
if ( ! str_contains($control['serialized_blocks'] ?? '', 'wp:custom/authored-button') || ! str_contains($control['serialized_blocks'] ?? '', '<svg') ) {
    fwrite(STDERR, "FAIL: reference span must retain the native icon button\n");
    exit(1);
}
foreach ( array($html, str_replace(' data-control=""', '', $html), str_replace('control-wrapper', 'action-container', $html)) as $source ) {
    $result = $transformer->transform($source)->toArray();
    $unsupported = array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_unsupported_element' === ($finding['diagnostic_code'] ?? ''));
    if ( array() !== $unsupported
        || ($result['serialized_blocks'] ?? '') !== $control['serialized_blocks']
        || ($result['assets'] ?? array()) !== ($control['assets'] ?? array())
    ) {
        fwrite(STDERR, "FAIL: neutral control wrapper must match span blocks and geometry CSS without unsupported fallback\n" . json_encode($result, JSON_PRETTY_PRINT) . "\n");
        exit(1);
    }
}

// A captured definition still owns the host; ordinary light DOM is not enough.
$runtime = $transformer->transform(str_replace('</body>', '<script>customElements.define("control-wrapper", class extends HTMLElement {});</script></body>', $html))->toArray();
if ( ($runtime['serialized_blocks'] ?? '') === $control['serialized_blocks'] ) {
    fwrite(STDERR, "FAIL: a proven runtime must retain its existing preservation path\n");
    exit(1);
}
echo "Render-neutral custom control wrapper tests passed\n";
