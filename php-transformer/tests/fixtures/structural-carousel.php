<?php
declare(strict_types=1);

// Neutral structural fixture: no builder-specific identities or runtime script.
return static function (string $itemClass = 'frame-item', string $controlRole = 'button', int $count = 20): array {
    $items = '';
    for ($index = 0; $index < $count; ++$index) {
        $svg = rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="960" height="720"><rect width="960" height="720" fill="hsl(' . $index * 17 . ',60%,50%)"/></svg>');
        $url = 'data:image/svg+xml,' . $svg;
        $items .= '<div class="' . $itemClass . (7 === $index ? ' active' : '') . '"><div class="image-container"><div class="image-ratio"><img class="frame-photo" src="' . $url . '" srcset="' . $url . ' 960w" sizes="100vw" alt="Frame ' . $index . '"></div></div></div>';
    }
    $controls = '<div class="frame-actions">';
    foreach (array('Previous', 'Next') as $label) {
        $controls .= '<div role="' . $controlRole . '" tabindex="0" aria-label="' . $label . ' image" class="frame-arrow"><svg viewBox="0 0 24 24"><path d="' . ('Next' === $label ? 'M8 4l8 8-8 8' : 'M16 4l-8 8 8 8') . '" fill="none" stroke="currentColor"/></svg></div>';
    }
    $controls .= '</div>';
    $css = '.frame-carousel{position:relative;width:100%}.frame-window{position:relative;overflow:hidden}'
        . '.frame-item{display:none;width:100%}.frame-item.active{display:block}'
        . '.image-container{width:100%;margin-inline:auto}.image-ratio{position:relative;aspect-ratio:4/3}'
        . '.frame-photo{display:block;position:absolute;inset:0;width:100%;height:100%;object-fit:cover}'
        . '@media(min-width:992px){.image-container{width:66.666667%}}'
        . '.frame-actions{position:absolute;inset:0;display:flex;align-items:center;justify-content:space-between;pointer-events:none}'
        . '.frame-arrow{width:40px;height:40px;padding:0;border:0;background:transparent;color:#123;pointer-events:auto;cursor:pointer}.frame-arrow svg{width:100%;height:100%}';
    $html = '<section class="photo-gallery"><h2>Gallery heading survives</h2><div class="frame-carousel" aria-label="Image carousel"><div class="frame-window">' . $items . '</div>' . $controls . '</div><p>Following gallery copy survives.</p></section>';
    return array('html' => $html, 'css' => $css);
};
