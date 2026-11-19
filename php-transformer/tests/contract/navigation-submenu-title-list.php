<?php
declare(strict_types=1);

// A submenu panel that restates its href-less toggle as a one-link title list
// before the child links converts to core/navigation-submenu: the title link
// gives the parent its destination, and the offcanvas menu keeps core's
// mobile overlay (https://github.com/Automattic/blocks-engine/issues/6764).
// The title list may also carry the panel's href-less back/close controls, and
// a list misnested directly after an item is that item's child menu.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$compile = static function (string $titleLabel): array {
    $css = '.navbar-toggler{display:none}.offcanvas{position:fixed;top:0;right:0;bottom:0;visibility:hidden;transform:translateX(100%)}'
        . '.offcanvas.show{visibility:visible;transform:none}@media(max-width:991px){.navbar-toggler{display:block;width:35px;height:28px}}';
    $submenu = '<li class="nav-item dropdown"><a class="nav-link" data-toggle="offcanvas-submenu" data-target="#sub0" aria-expanded="false">Accelerator</a>'
        . '<div class="panel" id="sub0"><ul class="a"><li><a class="back" aria-label="Back"><svg viewBox="0 0 8 8"><path d="M0 0h8v8H0z"/></svg></a><a class="close" aria-label="Close"><span></span></a></li>'
        . '<li><a href="https://example.com/accelerator/">' . $titleLabel . '</a></li></ul>'
        . '<ul class="b"><li><a href="https://example.com/accelerator/about/">About the accelerator</a></li>'
        . '<ul class="c"><li><a href="https://example.com/accelerator/about/team/">Team</a></li></ul>'
        . '<li><a href="https://example.com/accelerator/funding/">Funding</a></li></ul></div></li>';
    $html = '<!doctype html><html><head><style>' . $css . '</style></head><body><header><nav class="navbar" aria-label="Main menu"><div class="container">'
        . '<a class="navbar-brand" href="https://example.com/">Brand</a>'
        . '<button id="menu-btn" class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#mob-nav" aria-controls="mob-nav" aria-label=""><span aria-hidden="true"></span></button>'
        . '<div id="mob-nav" class="offcanvas offcanvas-end" tabindex="-1" aria-label="Main menu"><ul class="nav navbar-nav">' . $submenu
        . '<li class="nav-item"><a class="nav-link" href="https://example.com/events/">Events</a></li></ul></div>'
        . '</div></nav></header><main><h1>Contact</h1></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'];

    return array(
        implode("\n", array_column($plan['template_parts'], 'canonical_block_markup'))
            . implode("\n", array_column($plan['pages'], 'canonical_block_markup'))
            . "\n" . json_encode($plan, JSON_UNESCAPED_SLASHES),
        $result,
    );
};

[$markup, $result] = $compile('Accelerator');
if ( 1 !== preg_match('/<!-- wp:navigation \{[^>]*"overlayMenu":"mobile"/', $markup) ) {
    throw new RuntimeException('the offcanvas menu must convert with the native mobile overlay.');
}
if ( 1 !== preg_match('/<!-- wp:navigation-submenu (\{.*?\}) -->(.*)/s', stripslashes($markup), $match) ) {
    throw new RuntimeException('the titled panel must convert to a navigation submenu.');
}
$attrs = json_decode($match[1], true);
if ( 'Accelerator' !== ($attrs['label'] ?? null) || 'https://example.com/accelerator/' !== ($attrs['url'] ?? null) ) {
    throw new RuntimeException('the submenu takes its label from the toggle and its url from the title link: ' . $match[1]);
}
preg_match_all('/<!-- wp:(navigation-link|navigation-submenu) (\{.*?\}) \/?-->/s', $match[0], $items, PREG_SET_ORDER);
$items = array_slice(array_map(static fn (array $item): string => $item[1] . ':' . (json_decode($item[2], true)['label'] ?? ''), $items), 0, 4);
if ( array('navigation-submenu:Accelerator', 'navigation-submenu:About the accelerator', 'navigation-link:Team', 'navigation-link:Funding') !== $items ) {
    throw new RuntimeException('the submenu owns the child links, nests the misnested list under the item before it, and drops the title link and panel controls: ' . implode(', ', $items));
}
if ( ! isset($result['blocks']) || 'pass' !== (new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ) {
    throw new RuntimeException('output must have valid native save grammar.');
}

// A first list that does not restate the toggle is not a title list.
[$markup] = $compile('Something else');
if ( str_contains(stripslashes($markup), '"label":"Accelerator","url":"https://example.com/accelerator/"') ) {
    throw new RuntimeException('a first list with another label must not become the submenu parent.');
}

echo "navigation submenu title list contract passed\n";
