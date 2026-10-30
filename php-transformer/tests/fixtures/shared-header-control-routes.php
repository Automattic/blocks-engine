<?php
declare(strict_types=1);

return static function (bool $snapshot = true, bool $active = true): array {
    $files = array('style.css' => '.brand{display:flex;align-items:center;gap:11px;color:#fff;text-decoration:none}.btn{display:inline-flex;align-items:center;padding:12px 18px;background:#e2622b;color:#fff;border-radius:8px}.wrap{display:flex;justify-content:space-between;align-items:center}@media (max-width:980px){.hero-cta{display:none}}.menu-toggle{display:none}@media (max-width:980px){.menu-toggle{display:block}nav{display:none}}');
    foreach (array('index', 'about') as $slug) {
        $links = '';
        foreach (array('index' => 'Home', 'about' => 'About') as $target => $label) {
            $links .= '<a href="/' . $target . '.html"' . ($active && $target === $slug ? ' class="on"' : '') . '>' . $label . '</a>';
        }
        $state = $snapshot ? ' data-blocks-engine-capture-dialog-panel-state="' . htmlspecialchars(json_encode(array('html' => $links, 'style' => 'display: flex ; visibility: visible !important;', 'class' => 'open data-blocks-engine-capture-dropdown')), ENT_QUOTES) . '"' : '';
        $files[$slug . '.html'] = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $slug . '</title><link rel="stylesheet" href="style.css"></head><body><header><div class="wrap"><a class="brand" href="/index.html"><img src="logo.svg" alt="" width="40" height="40"><span>Sample Brand</span></a><nav id="primaryNav" class="data-blocks-engine-capture-dialog">' . $links . '</nav><a class="btn hero-cta" href="/index.html#quote">Free Estimate</a><button class="menu-toggle" id="menuToggle" aria-label="Open menu" aria-controls="primaryNav" aria-expanded="false" data-blocks-engine-capture-dialog-ancestor-state="[]"' . $state . '>Menu</button></div></header><main><h1>' . $slug . ' page</h1><p>Body copy for ' . $slug . '.</p></main></body></html>';
    }
    return array('entrypoint' => 'index.html', 'files' => $files);
};
