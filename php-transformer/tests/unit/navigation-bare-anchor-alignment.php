<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A wrapped link makes the source flex row taller. Its other anchors start at
// the same top edge, not vertically centered by Core's synthetic list/items.
$html = '<style>.menu{display:flex;gap:32px;width:360px;font-size:12px}</style>'
    . '<header><nav class="menu"><a href="/shop">Shop</a>'
    . '<a href="/fitment">Fitment Centre</a><a href="/contact">Contact</a></nav></header>'
    . '<main><h1>Home</h1></main>';
$result = (new HtmlTransformer())->transform($html, array())->toArray();
$markup = (string) $result['serialized_blocks'];
$css = '';
foreach ($result['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? '')) {
        $css .= $asset['content'] ?? '';
    }
}
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$assert(str_contains($markup, 'wp:navigation '), 'bare anchors remain native navigation');
$assert(str_contains($markup, 'blocks-engine-anchor-items-navigation'), 'bare-anchor navigation is marked independently of responsive toggles');
$assert(str_contains($css, '.wp-block-navigation.blocks-engine-anchor-items-navigation{align-items:normal}'), 'source flex alignment replaces Core centering on the host and cloned list');
$assert(str_contains($css, '.wp-block-navigation:where(.blocks-engine-anchor-items-navigation) .wp-block-navigation-item{align-items:normal}'), 'synthetic items stretch their anchor instead of centering it');
echo "Bare-anchor navigation alignment passed\n";
