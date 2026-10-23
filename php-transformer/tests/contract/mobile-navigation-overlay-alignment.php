<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Source reduction: the mobile panel is an absolute, collapsed column with
// padding:0 16px and align-items:flex-end; its list and links use text-align:end.
// Desktop submenus are centered. Keep only those conflicting rules, the
// two-layout header and the labelled checkbox from the burger-slot contract.
$links = '<ul><li class="block-header-item"><label><input type="checkbox"><a href="/services">Services</a><ul><li class="block-header-item"><a class="item-content" href="/support">Technical support</a></li><li><a href="/compare">Compare tools</a></li></ul></label></li><li class="block-header-item"><label><div class="item-content-wrapper"><a class="item-content" href="/about">About</a></div></label></li></ul>';
$css = <<<'CSS'
.mobile-row{display:none}
.block-header-item,.item-content{align-self:center}
.item-content-wrapper{display:flex;align-items:center}
nav ul ul{display:flex;align-items:center;text-align:center;padding:16px 24px}
.burger{width:24px;height:18px;transform:rotate(0deg)}
[data-qa="header-burger"]{translate:0;rotate:0deg;scale:1;perspective:1000px;filter:blur(0);backdrop-filter:blur(0);contain:paint;container-type:inline-size;will-change:transform}
.bar{display:block;height:2px;background:currentColor}
@media(max-width:920px){
.desktop-row{display:none}
.mobile-row{display:grid;position:relative;grid-template-columns:auto max-content;grid-template-areas:"brand opener";align-items:center;height:96px}
.brand{grid-area:brand}
.burger{grid-area:opener}
.dropdown{position:absolute;top:100%;left:0;width:100%;visibility:hidden;max-height:0;overflow:hidden;display:flex;flex-direction:column;align-items:flex-end;padding:0 16px;background:#ffffff;color:#123456}
.dropdown nav>ul{text-align:end}
.dropdown a{text-align:end;font-size:16px}
}
CSS;
$failures = array();
foreach (array('absolute', 'fixed') as $position) {
    $html = '<!doctype html><html><head><style>' . str_replace('position:absolute', 'position:' . $position, $css) . '</style></head><body><header>'
        . '<div class="desktop-row"><a href="/">Brand</a><nav>' . $links . '</nav></div>'
        . '<div class="mobile-row"><a class="brand" href="/">Brand</a><button class="burger" title="Menu" aria-controls="mobile-menu" data-qa="header-burger"><span class="bar"></span><span class="bar"></span><span class="bar"></span></button>'
        . '<div class="dropdown"><nav id="mobile-menu">' . $links . '</nav></div></div></header></body></html>';
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
    if ('right' !== ($navigation['layout']['justifyContent'] ?? null)) {
        $failures[] = "$position: overlay must carry mobile end alignment in Core layout.justifyContent.";
    }
    if ('#ffffff' !== ($navigation['customOverlayBackgroundColor'] ?? null) || '#123456' !== ($navigation['customOverlayTextColor'] ?? null)) {
        $failures[] = "$position: overlay paint must use Core's overlay color attributes.";
    }
    // The native open button is a child of this host. Its host must inherit
    // the burger's named grid area, not auto-place into an empty cart track.
    $artifact = (string) json_encode($result, JSON_UNESCAPED_SLASHES);
    if (!str_contains($artifact, 'grid-area:opener!important')) {
        $failures[] = "$position: replacement navigation must carry the burger's authored grid slot.";
    }
    // Model Core's rendered host and open sheet, including the classes
    // actually emitted in block attributes (not just generated selectors).
    $host = $dom->createElement('nav');
    $host->setAttribute('class', 'wp-block-navigation ' . ($navigation['className'] ?? ''));
    $overlay = $dom->createElement('div');
    $overlay->setAttribute('class', 'wp-block-navigation__responsive-container is-menu-open');
    $opener = $dom->createElement('button');
    $opener->setAttribute('class', 'wp-block-navigation__responsive-container-open');
    $host->appendChild($opener);
    $host->appendChild($overlay);
    $content = $dom->createElement('div');
    $content->setAttribute('class', 'wp-block-navigation__responsive-container-content');
    $overlay->appendChild($content);
    $list = $dom->createElement('ul');
    $list->setAttribute('class', 'wp-block-navigation__container');
    $content->appendChild($list);
    $parent = $dom->createElement('li');
    $parent->setAttribute('class', 'wp-block-navigation-item wp-block-navigation-submenu');
    $list->appendChild($parent);
    $submenu = $dom->createElement('ul');
    $submenu->setAttribute('class', 'wp-block-navigation__submenu-container');
    $parent->appendChild($submenu);
    $item = $dom->createElement('li');
    $item->setAttribute('class', 'wp-block-navigation-item');
    $submenu->appendChild($item);
    $link = $dom->createElement('a');
    $link->setAttribute('class', 'wp-block-navigation-item__content');
    $item->appendChild($link);
    // Use retained source classes alongside the native classes, just as the
    // rendered navigation does. Include a childless top-level item's wrappers.
    $item->setAttribute('class', 'wp-block-navigation-item block-header-item');
    $link->setAttribute('class', 'wp-block-navigation-item__content item-content');
    $leaf = $dom->createElement('li');
    $leaf->setAttribute('class', 'wp-block-navigation-item block-header-item');
    $list->appendChild($leaf);
    $label = $dom->createElement('span');
    $leaf->appendChild($label);
    $wrapper = $dom->createElement('div');
    $wrapper->setAttribute('class', 'item-content-wrapper');
    $label->appendChild($wrapper);
    $leafLink = $dom->createElement('a');
    $leafLink->setAttribute('class', 'wp-block-navigation-item__content item-content');
    $wrapper->appendChild($leafLink);
    $alignmentTargets = array($item, $link, $leaf, $label, $wrapper, $leafLink);
    $alignmentReset = array();
    ($row ?? $dom->documentElement)->appendChild($host);
    $forbidden = 'transform|translate|rotate|scale|perspective|filter|backdrop-filter|contain|container-type|will-change|width|height';
    $openerSized = false;
    $gutter = false;
    $submenuAligned = false;
    $desktopCentered = false;
    $submenuUnindented = false;
    $singleGutter = false;
    foreach ($result['assets'] ?? array() as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        (new CssStylesheetTransformer())->visitStyleRules((string) ($asset['content'] ?? ''), static function (string $selector, string $body) use ($host, $overlay, $opener, $forbidden, $position, &$failures, &$openerSized, $list, $submenu, $item, $link, &$gutter, &$submenuAligned, &$desktopCentered, &$submenuUnindented, &$singleGutter, $alignmentTargets, &$alignmentReset): void {
            foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array() as $selector) {
                $parsed = CssSelectorMatcher::parse($selector);
                foreach ($alignmentTargets as $index => $target) {
                    if ((CssSelectorMatcher::matches($target, $parsed, true)['matches'] ?? false)
                        && str_contains($body, 'align-self:auto!important')
                        && str_contains($body, 'align-items:inherit!important')) {
                        $alignmentReset[$index] = true;
                    }
                }
                if ((CssSelectorMatcher::matches($overlay, $parsed, true)['matches'] ?? false) && str_contains($body, 'padding-inline:0!important')) $singleGutter = true;
                if ((CssSelectorMatcher::matches($submenu, $parsed, true)['matches'] ?? false) && str_contains($body, 'padding-inline:0!important') && str_contains($body, 'margin-inline:0!important')) $submenuUnindented = true;
                foreach (array($submenu, $item, $link) as $target) {
                    if ((CssSelectorMatcher::matches($target, $parsed, true)['matches'] ?? false)
                        && preg_match('/(?:^|;)\s*(?:text-align|align-items|justify-content)\s*:\s*center\b/', $body)) {
                        $failures[] = "$position: desktop submenu centering leaks into the open overlay: $selector";
                    }
                }
                if ((CssSelectorMatcher::matches($list, $parsed, true)['matches'] ?? false) && str_contains($body, 'padding:0 16px!important')) $gutter = true;
                if ((CssSelectorMatcher::matches($submenu, $parsed, true)['matches'] ?? false) && str_contains($body, 'align-items:flex-end!important') && str_contains($body, 'text-align:right!important')) $submenuAligned = true;
                // The same submenu still receives source centering when Core
                // closes the overlay; do not delete desktop styles.
                $overlay->setAttribute('class', 'wp-block-navigation__responsive-container');
                if ((CssSelectorMatcher::matches($submenu, $parsed, true)['matches'] ?? false) && str_contains($body, 'text-align:center')) $desktopCentered = true;
                foreach ($alignmentTargets as $target) {
                    if ((CssSelectorMatcher::matches($target, $parsed, true)['matches'] ?? false)
                        && str_contains($body, 'align-self:auto!important')
                        && str_contains($body, 'align-items:inherit!important')) {
                        $failures[] = "$position: overlay alignment reset must not replace closed/desktop item alignment.";
                    }
                }
                $overlay->setAttribute('class', 'wp-block-navigation__responsive-container is-menu-open');
                foreach (array($host, $overlay) as $target) {
                    if ((CssSelectorMatcher::matches($target, $parsed, true)['matches'] ?? false)
                        && preg_match('/(?:^|;)\s*(' . $forbidden . ')\s*:/i', $body, $match)) {
                        $failures[] = "$position: $selector must not apply {$match[1]} to the navigation host or overlay.";
                    }
                }
                if ((CssSelectorMatcher::matches($opener, $parsed, true)['matches'] ?? false)
                    && str_contains($body, 'width:24px') && str_contains($body, 'height:18px')) {
                    $openerSized = true;
                }
            }
        });
    }
    if (count($alignmentReset) !== count($alignmentTargets)) {
        $failures[] = "$position: submenu and childless top-level items, links and wrappers must reset desktop self/item alignment inside the open overlay.";
    }
    if (!str_contains($artifact, 'align-self:center')) {
        $failures[] = "$position: regression fixture must retain the conflicting desktop item alignment.";
    }
    if (!$gutter) $failures[] = "$position: the open list must receive the source panel's 16px gutter.";
    if (!$singleGutter) $failures[] = "$position: the source gutter must replace, not double Core's inline overlay padding.";
    if (!$submenuUnindented) $failures[] = "$position: submenu inline padding and margins must not indent it from its parent.";
    if (!$submenuAligned) $failures[] = "$position: submenu alignment must follow the mobile parent.";
    if (!$desktopCentered) $failures[] = "$position: desktop submenu centering must remain outside the overlay.";
    if (!$openerSized) $failures[] = "$position: Core's open button must retain the burger's visual box in its grid slot.";
    if (str_contains($markup, 'class="wp-block-group dropdown') || str_contains($markup, '<button')) {
        $failures[] = "$position: neither the collapsed panel nor the inert source button may retain the opener.";
    }
    foreach (array('/services', '/support', '/compare', '/about') as $url) {
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
