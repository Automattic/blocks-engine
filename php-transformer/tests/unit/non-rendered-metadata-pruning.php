<?php
declare(strict_types=1);

/**
 * `<link>`, `<meta>`, `<base>` and `<title>` are metadata content: the UA
 * stylesheet gives them no rendered box and they belong in `<head>`, but
 * browsers tolerate them anywhere in `<body>`. Static captures occasionally
 * leak them into content (a duplicated RSS `<link rel="alternate">` inside a
 * template partial, a stray `<meta>` from a widget include).
 *
 * Left in place the raw tags were never excluded from RichText content, so a
 * paragraph's saved markup carried tags the site owner never authored.
 * HtmlCompilation::pruneNonRenderedMetadataElements() removes them before any
 * element is dispatched to a converter. Exercised here through the full
 * HtmlTransformer because the prune runs once, on the source document.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assertions = 0;
$failures   = array();
$assert     = static function (bool $condition, string $label, string $detail = '') use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();

$names = static function (array $blocks): array {
    $collected = array();
    $walk = static function (array $blocks) use (&$walk, &$collected): void {
        foreach ( $blocks as $block ) {
            $collected[] = $block['blockName'] ?? '';
            $walk(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array());
        }
    };
    $walk($blocks);
    return $collected;
};

$content = static function (array $blocks): string {
    $collected = array();
    $walk = static function (array $blocks) use (&$walk, &$collected): void {
        foreach ( $blocks as $block ) {
            if ( is_string($block['attrs']['content'] ?? null) ) {
                $collected[] = $block['attrs']['content'];
            }
            $walk(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array());
        }
    };
    $walk($blocks);
    return implode('', $collected);
};

// A `<link>` sitting beside plain text no longer blocks the paragraph from
// converting natively, and its raw tag no longer leaks into the saved content.
$link = $transform('<p class="blog-feed-link"><link href="" rel="alternate" type="application/rss+xml" title="RSS">Subscribe</p>');
$assert(array( 'core/paragraph' ) === $names($link['blocks'] ?? array()), 'link-beside-text-converts-to-native-paragraph');
$assert('Subscribe' === $content($link['blocks'] ?? array()), 'link-does-not-leak-into-paragraph-content');
$assert(array() === ($link['fallbacks'] ?? array()), 'link-prune-records-no-fallback');

// A paragraph that is only a `<link>`, with no other content, converts to an
// empty paragraph rather than a core/html island carrying nothing useful.
$linkOnly = $transform('<p class="blog-feed-link"><link href="" rel="alternate" type="application/rss+xml" title="RSS"></p>');
$assert(array( 'core/paragraph' ) === $names($linkOnly['blocks'] ?? array()), 'link-only-paragraph-converts-natively');
$assert('' === $content($linkOnly['blocks'] ?? array()), 'link-only-paragraph-has-no-leaked-markup');

// `<meta>`, `<base>` and `<title>` are pruned the same way.
$meta = $transform('<div><meta charset="utf-8"><p>Real content</p></div>');
$assert(false === str_contains($content($meta['blocks'] ?? array()), '<meta'), 'meta-does-not-leak-into-content');
$assert(in_array('core/paragraph', $names($meta['blocks'] ?? array()), true), 'meta-sibling-paragraph-still-converts');

$base = $transform('<div><base href="/"><p>Real content</p></div>');
$assert(false === str_contains($content($base['blocks'] ?? array()), '<base'), 'base-does-not-leak-into-content');

$title = $transform('<div><title>Leaked Page Title</title><p>Real content</p></div>');
$assert(false === str_contains($content($title['blocks'] ?? array()), 'Leaked Page Title'), 'title-text-is-removed-not-surfaced-as-body-copy');
$assert(in_array('core/paragraph', $names($title['blocks'] ?? array()), true), 'title-sibling-paragraph-still-converts');

// A `<link rel="stylesheet">` carries real behavior — it is not metadata noise
// — so it is not pruned.
$stylesheet = $transform('<p><link rel="stylesheet" href="/theme.css">Styled</p>');
$assert(str_contains($content($stylesheet['blocks'] ?? array()), '<link'), 'stylesheet-link-is-not-pruned');

// A `<link>` inside `<svg><defs>` is a recognized, safety-checked external
// stylesheet reference elsewhere in this class; it is not pruned either.
$svgDefsLink = '<svg><defs><link rel="stylesheet" href="/svg-theme.css"></defs><circle r="5"></circle></svg>';
$svgResult = $transform('<p>' . $svgDefsLink . '</p>');
$assert(array() !== ($svgResult['blocks'] ?? array()), 'svg-defs-link-paragraph-still-produces-a-block');

// `<style>` is deliberately excluded from pruning: unlike the other metadata
// tags, its rules apply wherever it sits in the DOM.
$style = $transform('<div><style>.x{color:red}</style><p class="x">Styled text</p></div>');
$assert(str_contains($content($style['blocks'] ?? array()), 'Styled text'), 'style-sibling-paragraph-content-preserved');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Non-rendered metadata pruning tests: ' . $assertions . " passed\n";
