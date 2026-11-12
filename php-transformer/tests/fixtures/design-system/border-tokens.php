<?php
declare(strict_types=1);

return array('entrypoint' => 'index.html', 'files' => array('index.html' => <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Border tokens</title>
<style>
:root{--brand:#3e559b;--accent:#d56741}
body{margin:0;background:#eef1f7;color:#26304d;font-family:sans-serif}
.masthead{border-top:7px solid var(--brand);border-bottom:4px solid var(--accent);padding:16px}
.masthead h1{color:var(--brand);margin:0}
</style></head><body>
<header class="masthead"><h1>Page title</h1></header><main><p>Body.</p></main></body></html>
HTML
));
