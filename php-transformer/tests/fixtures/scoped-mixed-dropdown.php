<?php
declare(strict_types=1);

$runtime = trim((string) file_get_contents(__DIR__ . '/dla-disclosure-runtime.js'));
$icon = '<svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><path d="M2 4h16M2 10h16M2 16h16" stroke="currentColor"></path></svg>';
$copy = static function (string $scope) use ($icon): string {
    return '<div class="data-liberation-' . $scope . '-document" data-dla-document-scope="">'
        . '<header><div class="menu-host"><div role="button" tabindex="0" aria-label="Open menu" data-dla-disclosure-label="Open menu" data-dla-dialog-trigger="menu-panel" aria-controls="menu-panel" aria-expanded="false" aria-haspopup="menu" data-dla-dialog-ancestor-unverified="ancestor-limit">' . $icon . '</div>'
        . '<div id="menu-panel" class="menu-panel dla-dropdown" style="display:flex;flex-direction:column" hidden data-dla-dialog-panel="menu-panel"><div>'
        . '<li><a href="#services">Services</a></li><li><a href="#gallery">Gallery</a></li><hr>'
        . '<div class="social"><a aria-label="Social" href="https://example.test/social">' . $icon . '</a></div><hr>'
        . '<span><a class="cta" href="#contact">Contact</a></span><button type="button">Ordinary action</button>'
        . '</div></div></div></header><main><h2 id="services">Services</h2><h2 id="gallery">Gallery</h2><h2 id="contact">Contact</h2></main></div>';
};
return '<!doctype html><html><head><style>body{margin:0}.menu-host{position:relative}.menu-panel{padding:12px;background:rgb(240,245,250)}.menu-panel>div{display:flex;flex-direction:column;gap:8px}.cta{padding:8px;border-radius:4px}.data-liberation-desktop-document{display:none}@media(min-width:768px){.data-liberation-mobile-document{display:none}.data-liberation-desktop-document{display:block}}</style>'
    . '<script data-dla-disclosure-runtime="true">' . $runtime . '</script></head><body>' . $copy('desktop') . $copy('mobile') . '</body></html>';
