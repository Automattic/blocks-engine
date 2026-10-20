<?php
// Neutral reductions from #3138 and its scroller follow-up.
$items = '';
foreach (array('NEWS ONE', 'BUSINESS TWO', 'ENTERTAINMENT THREE', 'SCIENCE FOUR', 'HEALTH FIVE', 'SPORT SIX', 'SHOP SEVEN') as $i => $label) {
    $items .= '<li><a href="/section-' . $i . '">' . $label . '</a></li>';
}
$scrollItems = '';
for ($i = 1; $i <= 20; ++$i) $scrollItems .= '<li><a href="/' . $i . '">Section number ' . $i . '</a></li>';
return array(
    'hidden' => '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Nav hide</title><style>
.site-header .header-top .main-menu > ul{display:none;gap:24px;list-style:none;margin:0;padding:0}
@media (min-width:1279px){.site-header .header-top .main-menu > ul{display:flex}}
.main-menu a{white-space:nowrap;font-weight:700}
</style></head><body><header class="site-header"><div class="header-top"><div class="menu main-menu"><ul>' . $items . '</ul></div></div></header><main><h1>Home</h1><p>Body text.</p></main></body></html>',
    'scroller' => '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Nav scroller</title><style>.strip > ul{display:flex;overflow-x:scroll;gap:24px;list-style:none;margin:0;padding:0}.strip a{white-space:nowrap}</style></head><body><nav class="strip"><ul>' . $scrollItems . '</ul></nav></body></html>',
);
