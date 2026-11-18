<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\WordPressCompatCss;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Navigation replays move an anchor rule onto core/navigation as the anchor's
// own host (`X.wp-block-navigation`) or an ancestor (`X .wp-block-navigation`).
// An image block is never also the navigation block, and neither an image
// block nor a paragraph can contain one: those arms match nothing and only
// add stylesheet weight. A real menu wrapper still replays.

$html = '<header class="menu"><nav><a href="/">Home</a></nav></header>'
    . '<figure class="wp-block-image"><a href="/"><img src="a.png" alt=""></a></figure>'
    . '<p class="carrier"><a href="/"><small>Note</small></a></p>';
$css = (new WordPressCompatCss())->css(
    ':where(.blocks-engine-image).wp-block-image > a > img{display:block}'
    . 'p.carrier > a > small{font-size:9px}'
    . '.menu a{color:red}',
    array(array('kind' => 'html', 'content' => $html)),
    array()
);

preg_match_all('/([^{}]+)\{/', (string) preg_replace('#/\*.*?\*/#s', '', $css), $preludes);
$arms = array_merge(...array_map(static fn (string $prelude): array => CssStylesheetTransformer::splitSelectorList($prelude) ?? array(), $preludes[1]));
foreach ($arms as $arm) {
    if (str_contains($arm, '.wp-block-navigation') && (str_contains($arm, 'wp-block-image') || str_contains($arm, 'p.carrier'))) {
        fwrite(STDERR, 'FAIL: impossible navigation replay emitted: ' . $arm . PHP_EOL);
        exit(1);
    }
}
if (!str_contains($css, '.menu .wp-block-navigation .wp-block-navigation-item__content')) {
    fwrite(STDERR, 'FAIL: a menu wrapper anchor rule no longer replays onto core/navigation' . PHP_EOL);
    exit(1);
}

echo "WordPress compatibility impossible navigation hosts passed\n";
