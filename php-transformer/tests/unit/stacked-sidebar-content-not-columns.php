<?php
declare(strict_types=1);

/**
 * Regression coverage for a stacked sidebar/content wrapper (Drupal Zen).
 *
 * The page wrapper holds `aside.sidebars` — a full-width block whose inner
 * `section.region-sidebar-first` floats right — and `#content`, a full-width
 * block flowing beside the float. Every child resolves to display:block at
 * the full container width; the only side-by-side geometry is the float
 * inside the aside. The sidebar/content name heuristic turned the wrapper
 * into core/columns, and because flex ignores the float the sidebar
 * rendered on the left and the content on the right: a mirrored page.
 *
 * A sidebar-named child whose rendered content is entirely floated is a
 * float carrier, not a pane, so outside a flex row the wrapper stays a
 * group, children keep source order and the carried float:right on
 * .region-sidebar-first reproduces the source placement. A flex row with
 * the same children is a real side-by-side layout and still yields columns.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ColumnsPattern;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\SourceBlockCreatorFixture;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
	if ( $condition ) {
		++$passes;
		return;
	}

	++$failures;
	fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

/**
 * @return array{top: list<string>, columns: bool, serialized: string, css: string}
 */
$transform = static function (string $html): array {
	$result = ( new HtmlTransformer() )->transform($html)->toArray();
	$top = array();
	foreach ( $result['blocks'] ?? array() as $block ) {
		$top[] = (string) ($block['blockName'] ?? '');
	}

	$css = '';
	array_walk_recursive(
		$result,
		static function ($value, $key) use (&$css): void {
			if ( 'content' === $key && is_string($value) ) {
				$css .= $value . "\n";
			}
		}
	);

	$serialized = (string) ($result['serialized_blocks'] ?? '');

	return array(
		'top' => $top,
		'columns' => str_contains($serialized, '<!-- wp:columns') || str_contains($serialized, '<!-- wp:column '),
		'serialized' => $serialized,
		'css' => $css,
	);
};

$page = static function (bool $navigation): string {
	$nav = $navigation ? "\n<div id=\"navigation\"><a href=\"/\">Home</a></div>" : '';

	return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><title>Seminars</title><style>.page-width{width:1000px;margin:0 220px}.region-sidebar-first{float:right;width:250px}#navigation{position:absolute;left:0;width:100%}</style></head><body>
<div class="page-width"><aside class="sidebars"><section class="region region-sidebar-first column"><h2>News &amp; Events</h2><ul><li><a href="/news/">News</a></li><li><a href="/seminars/">Seminars</a></li></ul></section></aside>{$nav}
<div id="content" class="column"><h1>Seminars</h1><p>Body text.</p></div></div>
</body></html>
HTML;
};

// ---------------------------------------------------------------------
// 1. The stacked wrapper, with and without #navigation, stays a group.
// ---------------------------------------------------------------------
foreach ( array( 'with #navigation' => true, 'without #navigation' => false ) as $label => $navigation ) {
	$out = $transform($page($navigation));
	$serialized = $out['serialized'];

	$assert(! $out['columns'], $label . ': stacked sidebar/content wrapper is not core/columns', substr($serialized, 0, 300));
	$assert(array( 'core/group' ) === $out['top'], $label . ': wrapper stays one core/group', 'top=' . implode(',', $out['top']));

	$aside = strpos($serialized, '<aside');
	$section = strpos($serialized, 'region-sidebar-first');
	$content = strpos($serialized, 'id="content"');
	$assert(false !== $aside && false !== $section && false !== $content && $aside < $section && $section < $content, $label . ': children keep source order (aside, then #content)', substr($serialized, 0, 300));

	if ( $navigation ) {
		$nav = strpos($serialized, '"anchor":"navigation"');
		$assert(false !== $nav && $aside < $nav && $nav < $content, $label . ': #navigation stays between the aside and #content', substr($serialized, 0, 600));
	}

	$assert(str_contains($serialized, 'News &amp; Events') && str_contains($serialized, 'Body text.'), $label . ': sidebar and content text remain', '');
	$assert((bool) preg_match('/\.region-sidebar-first\{[^}]*float:\s*right/', $out['css']), $label . ': float:right on .region-sidebar-first is carried', substr($out['css'], 0, 300));
}

// ---------------------------------------------------------------------
// 2. A real side-by-side flex row with the same children still yields
//    core/columns from the recognizer. (End to end, an author-owned flex or
//    grid wrapper lowers to the CSS-owned layout group before ColumnsPattern
//    runs, so the recognizer is exercised directly with the resolved
//    geometry: the wrapper is display:flex, the inner section floats right.)
//    The same geometry without the flex row declines; a grid row declines
//    too, because core/columns cannot carry grid tracks.
// ---------------------------------------------------------------------
$recognize = static function (bool $navigation, string $wrapperDisplay): ?array {
	$dom = new DOMDocument();
	$nav = $navigation ? '<div id="navigation"><a href="/">Home</a></div>' : '';
	@$dom->loadHTML('<div class="page-width"><aside class="sidebars"><section class="region region-sidebar-first column"><h2>News</h2><ul><li><a href="/news/">News</a></li></ul></section></aside>' . $nav . '<div id="content" class="column"><h1>Seminars</h1><p>Body text.</p></div></div>');
	$wrapper = $dom->getElementsByTagName('div')->item(0);
	if ( ! $wrapper instanceof DOMElement ) {
		return null;
	}

	$style = static function (DOMElement $element) use ($wrapperDisplay): string {
		if ( str_contains(' ' . $element->getAttribute('class') . ' ', ' page-width ') ) {
			return '' === $wrapperDisplay ? 'width:1000px' : 'display:' . $wrapperDisplay . ';width:1000px';
		}
		if ( str_contains(' ' . $element->getAttribute('class') . ' ', ' region-sidebar-first ') ) {
			return 'float:right;width:250px';
		}
		if ( 'navigation' === $element->getAttribute('id') ) {
			return 'position:absolute;left:0;width:100%';
		}

		return '';
	};
	$create = static fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $source = null): array => array(
		'blockName' => $name,
		'attrs' => $attrs,
		'innerBlocks' => $innerBlocks,
		'source' => $source?->getAttribute('id') ?: $source?->getAttribute('class'),
	);
	$fallbacks = array();

	return ( new ColumnsPattern(new SourceBlockCreatorFixture($create)) )->match(
		$wrapper,
		$fallbacks,
		static fn (DOMElement $child): array => array(),
		static fn (DOMElement $child): array => $create('core/group', array(), array(), $child),
		static fn (DOMElement $element): array => array(),
		$style,
		$create
	);
};

foreach ( array( 'with #navigation' => true, 'without #navigation' => false ) as $label => $navigation ) {
	$flex = $recognize($navigation, 'flex');
	$assert('core/columns' === ($flex['blockName'] ?? null), $label . ': sidebar + content in a flex row is core/columns', (string) json_encode($flex));
	$sources = array_map(static fn (array $column): string => (string) $column['source'], $flex['innerBlocks'] ?? array());
	$expected = $navigation ? array( 'sidebars', 'navigation', 'content' ) : array( 'sidebars', 'content' );
	$assert($expected === $sources, $label . ': flex-row columns keep source order', implode(',', $sources));

	$assert(null === $recognize($navigation, ''), $label . ': the same geometry without a flex row is not columns', '');
	$assert(null === $recognize($navigation, 'grid'), $label . ': a grid row stays a group (grid tracks are not columns)', '');
}

// ---------------------------------------------------------------------
// 3. A plain two-pane shell (no float carrier) keeps the promotion.
// ---------------------------------------------------------------------
$out = $transform('<div class="layout"><aside class="sidebar"><p>Nav</p></aside><main class="content"><h1>Title</h1><p>Body</p></main></div>');
$assert(str_contains($out['serialized'], '<!-- wp:columns'), 'plain aside + main shell is still core/columns', substr($out['serialized'], 0, 300));

if ( 0 < $failures ) {
	fwrite(STDERR, "Stacked sidebar/content not-columns contract failed ({$failures} failing, {$passes} passing)\n");
	exit(1);
}

fwrite(STDOUT, "Stacked sidebar/content not-columns contract passed: {$passes} assertions\n");
