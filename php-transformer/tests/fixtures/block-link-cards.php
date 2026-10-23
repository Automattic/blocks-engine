<?php
return <<<'HTML'
<!doctype html><html><head><style>
body{margin:0;font-family:Arial,sans-serif;line-height:1.4}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:0}
.card{display:block;padding:14px;background:#eee;color:#222;text-decoration:none}
.card__title{font-size:16px;margin:0}
@media (max-width:600px){.card__title{font-size:14px}}
</style></head><body><div class="grid">
<a class="card" href="https://example.com/1" target="_blank" rel="noopener"><div><h4 class="card__title">Name</h4><div><svg width="24" height="24"></svg></div></div></a>
<a class="card" href="https://example.com/2"><div><h4 class="card__title">Name</h4><div><svg width="24" height="24"></svg></div></div></a>
<a class="card" href="https://example.com/3"><div><h4 class="card__title">Name</h4><div><svg width="24" height="24"></svg></div></div></a>
</div></body></html>
HTML;
