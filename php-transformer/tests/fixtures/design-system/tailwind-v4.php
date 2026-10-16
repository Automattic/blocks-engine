<?php
declare(strict_types=1);

// A marketing site built the way Tailwind CSS v4 compiles it: theme tokens
// are custom properties in `@layer theme` (colours in `oklch()`), utilities
// read them through `var()` and `calc(var(--spacing) * n)`, and responsive,
// hover and dark variants nest their conditions inside the utility. Headings,
// paragraphs, buttons and sections get their colour and size only from
// utility classes. The dark theme follows `prefers-color-scheme`.
// `--font-serif` is part of the default theme but no page renders it.
$css = '/*! tailwindcss v4.1.11 | MIT License | https://tailwindcss.com */'
    . '@layer properties;@layer theme,base,components,utilities;'
    . '@layer theme{:root,:host{'
    . '--font-sans:"Inter",ui-sans-serif,system-ui,sans-serif,"Apple Color Emoji","Segoe UI Emoji";'
    . '--font-serif:"Merriweather",ui-serif,Georgia,serif;'
    . '--font-display:"Space Grotesk","Inter",ui-sans-serif,sans-serif;'
    . '--color-white:#fff;--color-slate-50:oklch(98.4% 0.003 247.858);--color-slate-100:oklch(96.8% 0.007 247.896);'
    . '--color-slate-300:oklch(86.9% 0.022 252.894);--color-slate-600:oklch(44.6% 0.043 257.281);--color-slate-900:oklch(20.8% 0.042 265.755);'
    . '--color-slate-950:oklch(12.9% 0.042 264.695);--color-indigo-500:oklch(58.5% 0.233 277.117);--color-indigo-600:oklch(51.1% 0.262 276.966);'
    . '--color-emerald-600:oklch(59.6% 0.145 163.225);--color-amber-300:oklch(87.9% 0.169 91.605);'
    . '--spacing:0.25rem;--container-5xl:64rem;'
    . '--text-sm:0.875rem;--text-sm--line-height:calc(1.25 / 0.875);--text-base:1rem;--text-base--line-height:calc(1.5 / 1);'
    . '--text-xl:1.25rem;--text-xl--line-height:calc(1.75 / 1.25);--text-3xl:1.875rem;--text-3xl--line-height:calc(2.25 / 1.875);'
    . '--text-5xl:3rem;--text-5xl--line-height:1;--font-weight-semibold:600;--font-weight-bold:700;'
    . '--default-font-family:var(--font-sans)}}'
    . '@layer base{*,::after,::before,::backdrop{box-sizing:border-box;margin:0;padding:0;border:0 solid}'
    . 'html,:host{line-height:1.5;-webkit-text-size-adjust:100%;tab-size:4;font-family:var(--default-font-family,ui-sans-serif,system-ui,sans-serif)}'
    . 'h1,h2,h3,h4,h5,h6{font-size:inherit;font-weight:inherit}a{color:inherit;text-decoration:inherit}'
    . 'button{font:inherit;color:inherit;background-color:transparent}}'
    . '@layer utilities{'
    . '.mx-auto{margin-inline:auto}.max-w-5xl{max-width:var(--container-5xl)}'
    . '.bg-white{background-color:var(--color-white)}.bg-slate-50{background-color:var(--color-slate-50)}.bg-slate-900{background-color:var(--color-slate-900)}'
    . '.bg-indigo-600{background-color:var(--color-indigo-600)}.bg-amber-300{background-color:var(--color-amber-300)}'
    . '.p-4{padding:calc(var(--spacing) * 4)}.p-8{padding:calc(var(--spacing) * 8)}.px-6{padding-inline:calc(var(--spacing) * 6)}'
    . '.py-3{padding-block:calc(var(--spacing) * 3)}.py-16{padding-block:calc(var(--spacing) * 16)}'
    . '.font-display{font-family:var(--font-display)}.font-sans{font-family:var(--font-sans)}.font-serif{font-family:var(--font-serif)}'
    . '.text-sm{font-size:var(--text-sm);line-height:var(--tw-leading,var(--text-sm--line-height))}'
    . '.text-base{font-size:var(--text-base);line-height:var(--tw-leading,var(--text-base--line-height))}'
    . '.text-xl{font-size:var(--text-xl);line-height:var(--tw-leading,var(--text-xl--line-height))}'
    . '.text-3xl{font-size:var(--text-3xl);line-height:var(--tw-leading,var(--text-3xl--line-height))}'
    . '.text-5xl{font-size:var(--text-5xl);line-height:var(--tw-leading,var(--text-5xl--line-height))}'
    . '.font-bold{--tw-font-weight:var(--font-weight-bold);font-weight:var(--font-weight-bold)}'
    . '.font-semibold{--tw-font-weight:var(--font-weight-semibold);font-weight:var(--font-weight-semibold)}'
    . '.text-white{color:var(--color-white)}.text-slate-50{color:var(--color-slate-50)}.text-slate-600{color:var(--color-slate-600)}'
    . '.text-slate-900{color:var(--color-slate-900)}.text-indigo-600{color:var(--color-indigo-600)}.text-emerald-600{color:var(--color-emerald-600)}'
    . '.hover\:bg-indigo-500{&:hover{@media (hover:hover){background-color:var(--color-indigo-500)}}}'
    . '.md\:text-5xl{@media (width>=48rem){font-size:var(--text-5xl);line-height:var(--tw-leading,var(--text-5xl--line-height))}}'
    . '.dark\:bg-slate-950{@media (prefers-color-scheme:dark){background-color:var(--color-slate-950)}}'
    . '.dark\:text-slate-100{@media (prefers-color-scheme:dark){color:var(--color-slate-100)}}'
    . '.dark\:text-slate-300{@media (prefers-color-scheme:dark){color:var(--color-slate-300)}}'
    . '}';
$page = static function (string $title, string $lede, string $body, string $prefix): string {
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="' . $prefix . 'assets/app.css"></head>'
        . '<body class="bg-white text-slate-900 font-sans dark:bg-slate-950 dark:text-slate-100">'
        . '<header class="bg-slate-900 text-slate-50 p-4"><p class="text-xl font-bold font-display">Northwind</p></header>'
        . '<main class="max-w-5xl mx-auto py-16 px-6">'
        . '<h1 class="text-3xl md:text-5xl font-bold font-display text-slate-900 dark:text-slate-100">' . $title . '</h1>'
        . '<p class="text-xl text-slate-600 dark:text-slate-300">' . $lede . '</p>'
        . '<section class="bg-slate-50 p-8"><h2 class="text-3xl font-semibold font-display text-slate-900">What we do</h2>'
        . '<p class="text-base text-slate-900">' . $body . '</p>'
        . '<p class="text-base text-slate-900">' . $body . ' Again, because good copy repeats its point.</p>'
        . '<p class="text-sm text-emerald-600">Trusted by teams everywhere.</p></section>'
        . '<section class="bg-slate-900 text-slate-50 p-8"><h2 class="text-3xl font-semibold font-display text-white">Ready when you are</h2>'
        . '<p class="text-base text-slate-50">Start free and invite the whole team when it fits.</p>'
        . '<p class="text-base text-slate-50">' . $lede . '</p></section>'
        . '<p class="text-base text-slate-900">Read the <a class="text-indigo-600" href="' . $prefix . 'about/index.html">story behind the product</a> and the plans.</p>'
        . '<button class="bg-indigo-600 hover:bg-indigo-500 text-white text-base font-semibold px-6 py-3" type="button">Get started</button>'
        . '<p class="bg-amber-300 text-slate-900 text-sm p-4">Limited offer this month.</p>'
        . '</main>'
        . '<footer class="bg-slate-900 text-slate-50 p-8"><p class="text-sm text-slate-50">Northwind Ltd. All rights reserved.</p></footer>'
        . '</body></html>';
};
return array(
    'entrypoint' => 'index.html',
    'files' => array(
        'assets/app.css' => $css,
        'index.html' => $page('Ship calmer software', 'Planning, review and release in one quiet place for the whole team.', 'We build tools that keep product teams focused on the work that matters to their customers.', ''),
        'about/index.html' => $page('About Northwind', 'A small team with a long view on how software should be made.', 'Founded by engineers and designers who wanted fewer meetings and better releases for everyone.', '../'),
        'pricing/index.html' => $page('Simple pricing', 'One plan per team size, no surprises at renewal time.', 'Every plan includes unlimited projects, reviews and releases, with support from real people.', '../'),
    ),
);
