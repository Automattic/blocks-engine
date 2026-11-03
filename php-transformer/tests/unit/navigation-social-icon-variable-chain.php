<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// A builder sizes artwork through a discarded wrapper and a media-only
// variable whose dependencies live on several ancestors (issue #4083).
$html = '<html><head><style>'
    . ':root{--unit:1vw;--minimum:0px;--maximum:9999px;--zoom:1}'
    . 'body{--scrollbar:0px}.section{--limit:1920px;--viewport:100 * var(--unit) * var(--zoom);'
    . '--scale:clamp(var(--minimum),var(--viewport),min(var(--maximum),var(--limit)))}'
    . '@media (max-width:1023px){.social{--size:max(0.5px,0.0615385 * (var(--scale) - var(--scrollbar)))}}'
    . '.social .artwork{width:var(--size);height:var(--size)}'
    . '.social .artwork svg{width:100%;height:100%}'
    . '</style></head><body><div class="section"><div class="social">'
    . '<nav aria-label="Social Bar"><a href="https://example.com" aria-label="Profile">'
    . '<div class="artwork"><svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"></path></svg></div>'
    . '</a></nav></div></div></body></html>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'editor' !== ($asset['stylesheet_target'] ?? 'both')), 'content'));
$size = 'max(0.5px,0.0615385 * (clamp(0px,100 * 1vw * 1,min(9999px,1920px)) - 0px))';
if (!str_contains($result['serialized_blocks'] ?? '', 'wp:navigation-link')
    || !preg_match('/@media \(max-width:1023px\)\{[^{}]*wp-block-navigation-item__content(?::not\(#[a-zA-Z0-9_-]+\))+\{width:' . preg_quote($size, '/') . ';height:' . preg_quote($size, '/') . '\}/', $css)) {
    throw new RuntimeException('Navigation artwork must retain its wrapper size and ancestor variable chain under the source media condition.');
}
echo "Social navigation icon variable chain passed.\n";
