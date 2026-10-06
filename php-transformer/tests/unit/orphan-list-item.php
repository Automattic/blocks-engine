<?php
declare(strict_types=1);

// A list item whose parent is not a list (`<div><li>…</li></div>`) is invalid
// HTML that real pages ship, typically when a framework renders translated or
// conditional rows into a plain wrapper. The list converter only consumes the
// `li` children of a `ul`/`ol`, so such an item used to reach the terminal
// unsupported-element recorder: the subtree was dropped and an
// `html_unsupported_element` finding failed the import quality gate. It has no
// list to belong to, so it now lowers the way a generic `div` does, keeping its
// content as native blocks.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();
$unsupported = static fn (array $result): array => array_values(array_filter(
    is_array($result['fallbacks'] ?? null) ? $result['fallbacks'] : array(),
    static fn (array $fallback): bool => 'html_unsupported_element' === ($fallback['diagnostic_code'] ?? '')
));
$text = static fn (string $html): string => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

$check = static function (string $label, string $html, array $expected) use ($transform, $unsupported, $text, $assert): void {
    $result = $transform($html);
    $serialized = (string) ($result['serialized_blocks'] ?? '');
    $assert(array() === $unsupported($result), "{$label}: no html_unsupported_element fallback; got: " . json_encode(array_column($unsupported($result), 'selector')));
    $assert(! str_contains($serialized, '<!-- wp:html'), "{$label}: no core/html fallback block");
    $assert(str_contains($serialized, '<li'), "{$label}: source list-item roots and their browser marker semantics survive");
    foreach ( $expected as $needle ) {
        $assert(str_contains($text($serialized), $needle), "{$label}: the text \"{$needle}\" survives; got: " . $text($serialized));
    }
    $validity = ( new BlockValidityValidator() )->validateBlocks($result['blocks'] ?? array());
    $assert('pass' === ($validity['status'] ?? ''), "{$label}: the converted blocks are Gutenberg-valid");
};

$check('plain items in a div', '<div><li>First point</li><li>Second point</li></div>', array( 'First point', 'Second point' ));
$check(
    'items with an icon and a span in nested wrappers',
    '<section><div><h3>Heading</h3><div class="rows"><li class="row"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"></path></svg><span>Alpha row</span></li><li class="row"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"></path></svg><span>Beta row</span></li></div></div></section>',
    array( 'Heading', 'Alpha row', 'Beta row' )
);

// A real list keeps its native list semantics.
$list = $transform('<ul><li>Kept</li></ul>');
$assert('core/list' === ($list['blocks'][0]['blockName'] ?? null), 'a list item inside a list still lowers to a native core/list');

// An orphan item that owns runtime behavior keeps the preserved/diagnosed path.
$runtime = $transform('<div><li onclick="toggle()">Interactive</li></div>');
$assert(1 === count($unsupported($runtime)), 'an orphan item with its own event handler is still reported, not silently flattened');

if ( $failures > 0 ) {
    fwrite(STDERR, "{$failures} failure(s), {$passes} pass(es)\n");
    exit(1);
}

echo "orphan-list-item: {$passes} passed\n";
