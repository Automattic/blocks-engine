<?php
declare(strict_types=1);

// A responsive capture with mixed intrinsic image shapes, conditional crop
// holders, important root margins, and both navigation topology contracts.
$frames = '';
for ($index = 0; $index < 20; ++$index) {
    $height = 8 === $index ? 1600 : 720;
    $svg = rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="960" height="' . $height . '"><rect width="960" height="' . $height . '" fill="hsl(' . $index * 17 . ',60%,50%)"/></svg>');
    $frames .= '<div class="frame-item' . (7 === $index ? ' active' : '') . '"><div class="image-container"><div class="frame-crop"><img class="frame-photo" src="data:image/svg+xml,' . $svg . '" alt="Frame ' . $index . '"></div></div></div>';
}
$arrows = '';
foreach (array('Previous', 'Next') as $label) {
    $arrows .= '<div role="button" tabindex="0" aria-label="' . $label . ' image" class="frame-arrow"><svg viewBox="0 0 24 24"><path d="' . ('Next' === $label ? 'M8 4l8 8-8 8' : 'M16 4l-8 8 8 8') . '" fill="none" stroke="currentColor"/></svg></div>';
}
$html = '';
foreach (array('wide', 'pocket') as $variant) {
    $html .= '<section class="' . $variant . '-only photo-gallery"><h2>Gallery heading survives</h2><div class="frame-carousel" data-frame-scope="' . $variant . '" aria-label="' . ucfirst($variant) . ' image carousel"><div class="frame-window">' . $frames . '</div><div class="frame-actions">' . $arrows . ('pocket' === $variant ? '<span class="frame-counter">8 / 20</span>' : '') . '</div></div></section>';
}
$crop = '.frame-crop{position:relative;padding-bottom:75%;overflow:hidden;border-radius:24px}.frame-crop img{position:absolute;top:0;left:0;transform-origin:left top}';
$css = '.frame-carousel{position:relative;width:100%;margin-left:auto!important;margin-right:auto!important}.frame-window{position:relative;overflow:hidden}'
    . 'figure{margin:0 0 1rem}'
    . '.frame-item{display:none;width:100%}.frame-item.active{display:block}.image-container{width:100%;aspect-ratio:4/3;margin-inline:auto}.frame-photo{width:100%;height:auto}'
    . '.frame-actions{position:absolute;inset:0;display:flex;align-items:center;justify-content:space-between;pointer-events:none}.frame-arrow{width:40px;height:40px;padding:0;border:0;background:transparent;color:#123;pointer-events:auto;cursor:pointer}.frame-arrow svg{width:100%;height:100%}'
    . '.frame-carousel[data-frame-scope] .frame-arrow{color:#123}'
    . '@media(max-width:991px){.wide-only{display:none}.pocket-only{display:block}' . $crop . '}'
    . '@media(min-width:992px){.wide-only{display:block}.pocket-only{display:none}.image-container{width:66.666667%}' . $crop . '}';
return array('html' => $html, 'css' => $css, 'carousels' => 2, 'images' => 40);
