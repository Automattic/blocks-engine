<?php
declare(strict_types=1);

/**
 * An SVG named through `aria-labelledby` (or described through
 * `aria-describedby`) is still passive artwork. Those attributes are id
 * references with no behavior or paint, so they must not send an otherwise
 * self-contained SVG to the core/html floor, standalone or inside a link.
 *
 * The image alt follows the accessible-name order: the referenced elements'
 * text wins over aria-label and <title>; a reference to an id the document
 * does not contain names nothing, so the next source applies.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message, string $context = '') use (&$failures): void {
    if ($condition) return;
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n" . ('' !== $context ? substr($context, 0, 1600) . "\n" : ''));
};
$transform = static fn (string $body): string => (string) ((new HtmlTransformer())->transform('<!doctype html><html><head><title>t</title></head><body>' . $body . '</body></html>')->toArray()['serialized_blocks'] ?? '');
$path = '<path fill="#242424" d="M0 0h10v10H0z"/>';

// A linked wordmark whose aria-labelledby points at a <desc> without that id:
// the link carries the name, and the SVG is passive artwork.
$linked = $transform('<header><a aria-label="Homepage" href="https://example.com/"><svg width="719" height="160" fill="none" aria-labelledby="wordmark-desc" viewBox="0 0 719 160"><desc>Wordmark</desc>' . $path . '</svg></a></header><p>Body</p>');
$assert(!str_contains($linked, '<!-- wp:html'), 'a linked SVG named by aria-labelledby emits no core/html', $linked);
$assert(1 === preg_match('/<a aria-label="Homepage" href="https:\/\/example\.com\/"><img src="[^"]+\.svg" alt=""/', $linked), 'the link keeps its name and wraps the materialized image; a dangling reference adds no alt', $linked);

// A resolved reference names the image, ahead of aria-label and <title>.
$named = $transform('<div><span id="mark-title" hidden>Acme</span><svg aria-labelledby="mark-title mark-sub" aria-label="ignored" viewBox="0 0 10 10"><desc id="mark-sub">  Logo   mark </desc>' . $path . '</svg></div>');
$assert(!str_contains($named, '<!-- wp:html'), 'a standalone SVG named by aria-labelledby emits no core/html', $named);
$assert(str_contains($named, '<!-- wp:image') && str_contains($named, 'alt="Acme Logo mark"'), 'the referenced text, in order and whitespace-collapsed, becomes the image alt', $named);

// A dangling reference falls through to aria-label.
$dangling = $transform('<div><svg aria-labelledby="missing" aria-label="Fallback name" viewBox="0 0 10 10">' . $path . '</svg></div>');
$assert(str_contains($dangling, 'alt="Fallback name"'), 'an unresolved reference falls through to aria-label', $dangling);

// aria-describedby is passive too.
$described = $transform('<div><p id="caption">A chart</p><svg aria-describedby="caption" aria-label="Chart" viewBox="0 0 10 10">' . $path . '</svg></div>');
$assert(!str_contains($described, '<!-- wp:html'), 'an SVG described by aria-describedby emits no core/html', $described);

// Behavior still keeps the floor: an event handler is not made passive by an
// accompanying aria reference.
$unsafe = $transform('<div><svg aria-labelledby="x" onclick="go()" viewBox="0 0 10 10"><desc id="x">X</desc>' . $path . '</svg></div>');
$assert(!str_contains($unsafe, '<!-- wp:image'), 'an SVG with an event handler is not materialized as an image', $unsafe);

if (0 !== $failures) exit(1);
echo "svg-aria-labelledby-native-image: ok\n";
