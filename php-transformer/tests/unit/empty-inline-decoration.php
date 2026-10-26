<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// #3480: an empty inline box that only CSS sizes and paints (a logo dot)
// survives conversion as a RichText carrier, in place, with the spaces the
// source put around it. A link lowered into a paragraph uses the same carrier
// as a paragraph does, and the carrier stays inside its link.
$flexTitle = '.brand .title{display:flex;align-items:center;gap:.6rem}';
$emDot = '.brand .dot{display:inline-block;width:.55em;height:.55em;border-radius:50%;background:currentColor}';
$pxDot = '.brand .dot{display:inline-block;width:12px;height:12px;border-radius:50%;background:#c33}';
$paintOnlyDot = '.brand .dot{display:inline-block;border-radius:50%;background:currentColor}';

$cases = array(
    'link, em sizes' => array(
        'css' => $flexTitle . $emDot,
        'html' => '<div class="brand"><a class="title" href="/">Paws <span class="dot"></span> Whiskers</a></div>',
        'pattern' => '#<a\b[^>]*href="/"[^>]*>Paws <mark\b[^>]*\bdot\b[^>]*></mark> Whiskers</a>#',
        'paint' => array('width:.55em', 'height:.55em', 'background:currentColor'),
    ),
    'link, px sizes' => array(
        'css' => $flexTitle . $pxDot,
        'html' => '<div class="brand"><a class="title" href="/">Paws <span class="dot"></span> Whiskers</a></div>',
        'pattern' => '#<a\b[^>]*href="/"[^>]*>Paws <mark\b[^>]*\bdot\b[^>]*></mark> Whiskers</a>#',
        'paint' => array('width:12px', 'height:12px', 'background:#c33'),
    ),
    'link, inline-style sizes' => array(
        'css' => $flexTitle . $paintOnlyDot,
        'html' => '<div class="brand"><a class="title" href="/">Paws <span class="dot" style="width:8px;height:8px"></span> Whiskers</a></div>',
        'pattern' => '#<a\b[^>]*href="/"[^>]*>Paws <mark\b[^>]*\bdot\b[^>]*></mark> Whiskers</a>#',
        'paint' => array('width:8px', 'height:8px', 'background:currentColor'),
    ),
    'link, dot at the start' => array(
        'css' => $flexTitle . $emDot,
        'html' => '<div class="brand"><a class="title" href="/"><span class="dot"></span> Paws Whiskers</a></div>',
        'pattern' => '#<a\b[^>]*href="/"[^>]*><mark\b[^>]*\bdot\b[^>]*></mark> Paws Whiskers</a>#',
        'paint' => array('width:.55em', 'background:currentColor'),
    ),
    'link, dot at the end' => array(
        'css' => $flexTitle . $emDot,
        'html' => '<div class="brand"><a class="title" href="/">Paws Whiskers <span class="dot"></span></a></div>',
        'pattern' => '#<a\b[^>]*href="/"[^>]*>Paws Whiskers <mark\b[^>]*\bdot\b[^>]*></mark></a>#',
        'paint' => array('width:.55em', 'background:currentColor'),
    ),
    'paragraph, em sizes' => array(
        'css' => $flexTitle . $emDot,
        'html' => '<div class="brand"><p class="title">Paws <span class="dot"></span> Whiskers</p></div>',
        'pattern' => '#<p\b[^>]*>Paws <mark\b[^>]*\bdot\b[^>]*></mark> Whiskers</p>#',
        'paint' => array('width:.55em', 'height:.55em', 'background:currentColor'),
    ),
    'paragraph, px sizes' => array(
        'css' => $flexTitle . $pxDot,
        'html' => '<div class="brand"><p class="title">Paws <span class="dot"></span> Whiskers</p></div>',
        'pattern' => '#<p\b[^>]*>Paws <mark\b[^>]*\bdot\b[^>]*></mark> Whiskers</p>#',
        'paint' => array('width:12px', 'background:#c33'),
    ),
    'paragraph, inline-style sizes and paint' => array(
        'css' => '',
        'html' => '<p>Paws <span style="display:inline-block;width:8px;height:8px;background:red"></span> Whiskers</p>',
        'pattern' => '#<p\b[^>]*>Paws <mark\b[^>]*></mark> Whiskers</p>#',
        'paint' => array('width:8px', 'height:8px', 'background:red'),
    ),
    'paragraph, dot at the start' => array(
        'css' => $flexTitle . $emDot,
        'html' => '<div class="brand"><p class="title"><span class="dot"></span>   Paws Whiskers</p></div>',
        'pattern' => '#<p\b[^>]*><mark\b[^>]*\bdot\b[^>]*></mark> Paws Whiskers</p>#',
        'paint' => array('width:.55em', 'background:currentColor'),
    ),
    'paragraph, dot at the end' => array(
        'css' => $flexTitle . $emDot,
        'html' => '<div class="brand"><p class="title">Paws Whiskers <span class="dot"></span></p></div>',
        'pattern' => '#<p\b[^>]*>Paws Whiskers <mark\b[^>]*\bdot\b[^>]*></mark></p>#',
        'paint' => array('width:.55em', 'background:currentColor'),
    ),
);

$failures = array();
foreach ($cases as $label => $case) {
    $markup = (string) (new HtmlTransformer())->transform($case['html'], array('static_css' => $case['css']))->toArray()['serialized_blocks'];
    if (1 !== preg_match($case['pattern'], $markup)) {
        $failures[] = $label . ': expected the empty carrier in place with its spaces; got ' . $markup;
        continue;
    }
    preg_match('#<mark\b[^>]*\bstyle="([^"]*)"#', $markup, $style);
    foreach ($case['paint'] as $declaration) {
        if (!str_contains($style[1] ?? '', $declaration)) {
            $failures[] = $label . ': the carrier lost `' . $declaration . '`; got ' . $markup;
        }
    }
    // A background shorthand already paints the box; a later
    // `background-color:transparent` reset would erase it.
    if (str_contains($style[1] ?? '', 'background-color:transparent')) {
        $failures[] = $label . ': the carrier paint was reset to transparent; got ' . $markup;
    }
    if (1 !== substr_count($markup, '<mark')) {
        $failures[] = $label . ': expected exactly one carrier; got ' . $markup;
    }
}

// Whitespace that the flex container never paints is still dropped where no
// empty carrier sits next to it: only the carrier's neighbours keep a space.
$markup = (string) (new HtmlTransformer())->transform(
    '<div class="brand"><p class="title">  Paws <strong>Whiskers</strong>  </p></div>',
    array('static_css' => $flexTitle)
)->toArray()['serialized_blocks'];
if (!str_contains($markup, '<p class="title">Paws<strong>Whiskers</strong></p>')) {
    $failures[] = 'flex boundary whitespace without a carrier must still be trimmed; got ' . $markup;
}

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "empty-inline-decoration: ok\n";
