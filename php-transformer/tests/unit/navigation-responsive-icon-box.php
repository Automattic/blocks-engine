<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// List lowering preserves anchor metadata, but discards the SVG's sizing
// wrapper. Its inherited, media-dependent variables must follow the artwork.
$html = '<html><head><style>'
    . 'body:not(.fluid) #page{min-width:980px}'
    . '.icons{--unit:1vw;--size:calc(2 * var(--unit))}'
    . '@media (max-width:1023px){.icons{--size:calc(6 * var(--unit))}}'
    . '.item-container{padding:2px}.profile{padding:0}.box{width:var(--size);height:var(--size)}.box svg{width:100%;height:100%}'
    . '</style></head><body class="fluid"><div id="page"><nav aria-label="Social Bar">'
    . '<ul class="icons"><li><div class="item-container"><a class="profile" href="https://example.com" aria-label="Profile">'
    . '<div class="box"><svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"></path></svg></div>'
    . '</a></div></li></ul></nav></div></body></html>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$blocks = $result['serialized_blocks'] ?? '';
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
if (!str_contains($blocks, 'blocksEngineNavigationAnchor')
    || !preg_match('/\.wp-block-navigation-item\.[^{}]+ \.wp-block-navigation-item__content\{display:inline-block;width:calc\(2 \* 1vw\);height:calc\(2 \* 1vw\);background-image:/', $css)
    || !preg_match('/@media \(max-width:1023px\)\{[^{}]+__content\{width:calc\(6 \* 1vw\);height:calc\(6 \* 1vw\)\}/', $css)
    || str_contains($css, 'body:not(.fluid) #page')) {
    throw new RuntimeException('Native navigation must retain a paintable, responsive source icon box without activating source-false document rules.');
}
echo "Responsive navigation icon box passed.\n";
