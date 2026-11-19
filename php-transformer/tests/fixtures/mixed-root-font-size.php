<?php
declare(strict_types=1);

// Two documents that render one 15px text size under different roots: the
// entry page keeps the 16px initial root, the second page sets a 10px root.
return array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Home</title>
<style>.home{font-size:15px;line-height:1.65}</style></head>
<body><main class="home"><h1>Home</h1><p>Plain paragraph text on a page with the default root.</p><p><a href="/about/">About</a></p></main></body></html>
HTML,
    'about/index.html' => <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>About</title>
<style>html{font-size:10px}.about{font-size:1.5rem;line-height:1.65}</style></head>
<body><main class="about"><h1>About</h1><p>Paragraph text on a page whose root is ten pixels.</p><p><a href="/">Home</a></p></main></body></html>
HTML,
));
