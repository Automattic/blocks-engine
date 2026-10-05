<?php
declare(strict_types=1);

$fixture = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/native-button-default-background-fixture.php')), true, 512, JSON_THROW_ON_ERROR);
$assert = static function (bool $condition, string $message) use ($fixture): void {
	if ( ! $condition ) {
		fwrite(STDERR, "FAIL: {$message}\nGenerated CSS:\n{$fixture['css']}\n");
		exit(1);
	}
};
$assert('core/button' === ($fixture['blocks'][0]['innerBlocks'][0]['blockName'] ?? ''), 'neutral inherited-color anchor is promoted to canonical core/button');
$assert(str_contains($fixture['css'], ':not([style*="background"]){background-color:transparent!important}'), 'an unfilled source anchor must neutralize the native button theme fill while allowing owner-authored inline fill');
fwrite(STDOUT, "native button default background tests: passed\n");
