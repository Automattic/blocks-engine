<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$sourceStyles = '@layer utilities{.mt-8{margin-top:calc(var(--spacing)*8)}@media (min-width:48rem){.md\\:mt-16{margin-top:calc(var(--spacing)*16)}}}';
$sourceFooter = '<footer class="mt-8 md:mt-16"><div class="footer-box">Footer content</div></footer>';
$result = ( new HtmlTransformer() )->transform(
	'<style>:root{--spacing:.25rem}' . $sourceStyles . '</style>' . $sourceFooter,
	array('static_css' => '.wp-block-group{margin-top:32px}')
)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = implode("\n", array_map(
	static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
	$result['assets'] ?? array()
));
$assert = static function (bool $condition, string $message, string $detail = ''): void {
	if ( $condition ) return;
	fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? "\n" . $detail : '' ) . "\n");
	exit(1);
};

$assert(1 === preg_match('/blocks-engine-responsive-margin-top-[0-9a-f]{12}/', $markup, $match), 'the responsive margin winner is attached to the source-corresponding group root', $markup);
$marker = $match[0];
$base = ':root .' . $marker . '{margin-top:calc(var(--spacing)*8)}';
$desktop = '@media (min-width:48rem){:root .' . $marker . '{margin-top:calc(var(--spacing)*16)}}';
$assert(str_contains($css, $base), 'support CSS restates the source base value on its block marker', $css);
$assert(str_contains($css, $desktop), 'support CSS retains the source breakpoint and desktop value', $css);
$assert(strpos($css, '.wp-block-group{margin-top:32px}') < strpos($css, $base), 'unlayered source-derived support follows the conflicting unlayered WordPress default', $css);
$assert(! str_contains($base, '!important') && ! str_contains($desktop, '!important'), 'projection preserves normal cascade priority rather than masking the mismatch');

$inline = ( new HtmlTransformer() )->transform('<footer style="margin-top:10px"><p>Inline margin owner</p></footer>')->toArray();
$assert(! str_contains((string) ($inline['serialized_blocks'] ?? ''), 'blocks-engine-responsive-margin-top-'), 'an inline margin owner is not restated as responsive stylesheet support');

$unlayeredSource = '<style>.mt-8{margin-top:32px}@media (min-width:48rem){.md\\:mt-16{margin-top:64px}}</style>' . $sourceFooter;
$unlayered = ( new HtmlTransformer() )->transform($unlayeredSource)->toArray();
$assert(! str_contains((string) ($unlayered['serialized_blocks'] ?? ''), 'blocks-engine-responsive-margin-top-'), 'unlayered author responsive utilities keep their existing stylesheet ownership');

echo json_encode(array(
	'status' => 'passed',
	'marker' => $marker,
	'baseRule' => $base,
	'desktopRule' => $desktop,
	'sourceHtml' => '<!doctype html><html><head><style>*,*::before,*::after{box-sizing:border-box}html,body{margin:0}body{display:flex;flex-direction:column}:root{--spacing:.25rem}' . $sourceStyles . '</style></head><body><div style="height:200px"></div>' . $sourceFooter . '</body></html>',
	'candidateHtml' => '<!doctype html><html><head><style>*,*::before,*::after{box-sizing:border-box}html,body{margin:0}body{display:flex;flex-direction:column}:root{--spacing:.25rem}' . $css . '</style></head><body><div style="height:200px"></div>' . $markup . '</body></html>',
), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
echo "\n";
