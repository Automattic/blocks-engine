<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Core's vertical orientation must not freeze a source's mobile column at
// desktop widths. Exercise the public transformer, including support CSS.
$result = (new HtmlTransformer())->transform(
    '<style>.menu{display:flex;flex-direction:column}'
    . '@media(min-width:992px){.menu{flex-direction:row}}</style>'
    . '<nav class="menu"><a href="/a">A</a><a href="/b">B</a></nav>'
)->toArray();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
if (!preg_match('/@media\(min-width:992px\)\{[^{}]*\.wp-block-navigation__container\{flex-direction:row!important\}/', $css)) {
    throw new RuntimeException('Responsive column navigation must project the desktop row onto Core\'s inner item container.');
}
echo "Responsive navigation direction: passed\n";
