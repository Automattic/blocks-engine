<?php
declare(strict_types=1);

// A site whose stylesheet defines its design as custom-property tokens and
// applies them through component classes and element selectors. `--font-mono`
// is defined and applied to `.code`, which no page uses.
$css = ':root{--color-paper:#fdfcf8;--color-ink:#1c1917;--color-muted:#57534e;--color-brand:#b91c1c;--color-brand-ink:#fef2f2;--color-sky:#0369a1;--color-sand:#e7e5e4;'
    . '--font-body:"Source Sans 3",Helvetica,Arial,sans-serif;--font-heading:"Playfair Display",Georgia,serif;--font-mono:"JetBrains Mono",monospace;'
    . '--step--1:0.875rem;--step-0:1.125rem;--step-1:1.5rem;--step-2:2rem;--step-3:2.75rem;--space-s:1rem;--space-m:1.5rem;--space-l:3rem}'
    . 'body{margin:0;background:var(--color-paper);color:var(--color-ink);font-family:var(--font-body);font-size:var(--step-0)}'
    . 'h1,h2,h3{font-family:var(--font-heading);color:var(--color-ink)}h1{font-size:var(--step-3)}h2{font-size:var(--step-2)}h3{font-size:var(--step-1)}'
    . 'a{color:var(--color-sky)}.code{font-family:var(--font-mono)}'
    . '.wrap{max-width:46rem;margin:0 auto;padding:var(--space-l) var(--space-m)}.lede{font-size:var(--step-1);color:var(--color-muted)}'
    . '.note{font-size:var(--step--1);color:var(--color-muted)}.panel{background:var(--color-sand);padding:var(--space-m)}'
    . '.button{background:var(--color-brand);color:var(--color-brand-ink);padding:var(--space-s) var(--space-m);text-decoration:none}'
    . '.masthead{background:var(--color-ink);color:var(--color-paper);padding:var(--space-s) var(--space-m)}';
$page = static function (string $title, string $lede, string $body, string $prefix): string {
    return '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="' . $prefix . 'site.css"></head><body>'
        . '<header class="masthead"><p>The Field Journal</p></header>'
        . '<main class="wrap"><h1>' . $title . '</h1><p class="lede">' . $lede . '</p>'
        . '<p>' . $body . '</p><p>' . $body . ' Each season adds a little more to the record.</p>'
        . '<h2>From the archive</h2><p>Browse <a href="' . $prefix . 'index.html">earlier entries</a> or read the methods.</p>'
        . '<div class="panel"><h3>Subscribe</h3><p>One letter each month, nothing else.</p><a class="button" href="' . $prefix . 'subscribe/index.html">Subscribe</a></div>'
        . '<p class="note">Photographs by the authors unless noted.</p></main></body></html>';
};
return array(
    'entrypoint' => 'index.html',
    'files' => array(
        'site.css' => $css,
        'index.html' => $page('Notes from the marsh', 'Twelve months of walking the same mile of coastline.', 'The tide sets the schedule here, and the birds keep to it more faithfully than we do.', ''),
        'subscribe/index.html' => $page('Subscribe to the journal', 'Letters about birds, weather and the slow change of a place.', 'Subscribers receive each new entry as it is written, along with the field sketches.', '../'),
        'methods/index.html' => $page('How we record', 'Notebooks, a pair of binoculars and a lot of patience.', 'Every observation is dated, located and checked against the previous year before it is published.', '../'),
    ),
);
