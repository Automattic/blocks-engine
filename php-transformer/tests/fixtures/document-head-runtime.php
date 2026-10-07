<?php
declare(strict_types=1);

return array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1" data-selected-viewport=""><script data-parser-selector="">document.querySelector("meta[data-selected-viewport]").setAttribute("content", "width=320, user-scalable=yes");window.firstHeadState={bodyAbsent:document.body===null,styleAbsent:document.querySelector("style[data-document-scope]")===null};</script><style data-document-scope="phone" media="not all">.phone{color:red;background-image:url(icon.svg)}</style><style data-document-scope="tablet" media="not all">.tablet{color:blue}</style><link rel="stylesheet" href="screen.css" media="not all" data-source-media="screen"><script src="check.js"></script></head><body><main><h1>Neutral head runtime</h1><p class="phone">Phone</p></main></body></html>',
    'screen.css' => '.phone{margin:8px}',
    'check.js' => 'window.viewportAtSecondScript=document.querySelector("meta[data-selected-viewport]").content;window.secondHeadState={bodyAbsent:document.body===null,stylePresent:!!document.querySelector("style[data-document-scope]"),linkPresent:!!document.querySelector("link[data-source-media]")};',
    'icon.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0h1v1H0z"/></svg>',
));
