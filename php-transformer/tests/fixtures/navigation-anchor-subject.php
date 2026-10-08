<?php
declare(strict_types=1);

return <<<'HTML'
<!doctype html><html><head><title>Anchor subject proof</title><style>
*{box-sizing:border-box}body{margin:0;font:16px/24px Arial;color:#17212b}
header{padding:12px;background:#fafafa}.menu-list{display:flex;align-items:center;list-style:none;margin:0;padding:0;gap:0}
.menu-item{display:flex;align-items:center;align-self:center;padding:3px 5px;margin-left:7px;border-left:2px solid #607080;border-radius:2px;background:#eef1f4}
.menu-item:first-child{margin-left:0;border-left:0}
.menu-link{display:block;padding:4px 6px;color:#17212b;text-decoration:none;border-radius:3px;line-height:24px}
.cta{padding:7px 11px;background:#294dd0;color:white;border-radius:19px;font-size:15px;line-height:22px;z-index:2}
.cta:hover{background:#b43c20;color:#fff4cc;border-radius:9px;z-index:6}
.cta:focus{color:#ffe080}
.cta:active{background:#803018;border-radius:4px}
.icon{display:flex;padding:0;transform:scale(1.2);margin:0 4px;color:#17212b}
.icon svg{display:block;width:20px;height:20px}
.icon-frame{padding:6px 9px;border-radius:13px;background:#fff6d0}.icon-group{display:flex;align-items:center}
@media(min-width:600px){.cta{padding:9px 17px;border-radius:27px;font-size:17px;background:#126050;z-index:4}.menu-item{padding:5px 7px;font-size:14px}.icon{transform:scale(1.4)}}
@media(min-width:1000px){.cta{padding:11px 23px;border-radius:33px;background:#493087}.menu-item{padding:7px 9px}}
</style></head><body><header><nav aria-label="Main"><ul class="menu-list">
<li class="menu-item"><a class="menu-link" href="#services">Services</a></li>
<li class="menu-item"><a class="menu-link cta" href="#contact">Contact</a></li>
<li class="menu-item" style="margin-left:8px"><a class="menu-link cta" style="padding:2px 4px;border-radius:5px;color:#ffffff;z-index:7" href="#inline">Inline</a></li>
<li class="menu-item"><div class="icon-frame"><div class="icon-group"><a class="icon" href="https://example.test/social" aria-label="Social"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M2 2h16v16H2zM6 6v8h8V6z" fill-rule="evenodd"/></svg></a></div></div></li>
</ul></nav></header><main><h1 id="services">Services</h1><p>Editable section content.</p><h2 id="contact">Contact</h2><h2 id="inline">Inline</h2></main></body></html>
HTML;
