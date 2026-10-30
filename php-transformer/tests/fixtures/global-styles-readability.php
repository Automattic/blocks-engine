<?php
declare(strict_types=1);

return array('entrypoint' => 'index.html', 'files' => array('index.html' => <<<'HTML'
<!doctype html><html><head><title>Readable sections</title><style>
:root{font-size:10px;--paper:#ffffff;--ink:#111111}
@media(prefers-color-scheme:dark){:root{--paper:#111111;--ink:#ffffff}}
body{font:16px/1.5 Arial,sans-serif;color:var(--ink);background-color:var(--paper)}
a,label,input,button,summary{color:var(--ink)}
input,button{background-color:var(--paper);font:inherit}
button{background-color:transparent}
.dark{color:#ffffff;background-color:#111111;padding:24px}
.dark button{background-color:#ffffff;color:#111111}
</style></head><body>
<nav><a href="#faq">Questions</a></nav>
<p>Readable body text at sixteen pixels.</p>
<section class="dark"><h1>Section heading</h1><h2>Step title</h2>
<form><label>Name <input name="name" value="Example"></label><button type="submit">Submit</button><button type="button">Attach file</button></form></section>
<section id="faq"><details open><summary>Frequently asked question</summary><p>Readable answer.</p></details><form><label>Message <input name="message" value="Hello"></label><button type="submit">Send</button></form></section>
</body></html>
HTML
));
