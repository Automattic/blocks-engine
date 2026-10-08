<?php
declare(strict_types=1);

$html = require __DIR__ . '/navigation-opener-presentation.php';
$html = str_replace('</style>', 'h3{font-size:16px;line-height:24px}.header-row{min-height:48px}ul{margin-top:0;margin-bottom:13px}@media(min-width:600px){ul{margin-bottom:23px}}.placement-region{overflow:hidden;height:80px}.placement-peer{float:left}.placement-region ul#placed-menu{display:flex;flex-wrap:nowrap;align-items:center;gap:12px;float:right;width:160px;position:relative;top:9px;padding:0;margin-left:0;margin-right:0;list-style:none;font-size:16px;line-height:24px}</style>', $html);
return str_replace('</body>', '<div class="placement-region"><span class="placement-peer">Peer</span><ul id="placed-menu"><li><a href="#services">A</a></li><li><a href="#gallery">B</a></li><li><a href="#contact">C</a></li></ul></div></body>', $html);
