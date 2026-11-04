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
$css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'editor' !== ($asset['stylesheet_target'] ?? 'both')), 'content'));
if (!str_contains($blocks, 'blocksEngineNavigationAnchor')
    || !preg_match('/\.wp-block-navigation-item\.[^{}]+ \.wp-block-navigation-item__content(?::not\(#[a-zA-Z0-9_-]+\))+\{display:inline-block;width:calc\(2 \* 1vw\);height:calc\(2 \* 1vw\);background-image:/', $css)
    || !preg_match('/@media \(max-width:1023px\)\{[^{}]+__content(?::not\(#[a-zA-Z0-9_-]+\))+\{width:calc\(6 \* 1vw\);height:calc\(6 \* 1vw\)\}/', $css)) {
    throw new RuntimeException('Native navigation must retain a paintable, responsive source icon box.');
}
if (str_contains($css, 'body:not(.fluid)')) {
    throw new RuntimeException('A standalone fragment must not activate a source-false body gate and push icons offscreen.');
}
// The site-plan interface has a native document metadata consumer. Unlike a
// standalone fragment it must preserve dormant gates for empty route contexts.
$artifact = (new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html', 'files' => array('index.html' => $html),
))->toArray();
$plan = (new \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan())->fromCompilerResult($artifact);
$nativeCss = implode("\n", array_column($plan['assets'], 'content'));
if (!str_contains($nativeCss, ':not(.fluid)') || !str_contains($nativeCss, 'min-width:980px')) {
    throw new RuntimeException('Native document plans must preserve body-class-gated author styles.');
}
echo "Responsive navigation icon box passed.\n";
