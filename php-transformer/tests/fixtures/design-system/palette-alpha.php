<?php
// Reduced palette tint regression, https://github.com/Automattic/blocks-engine/issues/3941.
return array('entrypoint' => 'index.html', 'files' => array('index.html' => <<<'HTML'
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Reduced</title>
<style>
:root{--bg:204 18% 95%;--fg:210 6% 11%}
body{margin:0;background:hsl(var(--bg));color:hsl(var(--fg))}
.dark{background:hsl(var(--fg));color:hsl(var(--bg));padding:40px}
.text-bg-70{color:hsl(var(--bg)/.7)}
</style></head><body>
<main><section class="dark"><h2>Heading</h2><p class="text-bg-70">Muted body copy on dark.</p></section></main></body></html>
HTML
));
