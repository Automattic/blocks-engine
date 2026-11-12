<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A same-site jump <select> inside a link row is a submenu: the row still
// lowers to core/navigation, so Core supplies the responsive menu opener
// where the source showed its own burger.
$css = 'body{background:#0b0b04;color:#f3e6dd}.bar{display:flex;align-items:center;height:88px;background:rgba(20,20,10,.86)}.links{color:#f3e6dd;display:flex;align-self:stretch;align-items:center;gap:36px;text-transform:uppercase}'
    . '.dd{position:relative}.dd-select{font-weight:600}.dd-icon{position:absolute;right:8px}'
    . '.badged{display:flex;align-items:center;gap:8px}.tag{padding:3px 8px}'
    . '.burger{display:none}@media (max-width:900px){.links{display:none}.burger{display:flex}}';
$page = static fn(string $control): string => '<!doctype html><html><head><link rel="stylesheet" href="s.css"></head><body><header class="bar"><a href="/">Brand</a>'
    . '<nav aria-label="Main" class="links">' . $control . '<a href="/s/">Stories</a><a href="/v/">Visit</a><a class="badged" href="/f/"><span>Function room</span><span class="tag">New</span></a></nav>'
    . '<button aria-label="Open menu" aria-expanded="false" class="burger" type="button"><span class="bars"><span></span></span></button></header><main><p>Hi</p></main></body></html>';
$compile = static function (string $html) use ($css): array {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 's.css' => $css)))->toArray();
    $json = (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    preg_match('/<!-- wp:navigation \{.*?<!-- \/wp:navigation -->/s', stripcslashes($json), $nav);
    return array('nav' => $nav[0] ?? '', 'json' => stripcslashes($json));
};
$options = '<option value="/menus/lunch/">Lunch</option><option value="/menus/main/">Main menu</option>';

$failures = array();
$jump = $compile($page('<div class="dd"><select class="dd-select"><option disabled value="" selected>Menus</option>' . $options . '</select><div class="dd-icon">▼</div></div>'));
foreach (array(
    'converts to a responsive navigation' => str_contains($jump['nav'], '"overlayMenu":"mobile"'),
    'placeholder labels the submenu; the glyph keeps its own weight' => str_contains($jump['nav'], '"label":"Menus\\u003cspan class=\\u0022dd-icon\\u0022 style=\\u0022font-weight:normal\\u0022 aria-hidden=\\u0022true\\u0022\\u003e▼\\u003c/span\\u003e"'),
    'each option is a submenu link' => str_contains($jump['nav'], '"label":"Lunch","url":"/menus/lunch/"') && str_contains($jump['nav'], '"label":"Main menu","url":"/menus/main/"'),
    'source glyph replaces core icon' => str_contains($jump['nav'], '"showSubmenuIcon":false'),
    'control keeps form text resets' => str_contains($jump['nav'], 'text-transform:none'),
    'submenu anchor styling skips its child links' => str_contains($jump['json'], ' :where(.wp-block-navigation-item__content):not(:where('),
    'overlay opens on the opaque surface behind the menu' => str_contains($jump['json'], 'is-menu-open:not(.disable-default-overlay){background:#0b0b04!important;color:#f3e6dd!important}'),
    'a flex anchor keeps its label parts as its items' => str_contains($jump['json'], '.badged>.wp-block-navigation-item__content>.wp-block-navigation-item__label{display:contents}'),
    'host alignment outranks the list baseline' => str_contains($jump['json'], ':root .wp-block-navigation.links{display:flex;align-items:center}'),
) as $name => $ok) {
    if (!$ok) $failures[] = $name;
}

// Forms, multi-selects, and off-site targets are not navigation.
foreach (array(
    'select in a form' => '<form><select><option value="">Menus</option>' . $options . '</select></form>',
    'multiple select' => '<select multiple><option value="">Menus</option>' . $options . '</select>',
    'external targets' => '<select><option value="">Menus</option><option value="https://example.org/a">A</option><option value="https://example.org/b">B</option></select>',
) as $name => $control) {
    if (str_contains($compile($page($control))['nav'], 'wp:navigation-submenu')) $failures[] = $name . ' stays a form control';
}

foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
if (array() !== $failures) exit(1);
echo "navigation jump select: ok\n";
