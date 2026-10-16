<?php
declare(strict_types=1);

// A small marketing site styled the way utility-first frameworks style it:
// every colour, size and gap is a single-purpose class. `font-serif` is
// declared by the framework but never used, so it must not become a preset.
$css = '*,::before,::after{box-sizing:border-box}html{line-height:1.5;font-family:Inter,ui-sans-serif,system-ui,sans-serif}body{margin:0}'
    . '.font-sans{font-family:Inter,ui-sans-serif,system-ui,sans-serif}.font-serif{font-family:Merriweather,Georgia,serif}.font-display{font-family:"Space Grotesk",Inter,sans-serif}'
    . '.bg-white{background-color:#ffffff}.bg-slate-50{background-color:#f8fafc}.bg-slate-900{background-color:#0f172a}.bg-indigo-600{background-color:#4f46e5}.bg-amber-400{background-color:#fbbf24}'
    . '.text-slate-900{color:#0f172a}.text-slate-600{color:#475569}.text-white{color:#ffffff}.text-indigo-600{color:#4f46e5}.text-emerald-600{color:#059669}.text-slate-50{color:#f8fafc}'
    . '.text-sm{font-size:.875rem}.text-base{font-size:1rem}.text-xl{font-size:1.25rem}.text-3xl{font-size:1.875rem}.text-5xl{font-size:3rem}'
    . '.p-4{padding:1rem}.p-8{padding:2rem}.px-6{padding-left:1.5rem;padding-right:1.5rem}.py-3{padding-top:.75rem;padding-bottom:.75rem}.py-16{padding-top:4rem;padding-bottom:4rem}'
    . '.mx-auto{margin-left:auto;margin-right:auto}.max-w-5xl{max-width:64rem}.font-bold{font-weight:700}';
$page = static function (string $title, string $lede, string $body, string $prefix): string {
    return '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="' . $prefix . 'app.css"></head>'
        . '<body class="bg-white text-slate-900 font-sans">'
        . '<header class="bg-slate-900 text-slate-50 p-4"><p class="text-xl font-bold font-display">Northwind</p></header>'
        . '<main class="max-w-5xl mx-auto py-16 px-6">'
        . '<h1 class="text-5xl font-bold font-display">' . $title . '</h1>'
        . '<p class="text-xl text-slate-600">' . $lede . '</p>'
        . '<section class="bg-slate-50 p-8"><h2 class="text-3xl font-display">What we do</h2><p class="text-base">' . $body . '</p><p class="text-base">' . $body . ' Again, because good copy repeats its point.</p>'
        . '<p class="text-sm text-emerald-600">Trusted by teams everywhere.</p></section>'
        . '<p class="text-base">Read the <a class="text-indigo-600" href="' . $prefix . 'about/index.html">story behind the product</a> and the plans.</p>'
        . '<a class="bg-indigo-600 text-white px-6 py-3" href="' . $prefix . 'index.html">Get started</a>'
        . '<p class="bg-amber-400 text-slate-900 p-4 text-sm">Limited offer this month.</p>'
        . '</main>'
        . '<footer class="bg-slate-900 text-slate-50 p-8"><p class="text-sm">Northwind Ltd. All rights reserved.</p></footer>'
        . '</body></html>';
};
return array(
    'entrypoint' => 'index.html',
    'files' => array(
        'app.css' => $css,
        'index.html' => $page('Ship calmer software', 'Planning, review and release in one quiet place for the whole team.', 'We build tools that keep product teams focused on the work that matters to their customers.', ''),
        'about/index.html' => $page('About Northwind', 'A small team with a long view on how software should be made.', 'Founded by engineers and designers who wanted fewer meetings and better releases for everyone.', '../'),
        'pricing/index.html' => $page('Simple pricing', 'One plan per team size, no surprises at renewal time.', 'Every plan includes unlimited projects, reviews and releases, with support from real people.', '../'),
    ),
);
