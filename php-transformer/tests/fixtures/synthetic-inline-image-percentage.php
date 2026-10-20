<?php
declare(strict_types=1);

$css = 'body{margin:0}.column{width:600px}img{max-width:100%}';
return array(
    array(
        'name' => 'clamped percentage width',
        'css' => $css,
        'html' => '<div class="column"><span><img width="1160" style="width:1160%;height:100%" src="square.png"></span></div>',
        'size' => 600,
    ),
    array(
        'name' => 'aligned clamped percentage width',
        'css' => $css . '.column{text-align:center}',
        'html' => '<div class="column"><span><img width="1160" style="width:1160%;height:100%" src="square.png"></span></div>',
        'size' => 600,
    ),
    array(
        'name' => 'percentage max-width',
        'css' => $css . 'img{max-width:50%}',
        'html' => '<div class="column"><span><img width="1160" src="square.png"></span></div>',
        'size' => 300,
    ),
    array(
        'name' => 'intrinsic width attribute',
        'css' => $css . '.column{text-align:right}',
        'html' => '<div class="column"><span><img width="80" src="square.png"></span></div>',
        'size' => 80,
    ),
    array(
        'name' => 'natural image next to text',
        'css' => $css . '.column{text-align:center}',
        'html' => '<div class="column"><span>Before <img src="square.png"> after</span></div>',
        'size' => 120,
    ),
    array(
        'name' => 'flex fill image',
        'css' => $css . '.column{display:flex}img{width:100%;height:100%}',
        'html' => '<div class="column"><img src="square.png"></div>',
        'size' => 600,
    ),
);
