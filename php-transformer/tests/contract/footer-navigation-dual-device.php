<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Both documents contain always-visible widget menus. The captured dialog
// binding is wrong, but the authored Bootstrap target still names the main menu.
$copy = static function (string $device, int $dialog): string {
    return '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-document-scope="">'
        . '<div id="content--data-blocks-engine-capture-' . $device . '">'
        . '<header><nav><button class="navbar-toggle data-blocks-engine-capture-responsive-counterpart-toggle" data-blocks-engine-capture-responsive-source="header:button:1" data-toggle="collapse" data-target=".navbar-collapse" aria-label="Menu" aria-haspopup="menu" aria-controls="data-blocks-engine-capture-dialog-' . $dialog . '" data-blocks-engine-capture-dialog-trigger="data-blocks-engine-capture-dialog-' . $dialog . '"><span class="icon-bar"></span></button>'
        . '<div class="navbar-collapse"><ul class="nav"><li><a href="/home">Home</a></li><li><a href="/about">About</a></li></ul></div></nav></header>'
        . '<main><div id="data-blocks-engine-capture-dialog-' . $dialog . '" class="flip-box-back" data-blocks-engine-capture-dialog-panel="true" hidden><p>Unrelated service information</p></div></main>'
        . '<footer><div class="widget widget_nav_menu data-blocks-engine-capture-responsive-counterpart-widget" data-blocks-engine-capture-responsive-source="footer:widget:1"><h3>Quick access</h3><div class="menu-quick-container"><ul id="menu-quick" class="menu"><li><a href="/webmail">Webmail</a></li><li><a href="/services">Services</a></li></ul></div></div></footer>'
        . '</div></div>';
};
$html = '<!DOCTYPE html><html><head><style>.menu{list-style:none;padding:0}.menu li{display:block}</style></head><body>' . $copy('desktop', 0) . $copy('mobile', 1) . '</body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $html))))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
preg_match_all('/<!-- wp:navigation (\{.*?\}) -->/', $markup, $matches);
$footerCount = 0;
$mainCount = 0;
$mainHasOpener = true;
foreach ($matches[1] as $json) {
    $attrs = json_decode($json, true);
    $classes = $attrs['className'] ?? '';
    if (str_contains($classes, 'menu-quick') || str_starts_with($attrs['anchor'] ?? '', 'menu-quick')) {
        ++$footerCount;
        if ('never' !== ($attrs['overlayMenu'] ?? '') || str_contains($classes, 'blocks-engine-native-responsive-navigation')) {
            throw new RuntimeException('Always-visible footer links must not acquire a native opener from the mobile document.');
        }
    } elseif (str_contains($classes, 'navbar-collapse')) {
        ++$mainCount;
        if ('mobile' !== ($attrs['overlayMenu'] ?? '') || !str_contains($classes, 'blocks-engine-native-navigation-toggle-')) {
            $mainHasOpener = false;
        }
    }
}
if (0 === $footerCount || 0 === $mainCount) {
    throw new RuntimeException('Expected both footer and main navigation in the compiled dual-device page.');
}
if (!$mainHasOpener) {
    throw new RuntimeException('The main menu must retain its authored opener despite the unrelated capture dialog.');
}
echo "Dual-device footer navigation contract passed\n";
