<?php
declare(strict_types=1);

/**
 * A classed source link lowered into a synthetic paragraph keeps its classes
 * on the saved `<a>` (#3521).
 *
 * The paragraph is an engine carrier the source tree never had. When it took
 * over the link's classes, author selectors matched different elements:
 * `.site-header .site-title a`, which matches nothing in the source (the link
 * IS `.site-title`), started matching the link, and `.site-title` rules meant
 * for the link painted the wrapper. The link keeps its classes inside the
 * paragraph's RichText, the wrapper carries none of them (only engine
 * markers), and presentation the engine mapped from the link's own rule still
 * reaches the link.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$failures = 0;
$passes   = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$headerCss = '.site-header{display:flex;justify-content:space-between;color:#fff;background:#222}'
    . '.site-header .site-title{font-weight:700;font-size:1.35rem;display:flex;align-items:center;gap:.6rem}'
    . '.site-header .site-title a{color:inherit}'
    . '.site-header nav a{color:#fff}';
$nav = '<nav><a href="/shop/">Shop</a></nav>';

/**
 * Each case: source markup, author CSS, the link's source classes, the
 * selectors (descendant chains of classes and tags) that must match the link
 * and those that must match nothing, as in the source tree.
 */
$cases = array(
    'header text link' => array(
        'html'      => '<header class="site-header"><a class="site-title" href="/">Paws Whiskers</a>' . $nav . '</header>',
        'css'       => $headerCss,
        'classes'   => array( 'site-title' ),
        'matches'   => array( '.site-header .site-title' ),
        'unmatched' => array( '.site-header .site-title a' ),
        'text'      => '/Paws\s*(<[^>]+>\s*)*Whiskers/',
    ),
    'header link with a decoration' => array(
        'html'      => '<header class="site-header"><a class="site-title" href="/">Paws <span class="dot"></span> Whiskers</a>' . $nav . '</header>',
        'css'       => $headerCss,
        'classes'   => array( 'site-title' ),
        'matches'   => array( '.site-header .site-title' ),
        'unmatched' => array( '.site-header .site-title a' ),
        'text'      => '/Paws\s*(<[^>]+>\s*)*Whiskers/',
    ),
    'header link with two classes' => array(
        'html'      => '<header class="site-header"><a class="site-title brand-link" href="/">Paws Whiskers</a>' . $nav . '</header>',
        'css'       => $headerCss,
        'classes'   => array( 'site-title', 'brand-link' ),
        'matches'   => array( '.site-header .site-title', '.site-header .brand-link' ),
        'unmatched' => array( '.site-header .site-title a', '.site-header .brand-link a' ),
        'text'      => '/Paws\s*(<[^>]+>\s*)*Whiskers/',
    ),
    'brand link outside a header' => array(
        'html'      => '<div class="masthead"><a class="site-title" href="/">Paws Whiskers</a></div>',
        'css'       => '.masthead .site-title{font-weight:700}.masthead .site-title a{color:inherit}',
        'classes'   => array( 'site-title' ),
        'matches'   => array( '.masthead .site-title' ),
        'unmatched' => array( '.masthead .site-title a' ),
        'text'      => '/Paws Whiskers/',
    ),
    'link styled by its own rule' => array(
        'html'      => '<div class="masthead"><a class="brand-link" href="/">Paws Whiskers</a><p>Since 1999</p></div>',
        'css'       => '.masthead{display:flex}.brand-link{color:#c00;font-weight:700;font-size:1.5rem;text-decoration:none}',
        'classes'   => array( 'brand-link' ),
        'matches'   => array( '.masthead .brand-link' ),
        'unmatched' => array( '.masthead .brand-link a' ),
        'text'      => '/Paws Whiskers/',
        'own_rule'  => '.brand-link{color:#c00;font-weight:700;font-size:1.5rem;text-decoration:none}',
        'host_color' => '#c00',
    ),
);

$classTest = static fn (string $class): string => "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
$selectorXPath = static function (string $selector) use ($classTest): string {
    $steps = array();
    foreach ( preg_split('/\s+/', trim($selector)) ?: array() as $compound ) {
        $steps[] = str_starts_with($compound, '.') ? '*[' . $classTest(substr($compound, 1)) . ']' : $compound;
    }
    return '//' . implode('//', $steps);
};

foreach ( $cases as $label => $case ) {
    $result = ( new HtmlTransformer() )->transform($case['html'], array( 'static_css' => $case['css'] ))->toArray();
    $serialized = (string) ( $result['serialized_blocks'] ?? '' );
    $authorCss = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'author-css' === ( $asset['source'] ?? '' ) ) {
            $authorCss .= (string) ( $asset['content'] ?? '' );
        }
    }

    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8"?><body>' . preg_replace('/<!--.*?-->/s', '', $serialized) . '</body>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);
    $link = $xpath->query('//a[@href="/"]')->item(0);

    $assert($link instanceof DOMElement, "{$label}: the home link is kept", $serialized);
    if ( ! $link instanceof DOMElement ) {
        continue;
    }
    $linkClasses = preg_split('/\s+/', trim($link->getAttribute('class'))) ?: array();
    $host = $link->parentNode;
    $hostClasses = $host instanceof DOMElement ? ( preg_split('/\s+/', trim($host->getAttribute('class'))) ?: array() ) : array();

    $assert($host instanceof DOMElement && 'p' === $host->tagName, "{$label}: the link is lowered into a paragraph", $serialized);
    $assert(in_array('blocks-engine-synthetic-paragraph', $hostClasses, true), "{$label}: the paragraph keeps its engine marker", $serialized);
    foreach ( $case['classes'] as $class ) {
        $assert(in_array($class, $linkClasses, true), "{$label}: the link keeps .{$class}", $serialized);
        $assert(0 === $xpath->query('ancestor::*[' . $classTest($class) . ']', $link)->length, "{$label}: no ancestor of the link carries .{$class}", $serialized);
        $assert(! str_contains($serialized, '"className":"' . $class) && ! preg_match('/"className":"[^"]*\s' . preg_quote($class, '/') . '[\s"]/', $serialized), "{$label}: no block attribute carries .{$class}", $serialized);
    }
    foreach ( $case['matches'] as $selector ) {
        $matched = $xpath->query($selectorXPath($selector));
        $assert(1 === $matched->length && $matched->item(0) === $link, "{$label}: {$selector} matches the link, as in the source", $serialized);
    }
    foreach ( $case['unmatched'] as $selector ) {
        $assert(0 === $xpath->query($selectorXPath($selector))->length, "{$label}: {$selector} matches nothing, as in the source", $serialized);
    }
    $assert(1 === preg_match($case['text'], $serialized), "{$label}: the link text is kept", $serialized);

    if ( isset($case['own_rule']) ) {
        // The link's own rule reaches it through its kept class...
        $assert(str_contains($authorCss, $case['own_rule']), "{$label}: the link's own rule still ships unchanged", $authorCss);
        // ...and the mapped colour stays on the host, which hands it to the link.
        $hostStyle = $host instanceof DOMElement ? $host->getAttribute('style') : '';
        $assert(in_array('has-text-color', $hostClasses, true) && str_contains($hostStyle, 'color:' . $case['host_color']), "{$label}: the mapped colour stays on the host for the link to inherit", $serialized);
        // Inherited type is not restated on the host box a second time.
        $assert(! str_contains($hostStyle, 'font-size') && ! str_contains($hostStyle, 'font-weight'), "{$label}: inherited type is not restated on the host", $serialized);
    }

    $validity = ( new BlockValidityValidator() )->validateBlocks($result['blocks'] ?? array());
    $assert('pass' === ( $validity['status'] ?? '' ), "{$label}: the output stays Gutenberg-valid", json_encode($validity) ?: '');
}

if ( $failures > 0 ) {
    fwrite(STDERR, "Lowered link class: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Lowered link class passed: {$passes} assertions\n");
