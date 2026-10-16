<?php
declare(strict_types=1);

// A studio site whose body text is Inter and whose page titles and section
// headings use a display webfont (Fraunces). The blog index lists
// twenty-four posts, each titled by a small `h3` in Arial, a system font
// with a one-line excerpt, so most of that route's text, and more heading
// text than every other route together, is small system-font titles. The
// other routes are mostly long body copy under one title and one section
// heading.
$css = 'html{font-family:Inter,sans-serif}body{margin:0;background:#fbfaf7;color:#1f2328}'
    . 'h1,h2,h3{margin:0;font-weight:600}p{margin:0}'
    . '.display{font-family:"Fraunces",Georgia,serif}.title{font-size:48px}.section{font-size:32px}'
    . '.post-title{font-family:Arial,Helvetica,sans-serif;font-size:15px}.excerpt{font-size:14px;color:#57606a}';

$page = static fn(string $title, string $body): string => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/site.css"></head>'
    . '<body><header><nav><a href="/index.html">Home</a> <a href="/blog/index.html">Blog</a> <a href="/about.html">About</a></nav></header><main>' . $body . '</main></body></html>';
$copy = str_repeat('We make furniture and objects by hand, in small batches, from timber we know by name, and we keep every offcut for the next piece. ', 6);
$posts = '';
for ($i = 1; $i <= 24; $i++) {
    $posts .= '<article><h3 class="post-title">Notes from the workshop, part ' . $i . ': materials, tools and the long afternoon</h3><p class="excerpt">A short excerpt.</p></article>';
}

return array(
    'entrypoint' => 'index.html',
    'files' => array(
        'site.css' => $css,
        'index.html' => $page('Studio', '<h1 class="display title">Quiet, careful work</h1><p>We make furniture and objects by hand, in small batches, from timber we know by name. ' . $copy . '</p>'
            . '<h2 class="display section">Recent work</h2><p>A walnut desk, a set of ash stools and a cabinet for a library reading room. ' . $copy . '</p>'),
        'about.html' => $page('About', '<h1 class="display title">About the studio</h1><p>Two makers, one workshop and a long apprenticeship behind both of us. ' . $copy . '</p>'
            . '<h2 class="display section">Visit</h2><p>The workshop is open on the first Saturday of every month. ' . $copy . '</p>'),
        'blog/index.html' => $page('Blog', $posts),
    ),
);
