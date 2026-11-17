<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A flex row of bare anchors stretches them (align-items:normal): each anchor
// is as tall as the row and its text starts at the row's top. Core centers its
// synthetic list and items, so the projection must restore the stretch, and
// must leave an authored alignment alone.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$compile = static function (string $menuCss): array {
    $html = '<style>.menu{display:flex;gap:32px;' . $menuCss . '}.menu a{display:block;padding:8px 0}</style>'
        . '<header><nav class="menu"><a href="/shop">Shop</a>'
        . '<a href="/fitment">Fitment</a><a href="/contact">Contact</a></nav></header>'
        . '<main><h1>Home</h1></main>';
    $result = (new HtmlTransformer())->transform($html, array())->toArray();
    $css = '';
    foreach ($result['assets'] as $asset) {
        if ('css' === ($asset['kind'] ?? '')) {
            $css .= $asset['content'] ?? '';
        }
    }

    return array((string) $result['serialized_blocks'], $css);
};
$stretch = '.wp-block-navigation.menu :is(.wp-block-navigation__container,.wp-block-navigation-item){align-items:normal}';

[$markup, $css] = $compile('');
$assert(str_contains($markup, '<!-- wp:navigation '), 'bare anchors remain native navigation');
$assert(str_contains($css, $stretch), 'default source alignment stretches the synthetic list and items');

[, $centeredCss] = $compile('align-items:center;');
$assert(!str_contains($centeredCss, $stretch), 'authored centering is not overridden');

[, $columnCss] = $compile('flex-direction:column;');
$assert(!str_contains($columnCss, $stretch), 'a column stack keeps its own cross-axis handling');

echo "Bare-anchor navigation row stretch passed\n";
