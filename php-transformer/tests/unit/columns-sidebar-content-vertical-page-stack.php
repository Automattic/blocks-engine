<?php
declare(strict_types=1);

/**
 * Regression coverage for sidebar/content names on a vertical page stack.
 *
 * hasSidebarAndContentChildren() promoted an ordinary vertical page stack to
 * core/columns when the container held one child named like a sidebar and
 * another whose class merely contained a `-content` token. Reduced from a
 * site-builder export: the page wrapper div.page holds a header, an empty
 * in-flow `<aside class="aside">` whose only child is position:absolute,
 * seven full-width sections, and a `footer class="full full-content"`. Every
 * child stacks vertically at every viewport, yet the aside counted as a
 * sidebar pane and `...-full-content` as a content pane, so the wrapper
 * became core/columns and every section a ~50px core/column side by side
 * (scrollWidth 1490 at a 1440 viewport).
 *
 * Before (trunk), the reduced page serialized as:
 *
 *   core/columns {"className":"page ...}
 *     core/column (header.full)  -> core/group -> paragraph
 *     core/column (aside.aside)  -> core/group -> group -> empty group
 *     core/column (section.full) -> core/group -> heading, paragraph
 *     core/column (section.full) -> core/group -> heading, paragraph
 *     core/column (footer.full.full-content) -> core/group -> paragraph
 *
 * After the fix it matches the same page without the `-content` token:
 *
 *   core/group {"className":"page ...}
 *     core/group (header) -> group -> paragraph
 *     core/group (aside)  -> group -> empty group
 *     custom/layout-shell (section) -> heading, paragraph
 *     custom/layout-shell (section) -> heading, paragraph
 *     core/group (footer) -> group -> paragraph
 *
 * Two structural signals now gate the name heuristic:
 *
 *  - A sidebar candidate with no in-flow rendered content — empty, or whose
 *    descendants all hang off `display:none` / `position:absolute|fixed`
 *    wrappers — is a positioning context, not a pane.
 *  - The named sidebar/content pair must be the container's whole in-flow
 *    element population (a two-pane shell). A naming pair among many stacked
 *    children is normal-flow stacking; an out-of-flow extra child (a
 *    decorative badge) does not break the shell.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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
 * @return array{top: list<string>, columns: bool, serialized: string, tree: string}
 */
$transform = static function (string $html, string $css = ''): array {
	$result = ( new HtmlTransformer() )->transform($html, '' === $css ? array() : array( 'static_css' => $css ))->toArray();
	$top = array();
	foreach ( $result['blocks'] ?? array() as $block ) {
		$top[] = (string) ($block['blockName'] ?? '');
	}

	$tree = static function (array $blocks) use (&$tree): string {
		$names = array();
		foreach ( $blocks as $block ) {
			$names[] = (string) ($block['blockName'] ?? '') . $tree($block['innerBlocks'] ?? array());
		}

		return '[' . implode(',', $names) . ']';
	};

	$serialized = (string) ($result['serialized_blocks'] ?? '');

	return array(
		'top' => $top,
		'columns' => str_contains($serialized, '<!-- wp:columns'),
		'serialized' => $serialized,
		'tree' => $tree($result['blocks'] ?? array()),
	);
};

$style = <<<'CSS'
.inner{max-width:940px;margin:0 auto}
.aside{max-width:940px;margin:0 auto}
.aside .side{width:200px;position:absolute;top:0}
.full{width:100%}
CSS;

$reducedPage = static function (string $footerClass) use ($style): string {
	return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><title>Reduced</title><style>{$style}</style></head><body>
<div class="page">
<header class="full"><div class="inner"><p>Header text</p></div></header>
<aside class="aside"><div class="side"><div class="inner"></div></div></aside>
<section class="full"><div class="inner"><h2>About</h2><p>First section paragraph.</p></div></section>
<section class="full"><div class="inner"><h2>Services</h2><p>Second section paragraph.</p></div></section>
<footer class="{$footerClass}"><div class="inner"><p>Footer text</p></div></footer>
</div>
</body></html>
HTML;
};

$pair = '<div class="layout"><aside class="sidebar"><p>Nav</p></aside><main class="content"><h1>Title</h1><p>Body</p></main></div>';

// ---------------------------------------------------------------------
// 1. The reduced vertical page stack: a `-content` token on the footer
//    plus an empty sidebar-named aside must not turn div.page into
//    core/columns. The tree must equal the same page without the token.
// ---------------------------------------------------------------------
$withToken = $transform($reducedPage('full full-content'));
$withoutToken = $transform($reducedPage('full'));

$assert(! $withToken['columns'], 'reduced page with full-content footer is not core/columns', 'top=' . implode(',', $withToken['top']));
$assert(array( 'core/group' ) === $withToken['top'], 'reduced page wrapper stays one core/group', 'top=' . implode(',', $withToken['top']));
$assert(! $withoutToken['columns'], 'reduced page control (no token) is not core/columns', 'top=' . implode(',', $withoutToken['top']));
$assert($withToken['tree'] === $withoutToken['tree'], 'the full-content token no longer changes the block tree', 'with=' . $withToken['tree'] . ' without=' . $withoutToken['tree']);
$assert(str_contains($withToken['serialized'], 'Header text') && str_contains($withToken['serialized'], 'Footer text'), 'header and footer content remain in the page', '');

// ---------------------------------------------------------------------
// 2. A sidebar candidate without in-flow rendered content cannot vouch
//    for the sidebar half of a split layout, even beside a main.
// ---------------------------------------------------------------------
$notColumns = array(
	'empty aside beside main' => array(
		'<div class="layout"><aside class="sidebar"></aside><main class="content"><h1>Title</h1><p>Body</p></main></div>',
		'',
	),
	'aside whose only descendants hang off an absolutely positioned wrapper' => array(
		'<div class="layout"><aside class="sidebar"><div class="flyout" style="position:absolute;top:0"><p>Menu</p></div></aside><main class="content"><h1>Title</h1><p>Body</p></main></div>',
		'',
	),
	'aside whose only descendants are hidden' => array(
		'<div class="layout"><aside class="sidebar"><div class="flyout" hidden><p>Menu</p></div></aside><main class="content"><h1>Title</h1><p>Body</p></main></div>',
		'',
	),
);

foreach ( $notColumns as $label => $case ) {
	$out = $transform($case[0], $case[1]);
	$assert(! $out['columns'], $label . ': no sidebar pane content, so the container is not core/columns', 'top=' . implode(',', $out['top']));
}

// ---------------------------------------------------------------------
// 3. Two-pane shells keep the promotion: an in-flow sidebar descendant
//    still counts as pane content, and an out-of-flow extra child (a
//    decorative badge) does not break the shell.
// ---------------------------------------------------------------------
$stillColumns = array(
	'aside with an in-flow rail descendant beside main' => array(
		'<div class="layout"><aside class="sidebar"><div class="rail"></div></aside><main class="content"><h1>Title</h1><p>Body</p></main></div>',
		'',
	),
	'panes plus an out-of-flow decorative badge' => array(
		'<div class="layout"><aside class="sidebar"><p>Nav</p></aside><main class="content"><h1>Title</h1></main><div class="badge" style="position:absolute;top:0">!</div></div>',
		'',
	),
);

foreach ( $stillColumns as $label => $case ) {
	$out = $transform($case[0], $case[1]);
	$assert($out['columns'], $label . ': two-pane shell stays core/columns', 'top=' . implode(',', $out['top']));
}

// ---------------------------------------------------------------------
// 4. A naming pair among many stacked in-flow children is normal-flow
//    stacking, not a split layout — even when the sidebar really has
//    content. The same pair with an unnamed in-flow spacer between the
//    panes is not a two-pane shell either.
// ---------------------------------------------------------------------
$stackNotColumns = array(
	'vertical page stack: header, sidebar with content, main, section' => array(
		'<div class="page"><header class="top"><p>Brand</p></header><aside class="sidebar"><p>Nav</p></aside><main class="content"><h1>Title</h1><p>Body</p></main><section class="block"><p>More</p></section></div>',
		'',
	),
	'unnamed in-flow spacer between the named panes' => array(
		'<div class="layout"><aside class="sidebar"><p>Nav</p></aside><div class="spacer"></div><main class="content"><h1>Title</h1></main></div>',
		'',
	),
);

foreach ( $stackNotColumns as $label => $case ) {
	$out = $transform($case[0], $case[1]);
	$assert(! $out['columns'], $label . ': stacked in-flow children stay a group', 'top=' . implode(',', $out['top']));
}

// ---------------------------------------------------------------------
// 5. The plain two-pane shell beside the new declines still promotes.
// ---------------------------------------------------------------------
$out = $transform($pair);
$assert($out['columns'], 'plain aside + main shell is still core/columns', 'top=' . implode(',', $out['top']));
$assert(str_contains($out['serialized'], '<!-- wp:column {"className":"sidebar'), 'the aside is still a column', substr($out['serialized'], 0, 200));

if ( 0 < $failures ) {
	fwrite(STDERR, "Columns sidebar/content vertical page stack contract failed ({$failures} failing, {$passes} passing)\n");
	exit(1);
}

fwrite(STDOUT, "Columns sidebar/content vertical page stack contract passed: {$passes} assertions\n");
