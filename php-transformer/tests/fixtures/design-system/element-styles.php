<?php
declare(strict_types=1);

// A two-route clinic site with one consistent element design: every link is
// teal and darkens on hover, every button is a teal pill with white
// semibold text that darkens on hover, and headings are a serif display
// face whose size steps down by level. The darker teal is also the heading
// colour, so both hover colours are palette tokens.
$css = 'html{font-family:Inter,sans-serif}body{margin:0;background:#ffffff;color:#1f2937}'
    . 'h1,h2,h3{font-family:"Libre Baskerville",Georgia,serif;color:#134e4a;font-weight:700;margin:0}'
    . 'h1{font-size:48px}h2{font-size:32px}h3{font-size:20px}'
    . 'a{color:#0f766e}a:hover{color:#134e4a}'
    . '.btn{display:inline-block;background-color:#0f766e;color:#ffffff;font-weight:600;padding:12px 24px;border-radius:999px;text-decoration:none}.btn:hover{background-color:#134e4a;color:#ffffff}';

$page = static fn(string $title, string $body): string => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/site.css"></head>'
    . '<body><main>' . $body . '</main></body></html>';

return array(
    'entrypoint' => 'index.html',
    'files' => array(
        'site.css' => $css,
        'index.html' => $page('Clinic', '<h1>Care close to home</h1><p>Same-day appointments, a pharmacy on site and <a href="/visit.html">easy parking</a>.</p>'
            . '<h2>Services</h2><h3>Family medicine</h3><p>Check-ups, vaccinations and long-term care for every age.</p>'
            . '<div class="actions"><a class="btn" href="/visit.html">Book a visit</a></div>'),
        'visit.html' => $page('Visit', '<h1>Plan your visit</h1><p>We are open every weekday; <a href="/index.html">see our services</a> first.</p>'
            . '<h2>Getting here</h2><h3>By bus</h3><p>Lines 4 and 12 stop outside the main entrance.</p>'
            . '<div class="actions"><a class="btn" href="/index.html">Call us</a></div>'),
    ),
);
