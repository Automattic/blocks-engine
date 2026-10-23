<?php
declare(strict_types=1);

/** Control and label metadata must not discard authored utility-class tails. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};

$html = file_get_contents(dirname(__DIR__) . '/fixtures/form-control-class-tail.html');
$expected = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 shadow h-9 px-4 w-full py-6 text-base bg-blue-600 hover:bg-blue-700 text-white';
$assert(22 === count(explode(' ', $expected)), 'the reduced submit has 22 valid tokens with colour and hover classes last');

$result = ( new HtmlTransformer() )->transform($html)->toArray();
$fallbacks = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? null)));
$controls = array_column($fallbacks[0]['controls'] ?? array(), null, 'name');
$assert($expected === ($controls['send']['class'] ?? null), 'submit class metadata retains every authored token');
$assert($expected === ($controls['email']['label_class'] ?? null), 'associated label_class retains every authored token');
$assert($expected === ($controls['send']['label_classes'] ?? null), 'submit label_classes retains every authored token');

// Removing the positional bound must not relax the existing token validation.
$html = str_replace('inline-flex ', 'bad/token ' . str_repeat('x', 81) . ' inline-flex ', $html);
$result = ( new HtmlTransformer() )->transform($html)->toArray();
$fallbacks = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? null)));
$controls = array_column($fallbacks[0]['controls'] ?? array(), null, 'name');
$assert($expected === ($controls['send']['class'] ?? null), 'invalid and overlong tokens remain excluded without losing the valid tail');

fwrite(STDOUT, sprintf('form-control-class-tail: %d failed%s', $failures, PHP_EOL));
exit($failures > 0 ? 1 : 0);
