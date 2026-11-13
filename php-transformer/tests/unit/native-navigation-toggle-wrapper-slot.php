<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

// brand | nav | actions{cart, toggle}: the collapsed nav leaves the flow, so
// the source shows the toggle at the trailing edge after the cart. Core's
// opener renders inside the navigation host, which sits in the nav's slot.
$header = static fn (string $actions): string => '<style>.nav-wrap{display:flex;align-items:center;justify-content:space-between}.nav{display:flex;gap:28px}.nav-actions{display:flex;align-items:center;gap:4px}.nav-toggle{display:none}'
    . '@media(max-width:800px){.nav-toggle{display:flex}.nav{position:absolute;top:80px;left:0;right:0;flex-direction:column;opacity:0;pointer-events:none}.nav.open{opacity:1;pointer-events:auto}}</style>'
    . '<header><div class="nav-wrap"><a href="/" class="brand">Brand</a><nav class="nav" id="menu"><a href="/a/">A</a><a href="/b/">B</a></nav>'
    . '<div class="nav-actions">' . $actions . '</div></div></header>'
    . '<script>document.querySelector(".nav-toggle").onclick=()=>document.getElementById("menu").classList.toggle("open")</script>';
$toggle = '<button class="nav-toggle" aria-controls="menu" aria-expanded="false" aria-label="Menu"><span></span></button>';
$transform = static function (string $html): array {
    $result = (new HtmlTransformer())->transform($html, array())->toArray();
    $css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? ''))));
    return array($result, $css);
};

[$result, $css] = $transform($header('<a class="cart-btn" href="/cart/">Cart</a>' . $toggle));
$assert(1 === preg_match('/@media\(max-width:800px\)\{[^@]*?(\.blocks-engine-native-navigation-toggle-[a-f0-9]+)\{[^}]*order:1!important;margin-inline-start:4px!important\}[^@]*?\1~:last-child\{margin-inline-start:auto!important\}/', $css), 'collapsed: the host follows the actions wrapper, spaced by its gap, and the wrapper keeps the trailing edge');
$assert(1 === preg_match('/<!-- wp:navigation .*<!-- \/wp:navigation -->\s*<!-- wp:group \{[^}]*"className":"nav-actions[^"]*"/s', (string) ($result['serialized_blocks'] ?? '')), 'the actions wrapper block is the sibling after the navigation that the rule addresses');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'the reflowed header stays editor-valid');

// A toggle that does not close its wrapper keeps the navigation's slot.
[, $css] = $transform($header($toggle . '<a class="cart-btn" href="/cart/">Cart</a>'));
$assert(! str_contains($css, '~:last-child{margin-inline-start:auto!important}') && ! str_contains($css, 'order:1!important'), 'a toggle before other actions is not moved');

fwrite(STDOUT, "Native navigation toggle wrapper slot tests passed\n");
