<?php
declare(strict_types=1);

/**
 * A link whose only content is an `<img>` with no image source (no src,
 * srcset or lazy-source attribute) shows no image: the browser renders the
 * image as its alt text, and the link navigates on that text. It converts as
 * that native text link, keeping the destination and the alt text, with no
 * unsupported-element fallback. An image that has a source anywhere keeps its
 * existing linked-image lowering.
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
$convert     = static function (string $html) use ($transformer): array {
	$result = $transformer->transform('<section><div class="venue">' . $html . '</div><p>Details</p></section>')->toArray();
	return array( (string) ( $result['serialized_blocks'] ?? '' ), $result['fallbacks'] ?? array(), $result['blocks'] ?? array() );
};

$cases = array(
	// A map image whose source had not been set when the page was saved.
	'alt text'        => array( '<a href="https://maps.example.com/search?query=48.8566,2.3522" target="_blank"><img id="venue-map" class="map" alt="Venue map" width="640" height="360"></a>', 'Venue map' ),
	'two images'      => array( '<a href="https://example.com/gallery"><img alt="First"> <img alt="Second"></a>', 'First Second' ),
	'collapsed space' => array( '<a href="https://example.com/a"><img alt="  Opening
		hours "></a>', 'Opening hours' ),
	'no alt'          => array( '<a href="https://example.com/a"><img width="640" height="360"></a>', '' ),
);
foreach ( $cases as $label => [ $html, $text ] ) {
	[ $markup, $fallbacks ] = $convert($html);
	preg_match('/href="([^"]+)"/', $html, $href);
	$assert(array() === $fallbacks, "1 ($label): no fallback finding", json_encode($fallbacks));
	$assert(! str_contains($markup, '<!-- wp:html'), "2 ($label): no core/html", $markup);
	$assert(! str_contains($markup, '<img'), "3 ($label): no source-less image is emitted", $markup);
	$assert(
		1 === preg_match('#<!-- wp:paragraph[^>]*-->\s*<p[^>]*><a href="' . preg_quote($href[1], '#') . '"[^>]*>' . preg_quote($text, '#') . '</a></p>#', $markup),
		"4 ($label): native paragraph link carrying the destination and alt text",
		$markup
	);
	$assert(str_contains($markup, 'Details'), "5 ($label): sibling content still converts", $markup);
}

[ , , $blocks ] = $convert($cases['alt text'][0]);
$assert('pass' === ( $validity->validateBlocks($blocks)['status'] ?? '' ), '6: output remains Gutenberg-valid', json_encode($validity->validateBlocks($blocks)));

// An image with a source anywhere is not this shape.
$sourced = array(
	'src'          => array( '<a href="https://example.com/a"><img src="https://example.com/p.png" alt="Photo"></a>', 'p.png' ),
	'lazy src'     => array( '<a href="https://example.com/a"><img data-src="https://example.com/p.png" alt="Photo"></a>', 'p.png' ),
	'srcset'       => array( '<a href="https://example.com/a"><img srcset="https://example.com/p.png 640w" alt="Photo"></a>', 'p.png' ),
);
foreach ( $sourced as $label => [ $html, $asset ] ) {
	[ $markup, $fallbacks ] = $convert($html);
	$assert(array() === $fallbacks && str_contains($markup, $asset) && ( str_contains($markup, '<img') || str_contains($markup, '\\u003cimg') ), "7 ($label): linked image keeps its image", $markup);
}

// A link that also wraps text keeps the image out of this rewrite.
[ $markup ] = $convert('<a href="https://example.com/a"><img alt="Icon">Read more</a>');
$assert(! str_contains($markup, 'IconRead more'), '8: a link with its own text is not given the alt text', $markup);

if ( $failures > 0 ) {
	fwrite(STDERR, PHP_EOL . "linked sourceless image tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
	exit(1);
}
fwrite(STDOUT, "OK: linked sourceless images ({$passes} assertions)" . PHP_EOL);
