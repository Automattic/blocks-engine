<?php
declare(strict_types=1);

$fixture = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/native-button-default-background-fixture.php')), true, 512, JSON_THROW_ON_ERROR);
$assert = static function (bool $condition, string $message) use ($fixture): void {
	if ( ! $condition ) {
		fwrite(STDERR, "FAIL: {$message}\nGenerated CSS:\n{$fixture['css']}\n");
		exit(1);
	}
};
$buttons = array();
$collectButtons = static function (array $blocks) use (&$collectButtons, &$buttons): void {
	foreach ($blocks as $block) {
		if ('core/button' === ($block['blockName'] ?? '')) $buttons[] = $block;
		$collectButtons($block['innerBlocks'] ?? array());
	}
};
$collectButtons($fixture['blocks']);
$assert('core/button' === ($buttons[0]['blockName'] ?? ''), 'brand anchor is promoted to canonical core/button');
$assert(str_contains($buttons[0]['attrs']['className'] ?? '', 'blocks-engine-native-button-alignment-start'), 'brand anchor uses the native alignment fallback');
$assert(1 === preg_match('/blocks-engine-native-button-(?!alignment-)[^"\s]+/', $fixture['candidate'], $guardMarker), 'transparent fallback emits a unique per-control marker');
$assert(str_contains($fixture['css'], '.' . $guardMarker[0] . '.' . $guardMarker[0] . '>.wp-block-button__link:not([style*="background"])') && str_contains($fixture['css'], 'background-color:transparent!important'), 'transparent reset is attached only to the brand control marker');
$assert(str_contains($fixture['css'], '.responsive:not(:where(') && str_contains($fixture['css'], ':hover{background-color:rgb(0,170,0)}') && str_contains($fixture['css'], '@media(max-width:600px)'), 'responsive source hover and breakpoint fills stay in projected stylesheet rules');
fwrite(STDOUT, "native button default background tests: passed\n");
