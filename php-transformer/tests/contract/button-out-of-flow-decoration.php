<?php
declare(strict_types=1);

/**
 * A button's absolutely positioned, text-free decoration layer (a hover fill
 * drawn by an inline SVG inside `position:absolute; inset:0` wrappers) must not
 * take label flow space. Block-level wrappers are unwrapped from the
 * core/button label, so the materialized SVG image has to keep the wrapper's
 * out-of-flow box resolved from the author cascade; otherwise it sits next to
 * the label, widens the button and wraps the text.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$source = (string) file_get_contents(dirname(__DIR__) . '/fixtures/button-out-of-flow-decoration.html');
$result = ( new HtmlTransformer() )->transform($source)->toArray();
$markup = (string) ( $result['serialized_blocks'] ?? '' );
$css = implode("\n", array_column(
    array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ( $asset['kind'] ?? null )),
    'content'
));

$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="utf-8" ?><html><body>' . $markup . '</body></html>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
$xpath = new DOMXPath($document);

// 1. The page keeps an editable core/button whose label is `Work`.
$assert(1 === substr_count($markup, '<!-- wp:button '), 'the link lowers to one core/button', $markup);
$link = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " wp-block-button ")]/a[@href="/work/"]')->item(0);
$assert($link instanceof DOMElement, 'the core/button link keeps its destination', $markup);
$assert($link instanceof DOMElement && 'Work' === trim($link->textContent ?? ''), 'the button label is the editable text Work', $markup);

// 2. No inline-svg image sits in label flow: any materialized decoration
// carries an out-of-flow position and the source inset box.
$images = $xpath->query('//img[contains(@src,"inline-svg-")]');
foreach ( $images as $image ) {
    $style = strtolower(preg_replace('/\s+/', '', $image instanceof DOMElement ? $image->getAttribute('style') : '') ?? '');
    $assert(1 === preg_match('/(?:^|;)position:(?:absolute|fixed)(?:;|$)/', $style), 'the decoration image is out of flow', $style);
    foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
        $assert(1 === preg_match('/(?:^|;)' . $side . ':0(?:px)?(?:;|$)/', $style), 'the decoration image keeps the source inset ' . $side, $style);
    }
    $assert(str_contains($style, 'pointer-events:none'), 'the decoration stays non-interactive', $style);
}
// The decoration is kept, not discarded, and its containing block stays positioned.
$assert(1 === $images->length, 'the decoration image is kept once', $markup);
$assert(1 === preg_match('/blocks-engine-control-[a-f0-9]+-0\):not\(\.be-c-0\):where\(\.wp-block-buttons\)\{[^}]*position:relative/', $css), 'the button keeps position:relative as the containing block', $css);
$assert('pass' === ( $result['source_reports']['wp_block_validity']['status'] ?? '' ), 'the output stays editor-valid');

// Text-bearing and in-flow wrappers are unaffected: an in-flow icon stays in flow.
$inFlow = ( new HtmlTransformer() )->transform(str_replace('.btn-fill{position:absolute;inset:0;', '.btn-fill{', $source))->toArray();
$assert(! str_contains((string) ( $inFlow['serialized_blocks'] ?? '' ), 'position:absolute'), 'an in-flow decoration is not repositioned', (string) ( $inFlow['serialized_blocks'] ?? '' ));

if ( 0 < $failures ) {
    fwrite(STDERR, "button out-of-flow decoration FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "button out-of-flow decoration passed: {$passes} assertions\n";
