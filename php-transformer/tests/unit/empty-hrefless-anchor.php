<?php
declare(strict_types=1);

/**
 * An empty, non-navigating anchor (no href, no id/name, no children, no
 * text) renders nothing and goes nowhere. Like the empty inline scaffolding
 * beside it (the empty `<span>` a widget usually leaves next to it), it is
 * not an unsupported-element loss: it emits no html_unsupported_element
 * diagnostic and no core/html. Anchors that do carry an href, id or name
 * keep their own handling (empty-destination-anchor,
 * empty-named-fragment-target).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$failures = 0;
$passes   = 0;
$assert   = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
	if ( $condition ) {
		++$passes;
		return;
	}
	++$failures;
	fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$transformer = new HtmlTransformer();
$validity    = new BlockValidityValidator();

// The widget scaffolding from the report: an empty span beside an empty
// anchor, both after a runtime iframe, inside a div.
$widget = $transformer->transform(
	'<div><iframe class="post-likes-widget jetpack-likes-widget" src="https://widgets.wp.com/likes/#blog=1&amp;post=2" scrolling="no" height="22px" width="100%" title="Like this post"></iframe><span class="sd-text-color"></span><a class="sd-link-color"></a></div>'
)->toArray();
$widgetMarkup = (string) ( $widget['serialized_blocks'] ?? '' );
$unsupported  = array_values(
	array_filter(
		$widget['fallbacks'] ?? array(),
		static fn (array $finding): bool => 'html_unsupported_element' === ( $finding['diagnostic_code'] ?? '' )
	)
);
$assert(array() === $unsupported, '1: empty anchor records no unsupported-element diagnostic', json_encode($widget['fallbacks'] ?? array()));
$assert(
	! in_array('a', array_column($widget['fallbacks'] ?? array(), 'tag'), true),
	'2: no fallback finding addresses the anchor at all',
	json_encode($widget['fallbacks'] ?? array())
);
$assert(! str_contains($widgetMarkup, '<!-- wp:html'), '3: empty anchor does not emit core/html', $widgetMarkup);
$assert(! str_contains($widgetMarkup, 'sd-link-color'), '4: the empty anchor itself is not carried into output', $widgetMarkup);
$assert(
	'pass' === ( $validity->validateBlocks($widget['blocks'] ?? array())['status'] ?? '' ),
	'5: output remains Gutenberg-valid',
	json_encode($validity->validateBlocks($widget['blocks'] ?? array()))
);

// A sibling with content must keep converting normally around the drop.
$withNeighbour = $transformer->transform(
	'<div><a class="sd-link-color"></a><p>Real content</p></div>'
)->toArray();
$withNeighbourMarkup = (string) ( $withNeighbour['serialized_blocks'] ?? '' );
$assert(
	array() === array_filter(
		$withNeighbour['fallbacks'] ?? array(),
		static fn (array $finding): bool => 'a' === ( $finding['tag'] ?? '' )
	),
	'6: anchor drop leaves no finding when a sibling carries content',
	json_encode($withNeighbour['fallbacks'] ?? array())
);
$assert(str_contains($withNeighbourMarkup, 'Real content'), '7: sibling content still converts', $withNeighbourMarkup);

// Anchors with a destination, a name or an id are not this shape and keep
// their existing handling: a native link, or a carried fragment target.
$destination = $transformer->transform('<main><a class="blank" href="https://example.com/plant"></a></main>')->toArray();
$assert(
	array() === ( $destination['fallbacks'] ?? array() )
		&& str_contains((string) ( $destination['serialized_blocks'] ?? '' ), 'href="https://example.com/plant"'),
	'8: empty destination-bearing anchor stays a native link',
	json_encode($destination['fallbacks'] ?? array())
);

$named = $transformer->transform('<main><a name="comments" id="comments"></a><p><a href="#comments">0 Comments</a></p></main>')->toArray();
$assert(
	array() === ( $named['fallbacks'] ?? array() )
		&& str_contains((string) ( $named['serialized_blocks'] ?? '' ), 'id="comments"'),
	'9: empty named fragment target keeps its identifier',
	json_encode($named['fallbacks'] ?? array())
);

// Text, children, href, name or id each take the anchor out of the drop.
$textAnchor = $transformer->transform('<div><a class="sd-link-color">Label</a></div>')->toArray();
$assert(
	array() === array_filter(
		$textAnchor['fallbacks'] ?? array(),
		static fn (array $finding): bool => 'html_unsupported_element' === ( $finding['diagnostic_code'] ?? '' ) && 'a' === ( $finding['tag'] ?? '' )
	) && str_contains((string) ( $textAnchor['serialized_blocks'] ?? '' ), 'Label'),
	'10: an anchor with text is not dropped',
	json_encode($textAnchor['fallbacks'] ?? array())
);

if ( $failures > 0 ) {
	fwrite(STDERR, PHP_EOL . "empty href-less anchor tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
	exit(1);
}
fwrite(STDOUT, "OK: empty href-less anchors ({$passes} assertions)" . PHP_EOL);
