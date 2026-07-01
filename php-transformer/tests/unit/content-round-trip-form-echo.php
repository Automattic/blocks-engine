<?php
declare(strict_types=1);

/**
 * End-to-end test for form-control echo suppression in the content round-trip
 * check (step 1: precision tightening). The transformer flattens form controls
 * into readable paragraphs whose text it SYNTHESIZES from label + value/
 * placeholder/required/option state — text that is intentionally absent from
 * the source's visible content. Without suppression the round-trip reporter
 * floods on these; with it, only genuinely invented copy survives.
 *
 * Plain-PHP test script (no PHPUnit) — drives the real HtmlTransformer so it
 * exercises the whole wire: collection in the transformer + exclusion in the
 * reporter.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\ContentRoundTripReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$transformer = new HtmlTransformer();

/**
 * @return array{status: string, texts: array<int, string>}
 */
$roundTrip = static function (string $html) use ($transformer): array {
    $report = $transformer->transform($html, array())->toArray()['source_reports']['content_round_trip'] ?? array();
    $texts = array();
    foreach ( $report['findings'] ?? array() as $finding ) {
        $texts[] = (string) ($finding['text'] ?? '');
    }

    return array( 'status' => (string) ($report['status'] ?? ''), 'texts' => $texts );
};

// ---------------------------------------------------------------------------
// 1. A required text input — placeholder + required are synthesized, NOT visible
//    source text — must not be flagged.
// ---------------------------------------------------------------------------
$html = '<form>'
    . '<label for="email">Email address</label>'
    . '<input id="email" type="email" placeholder="your@email.com" required>'
    . '</form>';
$result = $roundTrip($html);
$assert('pass' === $result['status'], '1: synthesized form-field echo is not flagged', implode(' | ', $result['texts']));

// ---------------------------------------------------------------------------
// 2. A <select> — option labels and the "(selected)" marker are synthesized.
// ---------------------------------------------------------------------------
$html = '<form><label for="topic">Topic</label>'
    . '<select id="topic"><option>General</option><option selected>Billing question</option></select>'
    . '</form>';
$result = $roundTrip($html);
$assert('pass' === $result['status'], '2: synthesized select option echoes are not flagged', implode(' | ', $result['texts']));

// ---------------------------------------------------------------------------
// 3. Data-entry forms preserve the real controls now, so placeholder values stay
//    inside attributes instead of becoming synthesized prose in the block output.
//    The round-trip reporter no longer needs an ignore set to hide placeholder
//    echoes for this path.
// ---------------------------------------------------------------------------
$html = '<form><label for="e2">Email</label><input id="e2" placeholder="you@example.com" required></form>';
$arr = $transformer->transform($html, array())->toArray();
$serialized = (string) ($arr['serialized_blocks'] ?? '');

$result = $roundTrip($html);
$assert('pass' === $result['status'], '3: placeholder echo suppressed in the wired transform', implode(' | ', $result['texts']));

$unsuppressed = ( new ContentRoundTripReporter() )->report($serialized, $html);
$assert('pass' === ($unsuppressed['status'] ?? ''), '3b: preserved form controls do not synthesize placeholder prose without the ignore set');
$unsuppressedText = strtolower(implode(' | ', array_map(static fn (array $f): string => (string) ($f['text'] ?? ''), $unsuppressed['findings'] ?? array())));
$assert(! str_contains($unsuppressedText, 'you@example.com'), '3c: placeholder value remains attribute text, not invented visible prose', $unsuppressedText);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
if ( $failures > 0 ) {
    fwrite(STDERR, sprintf("content-round-trip-form-echo: %d passed, %d FAILED%s", $passes, $failures, PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf("content-round-trip-form-echo: %d assertions passed%s", $passes, PHP_EOL));
