<?php
declare(strict_types=1);

/**
 * An authored control's link resolves in its source attributes too.
 *
 * A captured dialog trigger that is an `<a href>` converts to an authored
 * button whose save() renders every source attribute from the
 * `sourceAttributes` name/value pairs. Route resolution rewrites the saved
 * `href` to the WordPress route, so the pair must carry the same value;
 * otherwise the editor re-runs save() with the stale source path and reports
 * the block invalid. A link that names no artifact page is neutralized in
 * both places too.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $condition, string $message, string $context = '') use (&$failures): void {
	if ($condition) return;
	++$failures;
	fwrite(STDERR, "FAIL: {$message}\n" . ('' !== $context ? substr($context, 0, 1600) . "\n" : ''));
};

$page = static function (string $href): string {
	$trigger = '<a class="cart" href="' . $href . '" role="button" aria-label="Cart" data-blocks-engine-capture-dialog-trigger="data-blocks-engine-capture-dialog-0" aria-controls="data-blocks-engine-capture-dialog-0" aria-haspopup="dialog" aria-expanded="false"><svg width="24" height="24" viewBox="0 0 24 24"><path d="M3 3h18v18H3z"/></svg></a>';
	$panel = '<div class="data-blocks-engine-capture-dialog" role="dialog" hidden id="data-blocks-engine-capture-dialog-0" data-blocks-engine-capture-dialog-panel="data-blocks-engine-capture-dialog-0" aria-label="Cart"><p>Empty</p></div>';
	return '<main><h1>Home</h1><div class="actions">' . $trigger . '</div>' . $panel . '</main>';
};

$control = static function (string $href) use ($page): array {
	$result = (new ArtifactCompiler())->compile(array(
		'entrypoint' => 'website/index.html',
		'files' => array(
			'website/index.html' => $page($href),
			'website/cart/index.html' => '<main><h1>Cart</h1></main>',
		),
	))->toArray();
	foreach ($result['source_reports']['wordpress_site_plan']['pages'] ?? array() as $plan) {
		if ('website/index.html' !== ($plan['source_path'] ?? null)) continue;
		$markup = (string) ($plan['canonical_block_markup'] ?? '');
		if (!preg_match('~<!-- wp:custom/authored-button (\{.*?\}) -->(.*?)<!-- /wp:custom/authored-button -->~s', $markup, $match)) return array(null, null, $markup);
		$pairs = array_column(json_decode($match[1], true)['sourceAttributes'] ?? array(), 'value', 'name');
		$saved = preg_match('~^<a\b[^>]*\shref="([^"]*)"~', $match[2], $href) ? html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
		return array($pairs['href'] ?? null, $saved, $markup);
	}
	return array(null, null, '');
};

// A source document path resolves to the page route in the pair and the markup.
[$pair, $saved, $markup] = $control('/cart/index.html');
$assert('/cart' === $saved, 'the saved control links to the cart route', $markup);
$assert($saved === $pair, 'the source attribute pair carries the same resolved link save() renders', $markup);

// A document-relative link to no artifact page is neutralized in both places.
[$pair, $saved, $markup] = $control('missing.html');
$assert('#' === $saved, 'the saved control neutralizes the unresolved link', $markup);
$assert($saved === $pair, 'the source attribute pair carries the same neutralized link', $markup);

if ($failures > 0) exit(1);
echo "Authored control source attribute route link passed\n";
