<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$html = '<style>.cs-menu-items{list-style:none;display:flex}'
    . '@media only screen and (max-width:767px){.cs-section .cs-menu{position:fixed;top:0;right:0;bottom:0;z-index:3;width:0}'
    . '.cs-menu-items{width:370px;transform:translateX(370px);translate:370px}}</style>'
    . '<header class="cs-section"><button aria-controls="menu" aria-expanded="false">Menu</button>'
    . '<nav id="menu" class="cs-menu"><ul class="cs-menu-items"><li><a href="/a">A</a></li><li><a href="/b">B</a></li></ul></nav></header>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));
$assert(str_contains(json_encode($result['blocks']), 'blocks-engine-native-responsive-navigation'), 'the native overlay replaces the source drawer');
$assert(1 === preg_match('/@media\(max-width:[^{}]+\)\{([^{}]+)\{([^{}]+)\}([^{}]+)\{transform:none!important;translate:none!important;will-change:auto!important\}/', $css, $repair), 'the transform reset is inside the collapsed breakpoint');
$host = trim($repair[1]);
$assert($repair[3] === $host . ',' . $host . ' .wp-block-navigation__container', 'both the host and repeated source list classes lose the stale translation');
$assert(str_contains($css, 'transform:translateX(370px)'), 'authored rules remain available outside the native collapsed range');
$assert(1 === substr_count($css, 'transform:none!important;translate:none!important'), 'no unconditional or desktop translation reset is emitted');

// Ordinary link rows have no replacement control and keep authored transforms.
$ordinary = (new HtmlTransformer())->transform(str_replace('<button aria-controls="menu" aria-expanded="false">Menu</button>', '', $html))->toArray();
$ordinaryCss = implode("\n", array_column($ordinary['assets'], 'content'));
$assert(! str_contains($ordinaryCss, 'transform:none!important;translate:none!important'), 'ordinary navigation is not reset');
echo "ok\n";
