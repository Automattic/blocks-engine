<?php
declare(strict_types=1);

// A hand-written site: element and descriptive class selectors with literal
// values, a few inline styles, and a declared webfont stack (`Lobster`) that
// only an unused class applies.
$css = 'body{margin:0;background-color:#f4f1ea;color:#2b2b2b;font-family:Georgia,"Times New Roman",serif;font-size:17px}'
    . 'h1,h2{font-family:"Trebuchet MS",Helvetica,sans-serif;color:#1d3557}h1{font-size:40px}h2{font-size:26px}'
    . 'a{color:#e63946}.fancy{font-family:Lobster,cursive}'
    . '.content{max-width:720px;margin:0 auto;padding:32px 24px}.intro{font-size:21px;color:#555555}'
    . '.box{background-color:#a8dadc;padding:24px}.small{font-size:14px;color:#555555}'
    . '.top{background-color:#1d3557;color:#f1faee;padding:16px 24px}';
$page = static function (string $title, string $intro, string $body, string $prefix): string {
    return '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="' . $prefix . 'style.css"></head><body>'
        . '<div class="top"><p>Harbour Bakery</p></div>'
        . '<div class="content"><h1>' . $title . '</h1><p class="intro">' . $intro . '</p>'
        . '<p>' . $body . '</p><p style="color:#1d3557;padding:16px">' . $body . ' Come early on Saturdays.</p>'
        . '<h2>Visit us</h2><p>Find us by the <a href="' . $prefix . 'index.html">old harbour wall</a>, open from seven.</p>'
        . '<div class="box"><p>Orders for celebrations need two days notice.</p></div>'
        . '<p class="small">Prices include tax.</p></div></body></html>';
};
return array(
    'entrypoint' => 'index.html',
    'files' => array(
        'style.css' => $css,
        'index.html' => $page('Bread worth waking for', 'Sourdough, rye and seasonal pastries baked every morning.', 'Our ovens are lit before dawn so the first loaves are ready when the doors open at seven.', ''),
        'menu/index.html' => $page('This week', 'What comes out of the oven depends on the season.', 'Expect rye and seeded loaves daily, with fruit pastries when the orchards are generous.', '../'),
        'visit/index.html' => $page('Finding the bakery', 'A short walk from the ferry, past the fish market.', 'There is a bench outside for the impatient, and coffee from the cafe next door.', '../'),
    ),
);
