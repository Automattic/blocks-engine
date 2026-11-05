<?php
declare(strict_types=1);

// Two pages, each combining duplicated device-document ids and an ordinary id.
$pages = array();
foreach (array('Home', 'About') as $title) {
    $html = '<!doctype html><html><head><style>'
        . '@charset "UTF-8";@import url("base.css");'
        . '@font-face{font-family:Shared;src:url("shared.woff2")}'
        . 'html{font-family:Shared,sans-serif}body{margin:0}h1{font-size:2em}'
        . '@supports(display:grid){@font-face{font-family:Nested;src:url("nested.woff2")}body{display:grid}}'
        . '@supports(display:grid){@media(min-width:2px){#ordinary{padding-right:23px}}}'
        . '#panel{color:rgb(12,34,56)}'
        . '@media(min-width:1px){[id="panel"]{padding-left:17px}}'
        . '#panel #child{border-top-width:3px}'
        . '#ordinary{color:rgb(65,43,21);animation:reveal 1s both paused}'
        . '#panel #ordinary{margin-left:19px}'
        . '#panel,#ordinary{letter-spacing:2px}'
        . '@keyframes reveal{from{opacity:0}to{opacity:1}}'
        . '</style></head><body><div id="ordinary"><p>' . $title . '</p></div>';
    foreach (array('desktop', 'mobile') as $device) {
        $html .= '<div data-blocks-engine-capture-device-document="' . $device . '" data-blocks-engine-capture-document-scope="">'
            . '<section id="panel"><div id="child"><p>' . $title . ' content</p></div></section></div>';
    }
    $pages[] = $html . '<nav><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav></body></html>';
}
return $pages;
