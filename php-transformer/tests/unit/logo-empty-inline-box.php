<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

// #3566: a logo label keeps a CSS-painted empty inline box (a dot between two
// words) in place through its unsupported-tag unwrap, which runs on the DOM
// and asks SourceDom whether an element is an empty inline box.
$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL '), $what, "\n";
    $failures += $ok ? 0 : 1;
};

$css = '.site-title{font-weight:700;text-decoration:none}'
    . '.paw-dot{display:inline-block;width:.55em;height:.55em;border-radius:50%;background:currentColor}'
    . '.brand-tag{font-size:.8em}';
$nav = '<nav><ul><li><a href="/">Home</a></li></ul></nav>';
$dot = '<mark class="paw-dot" style="display:inline-block;width:.55em;height:.55em;border-radius:50%;background:currentColor;color:inherit" role="none"></mark>';

$labelCases = array(
    'dot between words' => array(
        'html' => '<header class="site-header"><div class="brand"><a class="site-title" href="/">Paws <span class="paw-dot"></span> Whiskers</a></div>' . $nav . '</header>',
        'label' => '<a class="site-title" href="/">Paws ' . $dot . ' Whiskers</a>',
    ),
    'dot beside a tagline div' => array(
        'html' => '<header class="site-header"><a class="site-title logo" href="/"><span class="paw-dot"></span>Paws<div class="brand-tag">Pet supplies</div></a>' . $nav . '</header>',
        'label' => '<a class="site-title logo" href="/">' . $dot . 'Paws Pet supplies</a>',
    ),
    'two dots around a strong' => array(
        'html' => '<header class="site-header"><a class="site-title logo" href="/"><strong>Paws</strong> <span class="paw-dot"></span> &amp; <span class="paw-dot"></span> Whiskers</a>' . $nav . '</header>',
        'label' => '<a class="site-title logo" href="/"><strong>Paws</strong> ' . $dot . ' &amp; ' . $dot . ' Whiskers</a>',
    ),
);

foreach ( $labelCases as $name => $case ) {
    $serialized = (new HtmlTransformer())->transform($case['html'], array( 'static_css' => $css ))->toArray()['serialized_blocks'];
    $check(str_contains($serialized, $case['label']), "{$name}: label keeps its empty inline boxes in place");
    $check(! preg_match('/[\x{E000}-\x{F8FF}]/u', $serialized), "{$name}: no private-use characters leak into the output");
}

$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="utf-8" ?><body>'
    . '<span id="empty"></span>'
    . '<span id="whitespace">  ' . "\n\t" . ' </span>'
    . '<span id="comment"><!-- dot --></span>'
    . '<span id="comment-and-space"> <!-- dot --> </span>'
    . '<span id="text">Paws</span>'
    . '<span id="nbsp">&nbsp;</span>'
    . '<span id="child"><i></i></span>'
    . '<img id="void" src="logo.png">'
    . '<br id="break">'
    . '<b id="bold"></b>'
    . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
libxml_use_internal_errors($previous);

$definitionCases = array(
    'empty span' => array( 'empty', true ),
    'span holding only whitespace' => array( 'whitespace', true ),
    'span holding only a comment' => array( 'comment', true ),
    'span holding a comment and whitespace' => array( 'comment-and-space', true ),
    'span with text' => array( 'text', false ),
    'span holding a non-breaking space' => array( 'nbsp', false ),
    'span holding an element' => array( 'child', false ),
    'void img element' => array( 'void', false ),
    'void br element' => array( 'break', false ),
    'empty non-span inline element' => array( 'bold', true ),
);

$xpath = new DOMXPath($document);
foreach ( $definitionCases as $name => [ $id, $expected ] ) {
    $element = $xpath->query('//*[@id="' . $id . '"]')->item(0);
    if ( ! $element instanceof DOMElement ) {
        $check(false, "{$name}: fixture element found");
        continue;
    }
    $check($expected === SourceDom::isEmptyInlineBox($element), "{$name}: " . ($expected ? 'is' : 'is not') . ' an empty inline box');
}

exit($failures > 0 ? 1 : 0);
