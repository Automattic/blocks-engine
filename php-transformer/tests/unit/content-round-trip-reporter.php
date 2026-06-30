<?php
declare(strict_types=1);

/**
 * Unit tests for the content round-trip / hallucination reporter (#1 ported
 * from the JS blocks-engine `output-verify.ts verifyComposedOutput()`).
 *
 * Plain-PHP test script in the style of tests/unit/css-value-splitter.php — no
 * PHPUnit. The reporter performs a FORWARD-direction parity check: every
 * visible text node in the serialized block output (>=3 alphanumeric chars,
 * normalized) must appear as a substring of the normalized source plaintext.
 * Output text that is not present in the source is "invented" copy and surfaces
 * as a `content_not_in_source` finding. Clean output produces zero findings and
 * a `pass` status.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\ContentRoundTripReporter;

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

$reporter = new ContentRoundTripReporter();

/**
 * @param array<string, mixed> $report
 * @return array<int, string>
 */
$codes = static function (array $report): array {
    $codes = array();
    foreach ( $report['findings'] ?? array() as $finding ) {
        $codes[] = (string) ($finding['code'] ?? '');
    }
    return $codes;
};

// ---------------------------------------------------------------------------
// 1. Faithful output (every text node present in source) -> pass, no findings.
// ---------------------------------------------------------------------------
$source = '<h2>Our Mission</h2><p>We build deterministic tools for the web.</p>';
$output = "<!-- wp:heading -->\n<h2>Our Mission</h2>\n<!-- /wp:heading -->\n"
    . "<!-- wp:paragraph -->\n<p>We build deterministic tools for the web.</p>\n<!-- /wp:paragraph -->";
$report = $reporter->report($output, $source);
$assert('pass' === ($report['status'] ?? ''), '1: faithful output reports pass', (string) ($report['status'] ?? ''));
$assert(array() === ($report['findings'] ?? null), '1b: faithful output has no findings', implode(',', $codes($report)));
$assert('blocks-engine/php-transformer/content-round-trip/v1' === ($report['schema'] ?? ''), '1c: report carries versioned schema');
$assert(ConversionFindingContract::SCHEMA === ($report['finding_schema'] ?? ''), '1d: report advertises the finding schema');

// ---------------------------------------------------------------------------
// 2. Invented copy not in source -> warning + content_not_in_source finding.
// ---------------------------------------------------------------------------
$source = '<h2>Our Mission</h2>';
$output = "<!-- wp:heading -->\n<h2>Our Mission</h2>\n<!-- /wp:heading -->\n"
    . "<!-- wp:paragraph -->\n<p>Subscribe to our newsletter today.</p>\n<!-- /wp:paragraph -->";
$report = $reporter->report($output, $source);
$assert('warning' === ($report['status'] ?? ''), '2: invented copy reports warning', (string) ($report['status'] ?? ''));
$assert(in_array('content_not_in_source', $codes($report), true), '2b: emits content_not_in_source', implode(',', $codes($report)));
$assert(
    'Subscribe to our newsletter today.' === ( ($report['findings'][0]['text'] ?? '') ),
    '2c: finding carries the offending text node',
    (string) ( $report['findings'][0]['text'] ?? '' )
);
$assert(
    isset($report['findings'][0]['reason_code']),
    '2d: finding is classified (reason_code stamped)'
);

// ---------------------------------------------------------------------------
// 3. A standalone text node with <3 alphanumeric chars is ignored, even when
//    it is absent from the source (e.g. a decorative separator glyph).
// ---------------------------------------------------------------------------
$source = '<p>Hello world</p>';
$output = "<!-- wp:paragraph -->\n<p>Hello world</p>\n<!-- /wp:paragraph -->\n"
    . "<!-- wp:separator -->\n<hr/><p>— · —</p>\n<!-- /wp:separator -->";
$report = $reporter->report($output, $source);
$assert('pass' === ($report['status'] ?? ''), '3: standalone punctuation-only node is ignored', implode(',', $codes($report)));

// ---------------------------------------------------------------------------
// 4. Whitespace + case differences must NOT count as hallucination.
// ---------------------------------------------------------------------------
$source = "<p>We   build\n  DETERMINISTIC tools.</p>";
$output = "<!-- wp:paragraph -->\n<p>we build deterministic tools.</p>\n<!-- /wp:paragraph -->";
$report = $reporter->report($output, $source);
$assert('pass' === ($report['status'] ?? ''), '4: normalization tolerates case + whitespace', implode(',', $codes($report)));

// ---------------------------------------------------------------------------
// 5. HTML entities in output decode to source plaintext (no false positive).
// ---------------------------------------------------------------------------
$source = '<p>Tom & Jerry said "hi"</p>';
$output = "<!-- wp:paragraph -->\n<p>Tom &amp; Jerry said &quot;hi&quot;</p>\n<!-- /wp:paragraph -->";
$report = $reporter->report($output, $source);
$assert('pass' === ($report['status'] ?? ''), '5: entity-decoded output matches source', implode(',', $codes($report)));

// ---------------------------------------------------------------------------
// 6. Block-delimiter comment attrs are NOT treated as visible output text.
//    (A label living only in a dynamic block's JSON attrs must not be scanned
//    as a text node — mirrors blocks-engine which strips wp: comments first.)
// ---------------------------------------------------------------------------
$source = '<nav><a href="/about">About</a></nav>';
$output = '<!-- wp:navigation-link {"label":"Totally Invented","url":"/x"} /-->';
$report = $reporter->report($output, $source);
$assert('pass' === ($report['status'] ?? ''), '6: attr-only text inside wp: comments is not scanned', implode(',', $codes($report)));

// ---------------------------------------------------------------------------
// 6b. Named/numeric HTML entities in the SOURCE decode to the same glyph the
//     transformer emits (it parses the DOM, so output carries literal © → —).
//     The check must decode the full entity set on both sides, not a 7-entity
//     subset — otherwise raw-HTML sources flag a flood of false positives on
//     &copy;/&rarr;/&mdash;/&ndash;/&rsquo;.
// ---------------------------------------------------------------------------
$source = '<footer>&copy; 2026 Switchback Outfitters &mdash; Trail Kits &rarr; Shop</footer>';
$output = "<!-- wp:paragraph -->\n<p>© 2026 Switchback Outfitters — Trail Kits → Shop</p>\n<!-- /wp:paragraph -->";
$report = $reporter->report($output, $source);
$assert('pass' === ($report['status'] ?? ''), '6b: full entity set decodes on both sides', implode(',', $codes($report)));

$source = '<p>It&rsquo;s Milwaukee&rsquo;s independent voice&hellip;</p>';
$output = "<!-- wp:paragraph -->\n<p>It’s Milwaukee’s independent voice…</p>\n<!-- /wp:paragraph -->";
$report = $reporter->report($output, $source);
$assert('pass' === ($report['status'] ?? ''), '6c: smart quotes and ellipsis decode', implode(',', $codes($report)));

$source = '<p>Open&nbsp;7am&ndash;8pm</p>';
$output = "<!-- wp:paragraph -->\n<p>Open 7am–8pm</p>\n<!-- /wp:paragraph -->";
$report = $reporter->report($output, $source);
$assert('pass' === ($report['status'] ?? ''), '6d: nbsp normalizes to a regular space', implode(',', $codes($report)));

// ---------------------------------------------------------------------------
// 6e. Producer-declared synthesized text (e.g. form-control echoes built from
//     placeholder/value/required attributes — NOT visible source copy) is
//     excluded from the check when passed in the ignore set, but still flagged
//     when it is not declared. The reporter normalizes ignore entries with the
//     same pipeline it uses for output nodes.
// ---------------------------------------------------------------------------
$source = '<form><label for="e">Email address</label><input id="e" placeholder="your@email.com" required></form>';
$echo   = 'Email address: your@email.com (required)';
$output = "<!-- wp:paragraph -->\n<p>Email address: your@email.com (required)</p>\n<!-- /wp:paragraph -->";

$report = $reporter->report($output, $source);
$assert('warning' === ($report['status'] ?? ''), '6e: undeclared synthesized text is still flagged', implode(',', $codes($report)));

$report = $reporter->report($output, $source, array( $echo ));
$assert('pass' === ($report['status'] ?? ''), '6e2: declared form-control echo is excluded', implode(',', $codes($report)));

// A different invented node is NOT masked just because some echoes are declared.
$output2 = $output . "\n<!-- wp:paragraph -->\n<p>Buy now and save fifty percent.</p>\n<!-- /wp:paragraph -->";
$report = $reporter->report($output2, $source, array( $echo ));
$assert(in_array('content_not_in_source', $codes($report), true), '6e3: ignore set does not mask genuine hallucinations');
$assert(1 === count($report['findings'] ?? array()), '6e4: exactly the non-echo node is flagged', (string) count($report['findings'] ?? array()));

// ---------------------------------------------------------------------------
// 7. Empty output is trivially faithful.
// ---------------------------------------------------------------------------
$report = $reporter->report('', '<p>anything</p>');
$assert('pass' === ($report['status'] ?? ''), '7: empty output passes');
$assert(array() === ($report['findings'] ?? null), '7b: empty output has no findings');

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
if ( $failures > 0 ) {
    fwrite(STDERR, sprintf("content-round-trip-reporter: %d passed, %d FAILED%s", $passes, $failures, PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf("content-round-trip-reporter: %d assertions passed%s", $passes, PHP_EOL));
