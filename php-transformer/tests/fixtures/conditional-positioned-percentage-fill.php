<?php
declare(strict_types=1);

$base = '.surface{position:relative;width:100%}.copy{height:180px;margin:0}.paint{background-image:linear-gradient(red,blue);width:100%}';
$html = '<section class="surface"><div class="wrapper"><div class="paint"></div></div><p class="copy">Neutral content</p></section>';
$mobile = static fn (string $rules): string => '@media (max-width:800px){' . $rules . '}';
$desktop = static fn (string $rules): string => '@media (min-width:801px){' . $rules . '}';
return array(
    array('name' => 'mobile absolute fill', 'css' => $base . $mobile('.wrapper{position:absolute;inset:0}.paint{height:100%}'), 'html' => $html, 'preserve' => true),
    array('name' => 'desktop absolute fill', 'css' => $base . $desktop('.wrapper{position:absolute;inset:0}.paint{height:100%}'), 'html' => $html, 'preserve' => true),
    array('name' => 'layered feature fill', 'css' => $base . '@layer boxes{@supports (display:grid){' . $mobile('.wrapper{position:absolute;inset:0}.paint{height:100%}') . '}}', 'html' => $html, 'preserve' => true),
    array('name' => 'own conditional position', 'css' => $base . $mobile('.paint{position:absolute;inset:0}') . $mobile('.paint{height:100%}'), 'html' => $html, 'preserve' => true),
    array('name' => 'indefinite static parent', 'css' => $base . $mobile('.paint{height:100%}'), 'html' => $html, 'preserve' => false),
    array('name' => 'different condition domain', 'css' => $base . $desktop('.wrapper{position:absolute;inset:0}') . $mobile('.paint{height:100%}'), 'html' => $html, 'preserve' => false),
    array('name' => 'conditional static wins', 'css' => $base . '.wrapper{position:absolute;inset:0}' . $mobile('.wrapper{position:static}.paint{height:100%}'), 'html' => $html, 'preserve' => false),
    array('name' => 'inline static wins', 'css' => $base . $mobile('.wrapper{position:absolute;inset:0}.paint{height:100%}'), 'html' => str_replace('class="wrapper"', 'class="wrapper" style="position:static"', $html), 'preserve' => false),
    array('name' => 'inherit from relative parent', 'css' => $base . $mobile('.wrapper{position:inherit}.paint{height:100%}'), 'html' => $html, 'preserve' => false),
    array('name' => 'important author positioning wins inline static', 'css' => $base . $mobile('.wrapper{position:absolute!important;inset:0}.paint{height:100%}'), 'html' => str_replace('class="wrapper"', 'class="wrapper" style="position:static"', $html), 'preserve' => true),
    array('name' => 'own rule loses to inline static', 'css' => $base . $mobile('.paint{position:absolute;inset:0;height:100%}'), 'html' => str_replace('class="paint"', 'class="paint" style="position:static"', $html), 'preserve' => false),
    array('name' => 'inline important positioning wins author important', 'css' => $base . $mobile('.wrapper{position:absolute!important;inset:0}.paint{height:100%}'), 'html' => str_replace('class="wrapper"', 'class="wrapper" style="position:static!important"', $html), 'preserve' => false),
);
