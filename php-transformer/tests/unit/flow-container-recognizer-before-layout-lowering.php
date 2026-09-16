<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
	if ( $condition ) {
		++$passes;
		return;
	}
	$failures[] = 'FAIL: ' . $message . ( '' !== $detail ? ' [' . $detail . ']' : '' );
};

/** @return array<string, int> block-name histogram over the whole tree */
$histogram = static function (array $blocks): array {
	$names = array();
	$walk = static function (array $inner) use (&$walk, &$names): void {
		foreach ( $inner as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			$names[ $name ] = ( $names[ $name ] ?? 0 ) + 1;
			$walk( $block['innerBlocks'] ?? array() );
		}
	};
	$walk( $blocks );
	return $names;
};

$accordionItems = <<<HTML
<div data-orientation="vertical">
	<div><h3><button aria-expanded="false" aria-controls="a">Q1</button></h3><div id="a" role="region"><p>A1</p></div></div>
	<div><h3><button aria-expanded="false" aria-controls="b">Q2</button></h3><div id="b" role="region"><p>A2</p></div></div>
</div>
HTML;
$accordionHtml = static fn (string $wrapperAttributes): string =>
	'<div class="grid"><div ' . $wrapperAttributes . '>' . substr($accordionItems, strlen('<div data-orientation="vertical">'), -strlen('</div>')) . '</div></div>';

$assertAccordionIntact = static function (array $histogram, string $message) use ($assert): void {
	$assert(1 === ( $histogram['core/accordion'] ?? 0 ), $message . ': exactly one core/accordion', json_encode($histogram));
	$assert(2 === ( $histogram['core/accordion-item'] ?? 0 ), $message . ': two accordion items', json_encode($histogram));
	$assert(2 === ( $histogram['core/accordion-heading'] ?? 0 ), $message . ': two accordion headings', json_encode($histogram));
	$assert(2 === ( $histogram['core/accordion-panel'] ?? 0 ), $message . ': two accordion panels', json_encode($histogram));
};

// 1. An accordion whose wrapper is a direct child of a CSS-owned grid parent
// must reach its recognizer before the CSS-owned layout lowering.
$grid = ( new HtmlTransformer() )->transform(
	$accordionHtml('data-orientation="vertical"'),
	array( 'static_css' => '.grid{display:grid}' )
)->toArray();
$assertAccordionIntact($histogram($grid['blocks']), 'accordion under display:grid parent');
$assert('pass' === ( $grid['source_reports']['wp_block_validity']['status'] ?? null ), 'accordion under grid parent serializes editor-valid');

// The same must hold for flex parents, which trigger the same CSS-owned
// layout classification, and for a wrapper that carries a role attribute
// (the earlier role short-circuit shares the layout branch's defect).
$flex = ( new HtmlTransformer() )->transform(
	$accordionHtml('data-orientation="vertical"'),
	array( 'static_css' => '.grid{display:flex}' )
)->toArray();
$assertAccordionIntact($histogram($flex['blocks']), 'accordion under display:flex parent');

$roleBearing = ( new HtmlTransformer() )->transform(
	$accordionHtml('role="region" data-orientation="vertical"'),
	array( 'static_css' => '.grid{display:grid}' )
)->toArray();
$assertAccordionIntact($histogram($roleBearing['blocks']), 'role-bearing accordion wrapper under grid parent');

// 2. Control: with no parent layout CSS the recognizer already claimed the
// wrapper; the fix must not change that outcome.
$unstyled = ( new HtmlTransformer() )->transform($accordionHtml('data-orientation="vertical"'))->toArray();
$assertAccordionIntact($histogram($unstyled['blocks']), 'accordion without parent layout CSS');

// 3. The layout short-circuit still owns elements no recognizer claims: a
// plain grid child keeps lowering to the CSS-owned layout group.
$plain = ( new HtmlTransformer() )->transform(
	'<div class="grid"><div class="card"><p>Card text</p></div><div class="card"><p>More text</p></div></div>',
	array( 'static_css' => '.grid{display:grid}' )
)->toArray();
$plainHist = $histogram($plain['blocks']);
$top = $plain['blocks'][0] ?? array();
$assert('core/group' === ( $top['blockName'] ?? null ), 'plain grid parent stays a group', (string) ( $top['blockName'] ?? '' ));
$assert(str_contains((string) ( $top['attrs']['className'] ?? '' ), 'blocks-engine-css-owned-grid'), 'grid parent keeps its CSS-owned grid carrier', (string) ( $top['attrs']['className'] ?? '' ));
$assert(2 === count(array_filter($top['innerBlocks'] ?? array(), static fn (array $block): bool => 'core/group' === ( $block['blockName'] ?? '' ) && str_contains((string) ( $block['attrs']['className'] ?? '' ), 'blocks-engine-css-owned-layout'))), 'both plain grid children lower to CSS-owned layout groups', json_encode($plainHist));
$assert(! isset( $plainHist['core/accordion'] ) && ! isset( $plainHist['core/details'] ), 'plain layout children claim no interactive widget');
$assert('pass' === ( $plain['source_reports']['wp_block_validity']['status'] ?? null ), 'plain grid lowering serializes editor-valid');

if ( $failures ) {
	fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
	exit(1);
}

echo 'Flow container recognizer-before-layout-lowering tests: ' . $passes . " passed\n";
