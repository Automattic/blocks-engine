<?php
declare(strict_types=1);

// A dark marketing site compiled by Tailwind CSS v3, the way a
// Tailwind/Vite build ships it: arbitrary-value utilities
// (`bg-[#050b18]`, `text-[#93a8ce]`) carry the brand colours, every route
// shares a header (brand link, navigation, call-to-action link) and a footer
// (link columns and small print), and headings use a display face.
//
// One route (`/app/`) is a product screen with its own light look: a white
// canvas, near-black text in the system font stack, and far more text than
// any marketing page. It is one route of six, so the site's roles must still
// come from the shared shell and the other five routes.
$css = '*,::before,::after{box-sizing:border-box;border-width:0;border-style:solid;border-color:#e5e7eb}'
    . 'html{line-height:1.5;-webkit-text-size-adjust:100%;font-family:Inter,ui-sans-serif,system-ui,sans-serif}'
    . 'body{margin:0;line-height:inherit}h1,h2,h3,h4,h5,h6{font-size:inherit;font-weight:inherit}a{color:inherit;text-decoration:inherit}'
    . 'blockquote,dl,dd,h1,h2,h3,h4,h5,h6,hr,figure,p,pre{margin:0}ul,ol{list-style:none;margin:0;padding:0}'
    . '*,::before,::after{--tw-ring-offset-shadow:0 0 #0000;--tw-shadow:0 0 #0000}'
    . '.mx-auto{margin-left:auto;margin-right:auto}.max-w-6xl{max-width:72rem}.flex{display:flex}.grid{display:grid}'
    . '.items-center{align-items:center}.justify-between{justify-content:space-between}.gap-6{gap:1.5rem}.gap-8{gap:2rem}'
    . '.grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}'
    . '.bg-\[\#050b18\]{--tw-bg-opacity:1;background-color:rgb(5 11 24 / var(--tw-bg-opacity, 1))}'
    . '.bg-\[\#0d1b3a\]{--tw-bg-opacity:1;background-color:rgb(13 27 58 / var(--tw-bg-opacity, 1))}'
    . '.bg-\[\#3b82f6\]{--tw-bg-opacity:1;background-color:rgb(59 130 246 / var(--tw-bg-opacity, 1))}'
    . '.bg-white{--tw-bg-opacity:1;background-color:rgb(255 255 255 / var(--tw-bg-opacity, 1))}'
    . '.bg-gray-100{--tw-bg-opacity:1;background-color:rgb(243 244 246 / var(--tw-bg-opacity, 1))}'
    . '.text-\[\#e9f1ff\]{--tw-text-opacity:1;color:rgb(233 241 255 / var(--tw-text-opacity, 1))}'
    . '.text-\[\#93a8ce\]{--tw-text-opacity:1;color:rgb(147 168 206 / var(--tw-text-opacity, 1))}'
    . '.text-\[\#1a1a1a\]{--tw-text-opacity:1;color:rgb(26 26 26 / var(--tw-text-opacity, 1))}'
    . '.text-gray-600{--tw-text-opacity:1;color:rgb(75 85 99 / var(--tw-text-opacity, 1))}'
    . '.text-white{--tw-text-opacity:1;color:rgb(255 255 255 / var(--tw-text-opacity, 1))}'
    . '.text-xs{font-size:0.75rem;line-height:1rem}.text-sm{font-size:0.875rem;line-height:1.25rem}.text-base{font-size:1rem;line-height:1.5rem}'
    . '.text-lg{font-size:1.125rem;line-height:1.75rem}.text-2xl{font-size:1.5rem;line-height:2rem}.text-4xl{font-size:2.25rem;line-height:2.5rem}'
    . '.font-display{font-family:"Space Grotesk",Inter,sans-serif}.font-system{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
    . '.font-semibold{font-weight:600}.font-bold{font-weight:700}.tracking-tight{letter-spacing:-0.025em}'
    . '.p-4{padding:1rem}.p-6{padding:1.5rem}.px-4{padding-left:1rem;padding-right:1rem}.px-5{padding-left:1.25rem;padding-right:1.25rem}'
    . '.py-2{padding-top:0.5rem;padding-bottom:0.5rem}.py-6{padding-top:1.5rem;padding-bottom:1.5rem}.py-12{padding-top:3rem;padding-bottom:3rem}'
    . '.mt-2{margin-top:0.5rem}.mt-4{margin-top:1rem}.mt-8{margin-top:2rem}.rounded-lg{border-radius:0.5rem}'
    . '.hover\:text-white:hover{--tw-text-opacity:1;color:rgb(255 255 255 / var(--tw-text-opacity, 1))}'
    . '.hover\:bg-\[\#2563eb\]:hover{--tw-bg-opacity:1;background-color:rgb(37 99 235 / var(--tw-bg-opacity, 1))}';

$header = static fn(string $prefix): string => '<header class="bg-[#050b18] px-4 py-6"><div class="max-w-6xl mx-auto flex items-center justify-between">'
    . '<a class="text-lg font-bold font-display text-[#e9f1ff]" href="' . $prefix . 'index.html">Liberate</a>'
    . '<nav aria-label="Primary"><ul class="flex gap-6">'
    . '<li><a class="text-sm text-[#93a8ce]" href="' . $prefix . 'services/index.html">Services</a></li>'
    . '<li><a class="text-sm text-[#93a8ce]" href="' . $prefix . 'before-after/index.html">Before and after</a></li>'
    . '<li><a class="text-sm text-[#93a8ce]" href="' . $prefix . 'about/index.html">About</a></li>'
    . '<li><a class="text-sm text-[#93a8ce]" href="' . $prefix . 'contact/index.html">Contact</a></li>'
    . '</ul></nav>'
    . '<a class="bg-[#3b82f6] text-white text-sm font-semibold px-5 py-2 rounded-lg" href="' . $prefix . 'contact/index.html">Book a call</a>'
    . '</div></header>';
$footer = static fn(string $prefix): string => '<footer class="bg-[#0d1b3a] px-4 py-12"><div class="max-w-6xl mx-auto grid grid-cols-3 gap-8">'
    . '<div><p class="text-base font-semibold font-display text-[#e9f1ff]">Liberate</p><p class="mt-2 text-sm text-[#93a8ce]">Move your content to an open web platform without losing a page.</p></div>'
    . '<div><p class="text-sm font-semibold text-[#e9f1ff]">Company</p><ul class="mt-2"><li><a class="text-sm text-[#93a8ce]" href="' . $prefix . 'about/index.html">About us</a></li><li><a class="text-sm text-[#93a8ce]" href="' . $prefix . 'contact/index.html">Contact the team</a></li></ul></div>'
    . '<div><p class="text-sm font-semibold text-[#e9f1ff]">Work</p><ul class="mt-2"><li><a class="text-sm text-[#93a8ce]" href="' . $prefix . 'services/index.html">Migration services</a></li><li><a class="text-sm text-[#93a8ce]" href="' . $prefix . 'before-after/index.html">Before and after</a></li></ul></div>'
    . '</div><p class="max-w-6xl mx-auto mt-8 text-xs text-[#93a8ce]">Liberate Studio. Built in the open, hosted anywhere.</p></footer>';
$marketing = static function (string $title, string $lede, array $sections, string $prefix) use ($header, $footer): string {
    $body = '';
    foreach ($sections as [$heading, $copy]) {
        $body .= '<section class="mt-8 bg-[#0d1b3a] p-6 rounded-lg"><h2 class="text-2xl font-semibold font-display text-[#e9f1ff]">' . $heading . '</h2>'
            . '<p class="mt-4 text-base text-[#e9f1ff]">' . $copy . '</p>'
            . '<p class="mt-4 text-base text-[#93a8ce]">' . $copy . ' Nothing is lost on the way over.</p></section>';
    }
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="' . $prefix . 'assets/site.css"></head>'
        . '<body class="bg-[#050b18] text-[#e9f1ff]">' . $header($prefix)
        . '<main class="max-w-6xl mx-auto px-4 py-12">'
        . '<h1 class="text-4xl font-bold tracking-tight font-display text-[#e9f1ff]">' . $title . '</h1>'
        . '<p class="mt-4 text-lg text-[#93a8ce]">' . $lede . '</p>'
        . $body
        . '<p class="mt-8 text-base text-[#e9f1ff]">Ready to move? <a class="text-[#93a8ce] hover:text-white" href="' . $prefix . 'contact/index.html">Tell us about your site</a> and we will reply within a day.</p>'
        . '</main>' . $footer($prefix) . '</body></html>';
};
$app = static function () use ($header, $footer): string {
    $rows = '';
    foreach (array('Posts', 'Pages', 'Media', 'Comments', 'Users', 'Redirects', 'Menus', 'Forms') as $index => $kind) {
        $rows .= '<div class="mt-4 bg-white p-4 rounded-lg"><h3 class="text-base font-semibold text-[#1a1a1a]">' . $kind . ' import</h3>'
            . '<p class="mt-2 text-sm text-gray-600">Step ' . ($index + 1) . ' reads every ' . strtolower($kind) . ' entry from the source export, checks each reference against the destination, rewrites links that point at the old address, and records any entry it could not place so you can review it before the import is published.</p>'
            . '<p class="mt-2 text-sm text-[#1a1a1a]">Entries are processed in batches of fifty with a resumable cursor, so an interrupted import continues where it stopped and never writes the same entry twice.</p></div>';
    }
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Import console</title><link rel="stylesheet" href="../assets/site.css"></head>'
        . '<body class="bg-gray-100 text-[#1a1a1a] font-system">' . $header('../')
        . '<main class="max-w-6xl mx-auto px-4 py-12">'
        . '<h1 class="text-2xl font-semibold text-[#1a1a1a]">Import console</h1>'
        . '<p class="mt-2 text-base text-gray-600">Run each stage of the import, watch its progress and review what needs attention before you publish the result.</p>'
        . $rows . '</main>' . $footer('../') . '</body></html>';
};
return array(
    'entrypoint' => 'index.html',
    'files' => array(
        'assets/site.css' => $css,
        'index.html' => $marketing('Your site, liberated', 'We move content, design and links from any platform to an open web stack.', array(array('Every page comes along', 'Pages, posts, media and redirects are carried over and checked against the original.'), array('Design that survives', 'Colours, type and spacing become editable design tokens on the new site.')), ''),
        'services/index.html' => $marketing('Migration services', 'A fixed-scope migration with a clear plan and a working preview in the first week.', array(array('Audit', 'We crawl the current site and list every page, template and integration it depends on.'), array('Move', 'Content is imported with its history, and every old address redirects to its new home.')), '../'),
        'before-after/index.html' => $marketing('Before and after', 'Real sites, moved and measured: the same pages, faster and easier to edit.', array(array('A publisher', 'Twelve thousand articles moved in a weekend with every author and category intact.'), array('A studio', 'A hand-built portfolio became a block theme the team edits without a developer.')), '../'),
        'about/index.html' => $marketing('About Liberate', 'A small team of migration engineers who believe your content should outlive any platform.', array(array('Open by default', 'Everything we build to move a site is released for anyone else to use.')), '../'),
        'contact/index.html' => $marketing('Contact', 'Tell us where your site lives today and where you would like it to be.', array(array('What happens next', 'We reply within a day with a short plan, a timeline and a fixed price.')), '../'),
        'app/index.html' => $app(),
    ),
);
