<?php
declare(strict_types=1);

/**
 * A flex or grid container blockifies its children: each text run becomes an
 * anonymous item whose leading and trailing whitespace is never painted. The
 * editor's RichText is `white-space: pre-wrap`, so a boundary space carried
 * into the serialized rich text is painted in the editor only (#3169).
 * Inline-layout content keeps its whitespace.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$serialize = static fn (string $html): string => (string) ( ( new HtmlTransformer() )->transform($html)->toArray()['serialized_blocks'] ?? '' );
$style = '<style>.trigger{display:inline-flex;align-items:center;gap:4px}.row{display:flex;gap:8px}</style>';
$icon = '<span class="icon">expand_more</span>';

$textThenIcon = $serialize($style . "<a class=\"trigger\" href=\"/programs/\">\n  Our Programs\n  " . $icon . "\n</a>");
$assert(
    1 === preg_match('#<a class="trigger" href="/programs/">Our Programs<mark\b[^>]*>expand_more</mark></a>#', $textThenIcon),
    'an inline-flex anchor serializes text followed by an icon without boundary whitespace',
    $textThenIcon
);

$iconThenText = $serialize($style . "<a class=\"trigger\" href=\"/programs/\">\n  " . $icon . "\n  Our Programs\n</a>");
$assert(
    1 === preg_match('#<a class="trigger" href="/programs/"><mark\b[^>]*>expand_more</mark>Our Programs</a>#', $iconThenText),
    'an inline-flex anchor serializes an icon followed by text without boundary whitespace',
    $iconThenText
);

$inline = $serialize($style . '<a class="plain" href="/programs/">Our Programs ' . $icon . '</a>');
$assert(
    1 === preg_match('#<a class="plain" href="/programs/">Our Programs <mark\b[^>]*>expand_more</mark></a>#', $inline),
    'an inline (non-flex) anchor keeps its single space between text and icon',
    $inline
);

$nested = $serialize($style . "<p>Explore <a class=\"trigger\" href=\"/programs/\">\n  Our Programs\n  " . $icon . "\n</a> today</p>");
$assert(
    1 === preg_match('#<p>Explore <a class="trigger" href="/programs/">Our Programs<mark\b[^>]*>expand_more</mark></a> today</p>#', $nested),
    'inside an inline paragraph only the inline-flex descendant drops its boundary whitespace',
    $nested
);

$flexParagraph = $serialize($style . "<p class=\"row\">\n  Our Programs\n  " . $icon . "\n</p>");
$assert(
    1 === preg_match('#<p class="row">Our Programs<mark\b[^>]*>expand_more</mark></p>#', $flexParagraph),
    'a flex paragraph drops whitespace between its text run and its element child',
    $flexParagraph
);

$button = $serialize('<style>.btn{display:inline-flex;align-items:center;gap:4px;padding:8px 16px;background:#000;color:#fff}</style>'
    . "<div><a class=\"btn\" href=\"/go/\">\n  Get started\n  <span class=\"icon\">arrow_forward</span>\n</a></div>");
$assert(
    1 === preg_match('#wp-block-button__link[^>]*href="/go/">Get started<span class="icon">arrow_forward</span></a>#', $button),
    'an inline-flex button link serializes its label without boundary whitespace',
    $button
);

if ( $failures ) {
    fwrite(STDERR, $failures . " flex-rich-text-boundary-whitespace test(s) failed\n");
    exit(1);
}

echo 'Flex-rich-text-boundary-whitespace tests: ' . $passes . " passed\n";
