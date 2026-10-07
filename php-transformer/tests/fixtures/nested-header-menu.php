<?php
declare(strict_types=1);

$icon = '<svg width="20" height="20" viewBox="0 0 20 20"><path d="M2 3h16v2H2zM2 9h16v2H2zM2 15h16v2H2z" fill="currentColor" /></svg>';
$items = static fn(string $suffix): string => '<li><a href="/index.html#services' . $suffix . '">Services</a></li>'
    . '<li><a href="/index.html#gallery' . $suffix . '">Gallery</a></li>'
    . '<li><div class="social"><a href="https://instagram.com/example">' . $icon . '</a></div></li>'
    . '<li><a href="/index.html#contact' . $suffix . '">Contact</a></li>';
$header = static function (string $suffix, bool $phone) use ($items, $icon): string {
    $panel = $phone ? '<div class="toggle-slot"><div role="button" tabindex="0" aria-label="Open menu" aria-controls="phone-menu" aria-haspopup="menu" aria-expanded="false" data-dla-dialog-trigger="phone-menu">' . $icon . '</div>'
        . '<div id="phone-menu" hidden data-dla-dialog-panel="phone-menu"><div class="panel-items">' . $items($suffix) . '</div></div></div>' : '';
    return '<header><nav><div class="header-row"><a href="/index.html"><h3>Northwind</h3></a>'
        . '<div class="menu-slot"><div class="' . ($phone ? 'offscreen-links' : 'desktop-links') . '"><ul class="menu">' . $items($suffix) . '</ul></div>' . $panel . '</div></div></nav></header>';
};
$sections = static function (string $suffix): string {
    $html = '<main>';
    foreach (array('services' => 'Services', 'gallery' => 'Gallery', 'contact' => 'Contact') as $id => $title) {
        $html .= '<section id="' . $id . $suffix . '"><h2>' . $title . '</h2><p>Editable section content.</p></section>';
    }
    return $html . '</main>';
};
return '<!doctype html><html><head><title>Nested header menu</title><style>'
    . 'body{margin:0;font:16px/1.5 sans-serif}header{padding:16px;background:white}.header-row,.menu-slot,.menu{display:flex;align-items:center;gap:16px}.header-row{justify-content:space-between}.panel-items{display:flex;flex-direction:column;gap:16px;padding:24px}h3{margin:0}.menu{list-style:none;margin:0;padding:0}a{color:#163c77}a[href^="https://instagram"]{transform:scale(1.2)}section{min-height:900px;padding:24px}.offscreen-links{position:absolute;left:-10000px}.site-document-variant-phone{display:none!important}'
    . '@media(max-width:768px){.site-document-variant-desktop{display:none!important}.site-document-variant-phone{display:contents!important}}'
    . '</style></head><body><div class="site-document-variant-desktop" data-dla-document-scope>' . $header('', false) . $sections('') . '</div>'
    . '<div class="site-document-variant-phone" data-dla-document-scope>' . $header('--phone', true) . $sections('--phone') . '</div></body></html>';
