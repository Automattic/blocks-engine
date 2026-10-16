<?php
declare(strict_types=1);

// A product page drawn in six greys, each written two ways a designer
// cannot tell apart (`#1a1a1a` beside `#212121`, ΔE 3.5) and both used on
// long paragraphs, plus blue and green call-to-action buttons and an amber
// and a rose heading. The brand accent is a cyan custom property
// (`--cyan`) that only marks small things: forty-six bullet dots and two
// eyebrow labels. It covers little area but is the colour a reader meets
// most often.
$copy = 'Every plan includes unlimited projects, shared workspaces, version history and priority support, with the same tools for a team of two or two hundred. ';
$css = ':root{--cyan:#22d3ee;--ink:#1a1a1a}'
    . 'html{font-family:Inter,sans-serif}body{margin:0;background:#ffffff;color:var(--ink)}'
    . '.g1{color:#1a1a1a}.g1b{color:#212121}.g2{color:#3a3a3a}.g2b{color:#424242}.g3{color:#5c5c5c}.g3b{color:#646464}'
    . '.g4{color:#7d7d7d}.g4b{color:#858585}.g5{color:#9e9e9e}.g5b{color:#a6a6a6}.g6{color:#c4c4c4}.g6b{color:#cbcbcb}'
    . '.btn-blue{background:#2563eb;color:#ffffff;padding:12px 20px}.btn-green{background:#16a34a;color:#ffffff;padding:12px 20px}'
    . '.amber{color:#b45309;font-size:40px}.rose{color:#be123c;font-size:32px}'
    . '.dot{color:var(--cyan);font-size:10px}.eyebrow{color:var(--cyan);font-size:12px;letter-spacing:.1em}';
$greys = '';
foreach (array('g1', 'g2', 'g3', 'g4', 'g5', 'g6') as $grey) {
    $greys .= '<p class="' . $grey . '">' . $copy . $copy . '</p><p class="' . $grey . 'b">' . $copy . $copy . '</p>';
}
$features = '';
for ($i = 1; $i <= 46; $i++) $features .= '<li><span class="dot">●</span> Feature ' . $i . '</li>';

return array(
    'entrypoint' => 'index.html',
    'files' => array(
        'assets/site.css' => $css,
        'index.html' => '<!doctype html><html><head><title>Plans</title><link rel="stylesheet" href="assets/site.css"></head><body><main>'
            . '<section><p class="eyebrow">New</p><h1 class="amber">Plans for every team</h1>' . $greys . '</section>'
            . '<section><p class="eyebrow">Included</p><h2 class="rose">Everything you need</h2><ul>' . $features . '</ul>'
            . '<p><a class="btn-blue" href="index.html">Start free</a> <a class="btn-green" href="index.html">Talk to sales</a></p></section>'
            . '</main></body></html>',
    ),
);
