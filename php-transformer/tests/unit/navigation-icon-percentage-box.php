<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// The SVG's wrapper is discarded by native navigation lowering. Its percentage
// dimensions must resolve against that wrapper, not the shrink-to-fit anchor.
$result = (new HtmlTransformer())->transform(
    '<style>.icons{--icon-size:24px}.icon-box{width:var(--icon-size);height:var(--icon-size)}'
    . '.icon-box svg{width:100%;height:100%}</style>'
    . '<nav class="icons" aria-label="Social"><a href="https://example.com" aria-label="Profile">'
    . '<div class="icon-box"><svg><path d="M0 0h24v24H0z"></path></svg></div></a></nav>'
)->toArray();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
if (!str_contains($result['serialized_blocks'] ?? '', 'blocks-engine-navigation-link-icon-')
    || !preg_match('/__content(?::not\([^)]*\))*\{display:inline-block;[^}]*width:24px;height:24px;background-image:url\("data:image\/svg\+xml,/', $css)) {
    throw new RuntimeException('Native navigation must paint percentage-sized SVG artwork in its source wrapper box.');
}
echo "Navigation icon percentage box passed.\n";
