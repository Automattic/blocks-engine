<?php
declare(strict_types=1);

$base = '.owner{width:100%;height:180px}.fill{transform:translateX(0)}.paint{display:block;width:100%;height:100%;border:0}';
$html = '<section class="owner"><div class="fill" style="position:absolute;inset:0"><div style="position:absolute;left:0;top:0;width:100%;height:100%"><iframe class="paint" src="https://example.test/map" width="640" height="180"></iframe></div></div></section>';
return array(
    array('name' => 'conditional containing block', 'css' => $base . '@media(min-width:1px){.owner{position:relative}}', 'html' => $html, 'active' => array(390, 768, 1440)),
    array('name' => 'desktop containing block', 'css' => $base . '@media(min-width:801px){.owner{position:relative}}', 'html' => $html, 'active' => array(1440)),
    array('name' => 'conditional static winner', 'css' => $base . '@media(min-width:1px){.owner{position:relative}}@media(min-width:1100px){.owner{position:static}}', 'html' => $html, 'active' => array(390, 768)),
    array('name' => 'nested conditions', 'css' => $base . '@supports(display:grid){@media(min-width:1px){.owner{position:relative}}}', 'html' => $html, 'active' => array(390, 768, 1440)),
    array('name' => 'specificity beats later static', 'css' => $base . '@media(min-width:1px){#owner{position:relative}}@media(min-width:1100px){.owner{position:static}}', 'html' => str_replace('class="owner"', 'id="owner" class="owner"', $html), 'active' => array(390, 768, 1440)),
    array('name' => 'important static containing block', 'css' => $base . '.owner{position:static!important}@media(min-width:1px){.owner{position:relative}}', 'html' => $html, 'active' => array()),
    array('name' => 'important child position wins', 'css' => $base . '.fill{position:static!important}@media(min-width:1px){.owner{position:relative}}', 'html' => $html, 'active' => array()),
);
