<?php
declare(strict_types=1);

// A neutral two-page site on a light page background with dark body text.
// Each page opens with a dark band whose text colour comes from its class
// through a custom property (`.band{background:#1e2024;color:var(--band-fg)}`,
// `:root{--band-fg:#f5f1e8}`), and the page's only H1 sits inside it. No rule
// colours a heading, so every heading inherits: the browser renders the band
// headings in the light custom property, while the usage collector, which
// cannot resolve a var() colour, records the inherited root text colour for
// every heading. An element colour equal to the root text colour is a no-op
// at the root and must not be emitted: `h1..h6 { color: contrast }` would
// override the band's inheritance and paint the band headings dark-on-dark.
// With `$styledHeadings` each H1 states the root colour itself: the control,
// whose headings keep rendering it through their own blocks instead of an
// element style.
return static function (bool $styledHeadings = false): array {
    $css = ':root{--band-fg:#f5f1e8}'
        . 'body{margin:0;background:#faf9f5;color:#1e2024;font-family:Inter,sans-serif}'
        . 'h1,h2{margin:0;font-weight:700}p{margin:0 0 16px}.band{padding:64px 24px;background:#1e2024;color:var(--band-fg)}'
        . '.title{font-size:48px}.content{padding:32px 24px}';
    $page = static fn(string $title, string $body): string => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/site.css"></head>'
        . '<body><header><nav><a href="/index.html">Home</a> <a href="/about.html">About</a></nav></header><main>' . $body . '</main></body></html>';
    $style = $styledHeadings ? ' style="color:#1e2024"' : '';
    $body = static fn(string $heading, string $section): string => '<section class="band"><h1 class="title"' . $style . '>' . $heading . '</h1><p>An introduction.</p></section>'
        . '<section class="content"><h2>' . $section . '</h2><p>We plan, build and maintain small websites for local organisations, and we keep every one of them fast, readable and easy to edit.</p></section>';

    return array(
        'entrypoint' => 'index.html',
        'files' => array(
            'site.css' => $css,
            'index.html' => $page('Home', $body('Websites that stay easy', 'What we do')),
            'about.html' => $page('About', $body('About the team', 'How we work')),
        ),
    );
};
