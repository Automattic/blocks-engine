<?php
declare(strict_types=1);

// A small journal whose body and interface text use the platform UI stack
// (`-apple-system, BlinkMacSystemFont, …`) and whose page and section
// headings use a display webfont through a utility class. One route's
// minor headings stay in the system stack, so no family is unanimous for
// headings.
//
// Rich text colours phrases three ways: a span with a colour class, a span
// with an inline colour, and an authored `<mark>`. The navigation links are
// translucent (`text-paper/70`) beside a call-to-action link.
$css = '*,::before,::after{box-sizing:border-box;border-width:0;border-style:solid}'
    . 'html{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}body{margin:0}'
    . 'h1,h2,h3{font-size:inherit;font-weight:inherit;margin:0}a{color:inherit;text-decoration:inherit}p{margin:0}'
    . '.bg-ink{background-color:#101827}.text-paper{color:#f5f1e8}.text-ink{color:#101827}.text-sky{color:#38bdf8}.text-paper\/70{color:rgb(245 241 232 / 0.7)}'
    . '.font-display{font-family:"Fraunces",Georgia,serif}.text-sm{font-size:.875rem}.text-xl{font-size:1.25rem}.text-3xl{font-size:1.875rem}'
    . '.p-4{padding:1rem}.px-3{padding-left:.75rem;padding-right:.75rem}.flex{display:flex}.gap-4{gap:1rem}.bg-sky{background-color:#38bdf8}.rounded{border-radius:.25rem}';

$page = static fn(string $title, string $body): string => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/site.css"></head>'
    . '<body class="bg-ink text-paper">'
    . '<header class="p-4"><nav class="flex gap-4"><a class="text-sm text-paper/70" href="/index.html">Home</a><a class="text-sm text-paper/70" href="/notes.html">Notes</a><a class="text-sm bg-sky text-ink px-3 rounded" href="/contact.html">Subscribe</a></nav></header>'
    . '<main class="p-4"><h1 class="font-display text-3xl">' . $title . '</h1>' . $body . '</main></body></html>';

return array(
    'entrypoint' => 'index.html',
    'files' => array(
        'site.css' => $css,
        'index.html' => $page('Field notes', '<p class="text-xl">A journal with <span class="text-sky">a highlighted phrase</span>, <span style="color:#38bdf8">an inline one</span> and <mark class="text-sky">a marked one</mark>.</p>'
            . '<h2 class="font-display text-xl">This week</h2><p>Body copy that runs long enough for the body text to carry most of the weight on this page.</p>'
            . '<a class="bg-sky text-ink px-3 rounded" href="/contact.html">Read more</a>'),
        'notes.html' => $page('Notes', '<h2 class="font-display text-xl">Recent</h2><p>More body copy in the system stack, so it outweighs the headings comfortably.</p>'),
        'contact.html' => $page('Contact', '<h3 class="text-xl">Write to us</h3><h3 class="text-xl">Visit us</h3><h3 class="text-xl">Call us</h3><p>We answer every message.</p>'),
    ),
);
