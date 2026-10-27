<?php
declare(strict_types=1);

// Two inert device sets must also retain markers on page-scoped slices.
$devices = '<link rel="stylesheet" href="desktop.css" media="not all" data-blocks-engine-capture-device-style="desktop wide" data-blocks-engine-capture-source-media="all"><link rel="stylesheet" href="mobile.css" media="not all" data-blocks-engine-capture-device-style="mobile" data-blocks-engine-capture-source-media="all">';
// Conflicting alternate values must remain switchable, without becoming defaults.
return array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="base.css"><link rel="stylesheet" href="dark.css" title="dark"><link rel="alternate stylesheet" href="light.css" title="light" media="all" disabled="false"><link rel="stylesheet" href="other.css" title="other"><link rel="stylesheet" href="print.css" media="print">' . $devices . '</head><body><main><h1>Neutral title</h1><p>Neutral content</p></main></body></html>',
    'second.html' => '<!doctype html><html><head><link rel="stylesheet" href="base.css">' . $devices . '</head><body><main><h1>Second route</h1><p>Independent content</p><button>Neutral action</button></main></body></html>',
    'base.css' => 'body{margin:0}main{padding:20px}h1{margin:0}p{margin:0}',
    'dark.css' => ':root{--type:Times}body{background:#56575d;font-family:var(--type)}h1{font-weight:700}',
    'light.css' => ':root{--type:Georgia}body{background:white;font-family:var(--type)}h1{font-weight:300}',
    'other.css' => 'body{background:red}',
    'print.css' => 'body{background:lime}',
    // @namespace keeps differing page projections as slices, not scoped selectors.
    'desktop.css' => '@namespace svg url("urn:fixture:svg");button{height:auto!important;padding:12px;color:red}',
    'mobile.css' => '@namespace svg url("urn:fixture:svg");button{height:auto!important;padding:8px;color:blue}',
));
