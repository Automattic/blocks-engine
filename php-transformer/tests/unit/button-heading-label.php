<?php
declare(strict_types=1);

/**
 * A <button> whose label is a heading (button > div > h3 or button > h3) keeps
 * that heading as a core/heading inside one button container.
 *
 * core/button stores its label as phrasing RichText, so lowering a
 * heading-labelled control erased the heading to plain button text: the
 * heading's level, authored typography and color were lost and the theme's
 * element-button paint applied over the source's transparent background. Real
 * FAQ disclosure toggles (<button><div><h3>Question?</h3></div>…) imported as
 * white-on-white text while the import report saw no content loss, because the
 * string was still in the markup. This mirrors the linked-container path that
 * already keeps an <a>-wrapped heading (#3150): one layout-shell wrapper with
 * the button's own tag, type and ARIA attributes around the editable inner
 * blocks. A plain-text button still lowers to core/button.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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

$label = 'Will I own the site?';
$css   = '<style>.q{display:flex;width:100%;background:none;border:0;padding:12px 0}.q h3{font-size:24px;color:#030712;margin:0}</style>';
$icon  = '<svg role="presentation" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6" stroke="#030712" fill="none"/></svg>';

$cases = array(
	'button > div > h3 with icon' => '<section><button class="q" type="button"><div><h3>' . $label . '</h3></div><div>' . $icon . '</div></button></section>',
	'button > h3'                 => '<section><button class="q" type="button"><h3>' . $label . '</h3></button></section>',
);

$blockNames = static function (array $blocks) use (&$blockNames): array {
	$names = array();
	foreach ( $blocks as $block ) {
		if ( ! empty($block['blockName']) ) {
			$names[] = $block['blockName'];
		}
		if ( ! empty($block['innerBlocks']) ) {
			$names = array_merge($names, $blockNames($block['innerBlocks']));
		}
	}

	return $names;
};

foreach ( $cases as $case => $html ) {
	$result = ( new HtmlTransformer() )->transform($css . $html)->toArray();
	$markup = (string) ( $result['serialized_blocks'] ?? '' );

	$names = $blockNames($result['blocks'] ?? array());
	$assert(
		! in_array('core/button', $names, true) && ! in_array('core/buttons', $names, true),
		$case . ': no core/button carries the heading text',
		$markup
	);
	$assert(
		in_array('core/heading', $names, true),
		$case . ': the label stays a core/heading block',
		$markup
	);
	$assert(
		str_contains($markup, '<!-- wp:heading {"level":3} -->'),
		$case . ': the heading keeps its source level',
		$markup
	);

	$dom = new DOMDocument();
	@$dom->loadHTML($markup);
	$xpath = new DOMXPath($dom);
	$assert(
		1 === $xpath->query('//button[contains(@class,"q")]//h3[normalize-space()="' . $label . '"]')->length,
		$case . ': an <h3> holding the label sits inside a <button> element',
		$markup
	);
	$assert(
		1 === $xpath->query('//button[contains(@class,"q")][@type="button"]')->length,
		$case . ': the control container keeps its source tag and type attribute',
		$markup
	);

	$stylesheet = '';
	foreach ( ( $result['assets'] ?? array() ) as $asset ) {
		if ( 'css' === ( $asset['kind'] ?? '' ) ) {
			$stylesheet .= "\n" . (string) ( $asset['content'] ?? '' );
		}
	}
	$assert(
		str_contains($stylesheet, '.q h3{font-size:24px;color:#030712'),
		$case . ': the heading typography and color rule still targets the kept heading',
		$stylesheet
	);
	$assert(
		'pass' === ( $result['source_reports']['wp_block_validity']['status'] ?? null ),
		$case . ': the output keeps canonical block validity',
		$markup
	);
}

// The linked-card control from #3150 is unchanged: the anchor path keeps the
// heading through the same layout-shell wrapper.
$control = ( new HtmlTransformer() )->transform(
	$css . '<section><a class="q" href="#faq"><div><h3>' . $label . '</h3></div><div>' . $icon . '</div></a></section>'
)->toArray();
$controlMarkup = (string) ( $control['serialized_blocks'] ?? '' );
$controlNames  = $blockNames($control['blocks'] ?? array());
$assert(
	in_array('core/heading', $controlNames, true) && ! in_array('core/button', $controlNames, true),
	'control a > div > h3: the anchor keeps its heading and does not become a button',
	$controlMarkup
);
$controlDom = new DOMDocument();
@$controlDom->loadHTML($controlMarkup);
$controlXpath = new DOMXPath($controlDom);
$assert(
	1 === $controlXpath->query('//a[contains(@class,"q")][@href="#faq"]//h3[normalize-space()="' . $label . '"]')->length,
	'control a > div > h3: one anchor wraps the kept heading',
	$controlMarkup
);

// A plain-text button still lowers to core/button.
$plain = ( new HtmlTransformer() )->transform(
	$css . '<section><button class="q" type="button">Save changes</button></section>'
)->toArray();
$plainMarkup = (string) ( $plain['serialized_blocks'] ?? '' );
$assert(
	str_contains($plainMarkup, '<!-- wp:button '),
	'a plain-text button still lowers to core/button',
	$plainMarkup
);
$plainDom = new DOMDocument();
@$plainDom->loadHTML($plainMarkup);
$assert(
	1 === $plainDom->getElementsByTagName('button')->length,
	'the plain-text button still saves a native button element',
	$plainMarkup
);

if ( $failures > 0 ) {
	fwrite(STDERR, "button-heading-label tests: {$failures} failed, {$passes} passed\n");
	exit(1);
}

echo 'button-heading-label tests: ' . $passes . " passed\n";
