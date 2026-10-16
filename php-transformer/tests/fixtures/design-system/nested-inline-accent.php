<?php
declare(strict_types=1);

// A dark page whose eyebrow labels are muted spans with letter spacing, each
// wrapping one highlighted word: an authored `<mark>` coloured by a
// Tailwind arbitrary-value class and the same colour inline
// (`color:var(--cyan)`). The conversion carries each span as a marker
// `<mark>`, so the word sits in two marks. A third label's word reads a
// custom property only a `.promo` scope defines, which no token can stand
// for, and a paragraph colours one word with an inline span.
$css = ':root{--cyan:#35c9f0;--ink:#050b18;--fg:#e9f1ff}'
    . 'html{font-family:Inter,sans-serif}body{margin:0;background:var(--ink);color:var(--fg)}'
    . '.text-\[color\:var\(--cyan\)\]{color:var(--cyan)}.text-\[\#93a8ce\]{color:#93a8ce}.tracking-\[\.2em\]{letter-spacing:.2em}'
    . '.promo{--glow:#ff00aa}.btn{background:var(--cyan);color:var(--ink);padding:8px 16px}';
$eyebrow = static fn(string $word, string $token = 'cyan'): string => '<div><span class="tracking-[.2em] text-[#93a8ce]"><mark class="text-[color:var(--' . $token . ')]" style="background:transparent;color:var(--' . $token . ')">' . $word . '</mark></span></div>';

return array(
    'entrypoint' => 'index.html',
    'files' => array(
        'assets/site.css' => $css,
        'index.html' => '<!doctype html><html><head><title>Studio</title><link rel="stylesheet" href="assets/site.css"></head><body><main>'
            . $eyebrow('Studio') . '<h1>Made by people who care</h1>'
            . '<p class="text-[#93a8ce]">We design and build calm, fast websites for small teams, and we answer every message ourselves.</p>'
            . $eyebrow('Process') . '<h2>How we work</h2>'
            . '<p class="text-[#93a8ce]">Discovery, a written plan, weekly reviews and a launch you can <span style="color:#ff00aa">rehearse</span> before it happens.</p>'
            . $eyebrow('Soon', 'glow')
            . '<p><a class="btn" href="index.html">Start a project</a></p>'
            . '</main></body></html>',
    ),
);
