<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = '<style>.menu{display:flex;flex-direction:column}button{display:block}@media(min-width:992px){.menu{flex-direction:row}button{display:none}}</style><header><button aria-controls="links" aria-expanded="false" aria-label="Menu"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button><nav id="links"><ul class="menu"><li><a href="/">Home</a></li><li><a href="/about">About</a></li><li><a href="/work">Work</a></li></ul></nav></header>';
$result = (new HtmlTransformer())->transform($source, array())->toArray();
if (!str_contains($result['serialized_blocks'], 'blocks-engine-native-responsive-navigation')) {
    throw new RuntimeException('Fixture must compile through native responsive navigation');
}
$css = implode("\n", array_column($result['assets'], 'content'));
if (in_array('--json', $argv, true)) {
    echo json_encode(array('css' => $css));
} else {
    if (!str_contains($css, '.wp-block-navigation__responsive-container:not(.is-menu-open) .wp-block-navigation__responsive-container-content{flex-direction:inherit}')) {
        throw new RuntimeException('Closed overlay transport must carry the source responsive direction');
    }
    echo "Responsive navigation direction contract passed\n";
}
