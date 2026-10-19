<?php
declare(strict_types=1);

// A dashboard on a `#f7fafc` page whose content sits on many large white
// (`#ffffff`) cards. White is within ΔE 5 of `#f7fafc`, so palette grouping
// ranks it with `base`, but it is the most painted surface on the page.
// Eleven distinct colours are painted besides: dark body text, blue and red
// buttons, seven text colours on full paragraphs, and two colours that each
// mark one tiny element only (`#34d399`, `#80a690`). The palette is capped at
// twelve, so something stays literal: it must be the two barely used
// colours, never the heavy white surface.
$copy = 'Revenue, sessions and conversion for the last thirty days, compared with the previous period and broken down by channel. ';
$css = 'html{font-family:Inter,sans-serif}body{margin:0;background:#f7fafc;color:#1a202c}'
    . '.card{background:#ffffff;padding:48px 32px;margin:24px}'
    . '.btn-blue{background:#2b6cb0;color:#ffffff;padding:12px 20px}.btn-red{background:#c53030;color:#ffffff;padding:12px 20px}'
    . '.t1{color:#4a5568}.t2{color:#718096}.t3{color:#2f855a}.t4{color:#b7791f}.t5{color:#6b46c1}.t6{color:#d53f8c}.t7{color:#c05621}'
    . '.tiny-green{color:#34d399;font-size:10px}.tiny-sage{color:#80a690;font-size:10px}';
$cards = '';
for ($i = 1; $i <= 8; $i++) $cards .= '<div class="card"><h2>Report ' . $i . '</h2><p>' . $copy . $copy . '</p><p>' . $copy . $copy . '</p></div>';
$paragraphs = '';
foreach (array('t1', 't2', 't3', 't4', 't5', 't6', 't7') as $class) $paragraphs .= '<p class="' . $class . '">' . $copy . $copy . '</p>';

return array(
    'entrypoint' => 'index.html',
    'files' => array(
        'assets/site.css' => $css,
        'index.html' => '<!doctype html><html><head><title>Reports</title><link rel="stylesheet" href="assets/site.css"></head><body><main>'
            . '<section><h1>Reports</h1>' . $paragraphs . '<p><span class="tiny-green">●</span> Live <span class="tiny-sage">●</span> Synced</p></section>'
            . '<section>' . $cards . '</section>'
            . '<p><a class="btn-blue" href="index.html">Export</a> <a class="btn-red" href="index.html">Reset</a></p>'
            . '</main></body></html>',
    ),
);
