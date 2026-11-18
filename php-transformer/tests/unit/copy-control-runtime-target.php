<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

// Two copy rows whose readonly inputs are filled by a script at runtime: the
// source carries no copy payload, but each button still converts normally.
$result = ( new HtmlTransformer() )->transform(
    '<main><article class="card"><label>First value</label><div class="row"><input id="first-value" readonly><button>Copy</button></div>'
    . '<label>Second value</label><div class="row"><input id="second-value" readonly><button>Copy</button></div></article></main>'
)->toArray();

$isLoss = static fn (string $key): callable => static fn (mixed $row): bool => is_array($row) && 'interactive_control_behavior_lost' === ($row[$key] ?? '');

$fallbackLosses = array_filter($result['fallbacks'] ?? array(), $isLoss('diagnostic_code'));
$assert(array() === $fallbackLosses, 'a copy control with a runtime-filled target is not recorded as an unsupported fallback');

$diagnosticLosses = array_values(array_filter($result['diagnostics'] ?? array(), $isLoss('code')));
$assert(2 === count($diagnosticLosses), 'each unresolved copy control reports its lost behavior through the diagnostics channel');
$assert('warning' === ($diagnosticLosses[0]['severity'] ?? ''), 'the lost copy behavior is reported as a warning');
$assert(str_contains((string) ($diagnosticLosses[0]['selector'] ?? ''), 'button'), 'the diagnostic identifies the copy control');

$serialized = (string) ($result['serialized_blocks'] ?? '');
$assert(2 === substr_count($serialized, '<button'), 'both copy buttons are still reproduced');
$assert(! str_contains($serialized, '<!-- wp:html'), 'nothing falls back to raw HTML');

echo "copy-control-runtime-target: ok\n";
