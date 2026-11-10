<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Public-safe reduction of webehappylife.com, issue #4726. Keep both header
// variants, both unbound burgers, the hidden inline mobile copy and the
// separate visibility-hidden overlay (not a display:none disclosure).
$links = '<div><a href="/one">One</a></div><div><a href="/two">Two</a></div><div><a href="/three">Three</a></div>';
$burger = '<div class="header-burger"><button class="header-burger-btn burger"><span hidden class="js-header-burger-open-title visually-hidden">Open Menu</span><span hidden class="js-header-burger-close-title visually-hidden">Close Menu</span><div class="burger-box"><div class="burger-inner"><div class="top-bun"></div><div class="patty"></div><div class="bottom-bun"></div></div></div></button></div>';
$inline = '<div class="header-nav"><div class="header-nav-wrapper"><nav class="header-nav-list">' . $links . '</nav></div></div>';
$html = '<!doctype html><html><head><link rel="stylesheet" href="header.css"></head><body><header id="header"><div class="header-inner">'
    . '<div class="header-display-desktop">' . $burger . '<a href="/">Brand</a>' . $inline . '</div>'
    . '<div class="header-display-mobile"><a href="/">Brand</a>' . $inline . $burger . '</div></div>'
    . '<div class="header-menu"><div class="header-menu-bg"></div><div class="header-menu-nav"><nav class="header-menu-nav-list"><div class="header-menu-nav-folder"><div class="header-menu-nav-folder-content"><div class="header-menu-nav-wrapper">' . $links . '</div></div></div></nav></div></div>'
    . '</header><main><h1>Page</h1></main></body></html>';
$css = <<<'CSS'
.visually-hidden{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}
.header-display-desktop{display:flex}
.header-display-mobile{display:none}
.header-burger{display:none;align-items:center}
.burger{position:relative;background:transparent;border:0;padding:0;width:35px;height:35px}
.burger-box{position:relative;width:35px;height:35px}
.top-bun,.bottom-bun{position:absolute;display:block;width:100%;height:1px;background:currentColor}
.top-bun{top:12px}.bottom-bun{bottom:12px}.patty{display:none}
.header-menu{position:fixed;display:flex;top:0;right:0;bottom:0;left:0;visibility:hidden;opacity:0}
@media screen and (max-width:799px){
.header-display-desktop{display:none}
.header-display-mobile{display:flex;align-items:center;justify-content:space-between}
.header-nav{display:none}
.header-burger{display:flex}
.header--menu-open .header-menu{visibility:visible;opacity:1}
}
CSS;
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $html),
    array('path' => 'header.css', 'content' => $css),
)))->toArray();
$markup = $result['serialized_blocks'];
$dom = new DOMDocument();
@$dom->loadHTML($markup);
$xpath = new DOMXPath($dom);
$mobile = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," header-display-mobile ")]')->item(0);
$failures = array();
$projected = array();
if ($mobile instanceof DOMElement) {
    foreach ($mobile->getElementsByTagName('*') as $slot) {
        if (!str_contains($slot->getAttribute('class'), 'header-burger')) continue;
        foreach ($slot->childNodes as $child) {
            if ($child instanceof DOMComment && preg_match('/^ wp:navigation (\{.*\}) /s', $child->data, $match)) {
                $projected[] = json_decode($match[1], true);
            }
        }
    }
}
if (1 !== count($projected) || 'mobile' !== ($projected[0]['overlayMenu'] ?? '')) {
    $failures[] = 'Exactly one native mobile navigation must occupy the mobile burger slot.';
}
$styles = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '') && !str_contains($asset['path'], 'editor-static')), 'content'));
$toggleClass = '';
if (preg_match('/blocks-engine-native-navigation-toggle-[a-f0-9]+/', $projected[0]['className'] ?? '', $match)) {
    $toggleClass = $match[0];
}
if ('' === $toggleClass || !str_contains($styles, '.' . $toggleClass . '>.wp-block-navigation__responsive-container-open')
    || !str_contains($styles, '@media(min-width:600px) and (max-width:799px)')) {
    $failures[] = 'The existing collapse-boundary rule must expose the opener at 768, not just below Core\'s 600px switch.';
}
if ('' === $toggleClass || !str_contains($styles, '@media(max-width:799px)')
    || !str_contains($styles, '@media(min-width:800px)')) {
    $failures[] = 'Use the source collapse/absent-above boundaries for the mobile-only occurrence.';
}
if (str_contains($markup, '<button') || str_contains($markup, 'class="wp-block-group header-menu ')) {
    $failures[] = 'Neither inert burgers nor the hidden source overlay may survive.';
}
foreach (array('/one', '/two', '/three') as $url) {
    if (2 !== substr_count($markup, '"url":"' . $url . '"')) $failures[] = 'Keep desktop and projected overlay links: ' . $url;
}
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit(array() === $failures ? 0 : 1);
