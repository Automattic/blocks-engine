<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Group save() renders `<tagName>` from the stored attribute, so the comment and
// the wrapper must name the same tag or the editor reports the block invalid.
$menu = (new HtmlTransformer())->transform('<main><menu class="mobile-menu"><button class="toggle">Menu</button><span>About</span></menu></main>');
$markup = is_array($menu) ? (string) ($menu['serialized'] ?? '') : (string) $menu->serializedBlocks;
if ( ! str_contains($markup, '"tagName":"menu"') || ! preg_match('/"tagName":"menu"[^>]*-->\s*<menu class="wp-block-group/', $markup) ) {
    throw new RuntimeException('A source <menu> of controls must keep its tag in both the group attribute and its wrapper: ' . $markup);
}

$unsupported = (new BlockFactory())->create('core/group', array( 'tagName' => 'blink' ));
if ( 'div' !== ($unsupported['attrs']['tagName'] ?? null) || ! str_starts_with($unsupported['innerHTML'], '<div class="wp-block-group') ) {
    throw new RuntimeException('An unsupported group tag must fall back to div in both the attribute and the wrapper.');
}

echo "group tag name save agreement passed\n";
