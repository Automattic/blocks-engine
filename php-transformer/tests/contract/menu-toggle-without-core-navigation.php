<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A hamburger beside a hidden menu panel is only safe to drop when the menu
// becomes core/navigation, whose responsive overlay replaces it. Submenus bound
// by a checkbox inside their label must therefore convert, keeping every
// destination as navigation-link / navigation-submenu blocks.
$css = '.menu-btn{width:24px;height:18px;display:none}.bar{display:block;height:2px;background:#000}'
    . '@media (max-width:920px){.menu-btn{display:block}}.drop{display:none}.sub-trigger{display:none}';
$toggle = '<button class="menu-btn"><span class="bar"></span><span class="bar"></span><span class="bar"></span></button>';
$cases = array(
    'checkbox submenu' => array('<nav><ul><li><label><input type="checkbox" class="sub-trigger"><a href="/a">A</a><ul><li><a href="/a1">A1</a></li></ul></label></li><li><label><a href="/b">B</a></label></li></ul></nav>', array('/a', '/a1', '/b'), true),
    'plain links' => array('<nav><a href="/a">A</a><a href="/b">B</a></nav>', array('/a', '/b'), false),
);
$failures = array();
foreach ($cases as $case => [$nav, $urls, $submenu]) {
    $html = '<!doctype html><html><head><style>' . $css . '</style></head><body><header><div>' . $toggle . '<div class="drop">' . $nav . '</div></div></header><main><h1>Hi</h1></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $html))))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    if (!str_contains($markup, '<!-- wp:navigation ') || !str_contains($markup, '"overlayMenu":"mobile"')) {
        $failures[] = "$case: menu must convert to core/navigation with the mobile overlay.";
    }
    foreach ($urls as $url) {
        if (!str_contains($markup, '"url":"' . $url . '"')) {
            $failures[] = "$case: destination $url must be a navigation link.";
        }
    }
    if ($submenu && !str_contains($markup, '<!-- wp:navigation-submenu ')) {
        $failures[] = "$case: nested list must become a navigation-submenu.";
    }
    if (1 === preg_match('/<!-- wp:button \{[^\n]*?"className":"[^"]*\bmenu-btn\b/', $markup) || str_contains($markup, 'sub-trigger')) {
        $failures[] = "$case: source toggle and checkbox are replaced by the native overlay.";
    }
}
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit(array() === $failures ? 0 : 1);
