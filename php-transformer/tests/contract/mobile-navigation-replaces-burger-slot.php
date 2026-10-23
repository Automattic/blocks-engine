<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Reduction of a two-layout header: the desktop copy must not prevent the
// collapsed, out-of-flow mobile panel from moving to its opener's grid slot.
// The responsive at-rule is intentional: unconditional hidden-state analysis
// alone cannot discover this panel. The labelled checkbox remains a submenu.
$links = '<ul><li><label><input type="checkbox"><a href="/services">Services</a><ul><li><a href="/support">Support</a></li></ul></label></li><li><a href="/about">About</a></li></ul>';
$css = <<<'CSS'
.mobile-row{display:none}
.burger{width:24px;height:18px}
.bar{display:block;height:2px;background:currentColor}
@media(max-width:920px){
.desktop-row{display:none}
.mobile-row{display:grid;position:relative;grid-template-columns:auto max-content;grid-template-areas:"brand opener";align-items:center;height:96px}
.brand{grid-area:brand}
.burger{grid-area:opener}
.dropdown{position:absolute;top:100%;left:0;width:100%;visibility:hidden;max-height:0;overflow:hidden}
}
CSS;
$failures = array();
foreach (array('absolute', 'fixed') as $position) {
    $html = '<!doctype html><html><head><style>' . str_replace('position:absolute', 'position:' . $position, $css) . '</style></head><body><header>'
        . '<div class="desktop-row"><a href="/">Brand</a><nav>' . $links . '</nav></div>'
        . '<div class="mobile-row"><a class="brand" href="/">Brand</a><button class="burger" title="Menu"><span class="bar"></span><span class="bar"></span><span class="bar"></span></button>'
        . '<div class="dropdown"><nav>' . $links . '</nav></div></div></header></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $html))))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $dom = new DOMDocument();
    @$dom->loadHTML($markup);
    $xpath = new DOMXPath($dom);
    $row = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," mobile-row ")]')->item(0);
    $navigation = null;
    if ($row instanceof DOMElement) {
        foreach ($row->childNodes as $child) {
            if ($child instanceof DOMComment && preg_match('/^ wp:navigation (\{.*\}) /s', $child->data, $match)) {
                $navigation = json_decode($match[1], true);
            }
        }
    }
    if (!is_array($navigation) || 'mobile' !== ($navigation['overlayMenu'] ?? null)) {
        $failures[] = "$position: native mobile navigation must be a direct child of the header row at the dropped burger's slot.";
    }
    // The native open button is a child of this host. Its host must inherit
    // the burger's named grid area, not auto-place into an empty cart track.
    $artifact = (string) json_encode($result, JSON_UNESCAPED_SLASHES);
    if (!str_contains($artifact, 'grid-area:opener!important')) {
        $failures[] = "$position: replacement navigation must carry the burger's authored grid slot.";
    }
    if (str_contains($markup, 'class="wp-block-group dropdown') || str_contains($markup, '<button')) {
        $failures[] = "$position: neither the collapsed panel nor the inert source button may retain the opener.";
    }
    foreach (array('/services', '/support', '/about') as $url) {
        if (substr_count($markup, '"url":"' . $url . '"') !== 2) {
            $failures[] = "$position: both layout occurrences must retain $url.";
        }
    }
    if (!str_contains($markup, '<!-- wp:navigation-submenu ')) {
        $failures[] = "$position: labelled checkbox submenu must still convert.";
    }
}
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit(array() === $failures ? 0 : 1);
