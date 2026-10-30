<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Neutral reduction of the declared source: separate desktop/mobile rows,
// with visibility owned by the shared mobile row, not the burger or menu.
$links = '<ul><li><label><input type="checkbox"><a href="/services">Services</a><ul><li><a href="/support">Support</a></li></ul></label></li><li><a href="/about">About</a></li></ul>';
$failures = array();
foreach (array('', ' aria-controls="mobile-menu"') as $binding) {
    $html = '<!doctype html><html><head><style>
.mobile-row{display:none}.burger{width:24px;height:18px}.bar{display:block;height:2px;background:currentColor}
@media screen and (max-width:920px){
.desktop-row{display:none}.mobile-row{display:grid;position:relative;grid-template-areas:"brand opener"}
.brand{grid-area:brand}.burger{grid-area:opener}
.dropdown{position:absolute;top:100%;left:0;width:100%;visibility:hidden;max-height:0;overflow:hidden}
}
</style></head><body><header><div class="desktop-row"><a href="/">Brand</a><nav>' . $links . '</nav></div>
<div class="mobile-row"><a class="brand" href="/">Brand</a><button class="burger" title="Menu"' . $binding . '><span class="bar"></span><span class="bar"></span><span class="bar"></span></button>
<div class="dropdown"><nav id="mobile-menu">' . $links . '</nav></div></div></header></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $html))))->toArray();
    preg_match_all('/<!-- wp:navigation (\{.*?\}) -->/', (string) $result['serialized_blocks'], $matches);
    $mobile = null;
    $desktop = null;
    foreach ($matches[1] as $json) {
        $attrs = json_decode($json, true);
        if ('mobile' === ($attrs['overlayMenu'] ?? null)) $mobile = $attrs;
        else $desktop = $attrs;
    }
    if (null === $mobile || null === $desktop) {
        $failures[] = 'Both layout navigation occurrences must survive, with a native mobile overlay.';
        continue;
    }
    $dom = new DOMDocument();
    $dom->loadHTML('<html><body></body></html>');
    $hosts = array();
    foreach (array('mobile' => $mobile, 'desktop' => $desktop) as $name => $attrs) {
        $host = $dom->createElement('nav');
        $host->setAttribute('class', 'wp-block-navigation ' . ($attrs['className'] ?? ''));
        $dom->getElementsByTagName('body')->item(0)->appendChild($host);
        foreach (array('opener' => 'wp-block-navigation__responsive-container-open', 'closed' => 'wp-block-navigation__responsive-container', 'open' => 'wp-block-navigation__responsive-container is-menu-open') as $state => $class) {
            $child = $dom->createElement('div');
            $child->setAttribute('class', $class);
            $host->appendChild($child);
            $hosts[$name][$state] = $child;
        }
    }
    foreach (array(600, 700, 768, 900, 920, 921, 1200) as $width) {
        $collapsed = array('opener' => false, 'closed' => false);
        foreach ($result['assets'] ?? array() as $asset) {
            if ('css' !== ($asset['kind'] ?? '')) continue;
            (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $selector, string $body, array $conditions) use ($width, $hosts, &$collapsed, &$failures): void {
                // Inspect only native boundary overrides, not the generic Core
                // list repairs. Check actual emitted host classes and conditions.
                if (!str_contains($selector, 'blocks-engine-native-navigation-toggle-') || !preg_match('/(?:^|;)display:(flex|none)!important/', $body, $display)) return;
                foreach ($conditions as $condition) {
                    if (preg_match('/min-width:\s*([\d.]+)px/', $condition, $m) && $width < (float) $m[1]) return;
                    if (preg_match('/max-width:\s*([\d.]+)px/', $condition, $m) && $width > (float) $m[1]) return;
                }
                $parsed = CssSelectorMatcher::parse($selector);
                foreach ($hosts as $name => $targets) {
                    foreach ($targets as $state => $target) {
                        if (!(CssSelectorMatcher::matches($target, $parsed, true)['matches'] ?? false)) continue;
                        if ('mobile' === $name && isset($collapsed[$state])) $collapsed[$state] = ('opener' === $state ? 'flex' : 'none') === $display[1];
                        if ('desktop' === $name || 'open' === $state || $width > 920) $failures[] = "$width: collapse override must leave desktop, open overlays and widths above the boundary alone.";
                    }
                }
            });
        }
        if ($width <= 920 && in_array(false, $collapsed, true)) $failures[] = "$width: native opener must show and closed inline container must hide through the source boundary.";
    }
}
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit(array() === $failures ? 0 : 1);
