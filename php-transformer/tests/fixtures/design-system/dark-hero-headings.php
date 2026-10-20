<?php
declare(strict_types=1);

// A neutral three-page site on a light page background with dark body text.
// Every page opens with a dark section that sets a light text colour
// (`background:#111;color:#fff`), and the page's only H1 sits inside it and
// inherits that colour. Every H1 therefore renders #fff, though no rule ever
// colours an H1, and white does not read on the light page background.
// With `$darkHeadings` the H1s sit on the page background in a dark colour
// instead: the control, whose unanimous colour does read there.
return static function (bool $darkHeadings = false): array {
    $css = 'body{margin:0;background:#f6f8fa;color:#1f2328;font-family:Inter,sans-serif}'
        . 'h1,h2{margin:0;font-weight:700}p{margin:0 0 16px}.hero{padding:64px 24px}.title{font-size:48px}'
        . '.content{padding:32px 24px}.muted{color:#57606a}';
    $page = static fn(string $title, string $body): string => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/site.css"></head>'
        . '<body><header><nav><a href="/index.html">Home</a> <a href="/about.html">About</a> <a href="/contact.html">Contact</a></nav></header><main>' . $body . '</main></body></html>';
    $copy = str_repeat('We plan, build and maintain small websites for local organisations, and we keep every one of them fast, readable and easy to edit. ', 5);
    $hero = static fn(string $heading): string => $darkHeadings
        ? '<section class="hero"><h1 class="title" style="color:#0b1f33">' . $heading . '</h1><p>An introduction.</p></section>'
        : '<section class="hero" style="background:#111;color:#fff"><h1 class="title">' . $heading . '</h1><p>An introduction.</p></section>';
    $body = static fn(string $heading, string $section): string => $hero($heading)
        . '<section class="content"><h2>' . $section . '</h2><p>' . $copy . '</p><p class="muted">Updated monthly.</p></section>';

    return array(
        'entrypoint' => 'index.html',
        'files' => array(
            'site.css' => $css,
            'index.html' => $page('Home', $body('Websites that stay easy', 'What we do')),
            'about.html' => $page('About', $body('About the team', 'How we work')),
            'contact.html' => $page('Contact', $body('Get in touch', 'Office hours')),
        ),
    );
};
