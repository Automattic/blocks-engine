<?php
declare(strict_types=1);

// Two documents that render one 15px text size under different roots: the
// entry page keeps the 16px initial root, the second page sets a 10px root.
// Each page also sets a rem font size and a 15px box padding (`1.5rem` on the
// 10px page), so a value is only bound right when it is measured at its own
// page's root.
return array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Home</title>
<style>.home{font-size:15px;line-height:1.65}.lead{font-size:1.5rem}</style></head>
<body><main class="home"><h1>Home</h1><p>Plain paragraph text on a page with the default root.</p><p class="lead">A lead paragraph set in root units.</p><p style="font-size:1.5rem">An inline paragraph set in root units.</p><div style="padding:15px"><p>Boxed text.</p></div><p><a href="/about/">About</a></p></main></body></html>
HTML,
    'about/index.html' => <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>About</title>
<style>html{font-size:10px}.about{font-size:1.5rem;line-height:1.65}</style></head>
<body><main class="about"><h1>About</h1><p>Paragraph text on a page whose root is ten pixels.</p><div style="padding:1.5rem"><p>Boxed text.</p></div><div style="padding:1.5rem"><p>Boxed again.</p></div><p><a href="/">Home</a></p></main></body></html>
HTML,
));
