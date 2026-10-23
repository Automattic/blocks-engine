<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$css = '.menu-btn{width:24px;height:18px;display:none}.bar{display:block;height:2px;background:#000}'
    . '@media (max-width:920px){.menu-btn{display:block}}.drop{display:none}.sub-trigger{display:none}';
$toggle = '<button class="menu-btn"><span class="bar"></span><span class="bar"></span><span class="bar"></span></button>';
$fixture = '<nav><ul><li><label><input type="checkbox" class="sub-trigger"><a href="/a">A</a><ul><li><a href="/a1">A1</a></li></ul></label></li><li><label><a href="/b">B</a></label></li></ul></nav>';
$failures = array();
foreach (array('checkbox submenu' => $fixture, 'empty' => '<nav></nav>', 'plain control' => '<nav><a href="/a">A</a><a href="/b">B</a></nav>') as $case => $nav) {
    $html = '<!doctype html><html><head><style>' . $css . '</style></head><body><header><div>' . $toggle . '<div class="drop">' . $nav . '</div></div></header><main><h1>Hi</h1></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $html))))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $native = str_contains($markup, '<!-- wp:navigation ') && str_contains($markup, '"overlayMenu":"mobile"');
    $kept = 1 === preg_match('/<!-- wp:button \{[^\n]*?"className":"[^"]*\bmenu-btn\b/', $markup);
    if ('empty' === $case) {
        if (!$kept) $failures[] = 'Empty navigation must not silently drop its toggle.';
    } elseif ('plain control' === $case) {
        if (!$native || $kept) $failures[] = 'Convertible navigation must replace the source toggle with its native overlay.';
    } else {
        $destinations = str_contains($markup, '"url":"/a"') && str_contains($markup, '"url":"/a1"') && str_contains($markup, '"url":"/b"');
        if (!($native && $destinations) && !$kept) $failures[] = 'Checkbox submenu needs an overlay with every destination, or its source toggle retained.';
        if ($native && (!str_contains($markup, '<!-- wp:navigation-submenu ') || !$destinations)) $failures[] = 'Native checkbox navigation must preserve its submenu and all destinations.';
    }
}
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit(array() === $failures ? 0 : 1);
