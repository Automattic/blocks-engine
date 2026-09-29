<?php
declare(strict_types=1);

// The HTML `<search>` element is a flow-content landmark (WHATWG "the search
// element"; ARIA role `search`), grouped like `<nav>` or `<aside>`. It was not
// a registered flow container, so it reached the terminal unsupported-element
// recorder, which dropped its whole subtree and recorded
// `html_unsupported_element`. It now lowers like the other landmark wrappers:
// children convert to native blocks and, when the wrapper carries presentation,
// it stays a core/group rendered as `<search>` (core/group's `tagName` is a free
// string that save() renders verbatim, and `search` is in core's kses allowlist).

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

$transform = static fn (string $html, string $css = ''): array =>
    ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ))->toArray();

/** @param array<int, array<string, mixed>> $blocks @return array<int, array<string, mixed>> */
$flatten = static function (array $blocks) use (&$flatten): array {
    $flat = array();
    foreach ( $blocks as $block ) {
        $flat[] = $block;
        $flat = array_merge($flat, $flatten(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array()));
    }
    return $flat;
};

$blocksNamed = static fn (array $result, string $name): array => array_values(array_filter(
    $flatten(is_array($result['blocks'] ?? null) ? $result['blocks'] : array()),
    static fn (array $block): bool => $name === ($block['blockName'] ?? '')
));

$unsupported = static fn (array $result): array => array_values(array_filter(
    is_array($result['fallbacks'] ?? null) ? $result['fallbacks'] : array(),
    static fn (array $fallback): bool => 'html_unsupported_element' === ($fallback['diagnostic_code'] ?? '')
));

$searchGroups = static fn (array $result): array => array_values(array_filter(
    $blocksNamed($result, 'core/group'),
    static fn (array $block): bool => 'search' === ($block['attrs']['tagName'] ?? null)
));

// A search landmark wrapping a search form.
$form = $transform(
    '<main><h1>Catalog</h1><search class="site-search"><form role="search" action="/find"><label for="q">Find</label><input id="q" type="search" name="q"><button type="submit">Go</button></form></search></main>',
    '.site-search { padding: 8px; }'
);
$assert(array() === $unsupported($form), 'a <search> wrapping a search form records no unsupported element; got: ' . json_encode(array_column($unsupported($form), 'tag')));
$assert(1 === count($blocksNamed($form, 'core/search')), 'the search form inside <search> becomes a native core/search block');
$assert(1 === count($searchGroups($form)), 'the <search> landmark stays a core/group with tagName "search"');
$assert(str_contains((string) ($form['serialized_blocks'] ?? ''), '<search class="wp-block-group site-search">'), 'the group renders the <search> landmark with its class');

// A search landmark wrapping only a toggle button (a search-overlay trigger).
$toggle = $transform('<main><h1>Catalog</h1><search class="toolbar"><button type="button" aria-label="Search">Search</button></search><p>After</p></main>');
$assert(array() === $unsupported($toggle), 'a <search> wrapping a button records no unsupported element; got: ' . json_encode(array_column($unsupported($toggle), 'tag')));
$assert(1 === count($blocksNamed($toggle, 'core/button')), 'the button inside <search> is preserved as a native core/button');
$assert(1 === count($searchGroups($toggle)), 'the classed <search> wrapper is kept as a search landmark group');

// Arbitrary flow content inside <search> survives as native blocks.
$flow = $transform('<main><search><h2>Search the archive</h2><p>Type a keyword.</p></search></main>');
$flowText = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ($flow['serialized_blocks'] ?? ''))));
$assert(array() === $unsupported($flow), 'a <search> wrapping flow content records no unsupported element');
$assert(str_contains($flowText, 'Search the archive') && str_contains($flowText, 'Type a keyword.'), 'the flow content inside <search> survives; got: ' . $flowText);
$assert(1 === count($searchGroups($flow)), 'a multi-child <search> is kept as a search landmark group');

foreach ( array( 'form' => $form, 'toggle' => $toggle, 'flow' => $flow ) as $label => $result ) {
    $validity = ( new BlockValidityValidator() )->validateBlocks($result['blocks'] ?? array());
    $assert('pass' === ($validity['status'] ?? ''), "the {$label} case produces Gutenberg-valid blocks");
}

if ( $failures > 0 ) {
    fwrite(STDERR, "{$failures} failure(s), {$passes} pass(es)\n");
    exit(1);
}

echo "search-landmark-element: {$passes} passed\n";
