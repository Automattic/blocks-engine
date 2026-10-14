<?php
declare(strict_types=1);

/**
 * Capture-evidence equivalence gate (Automattic/data-liberation-agent#787).
 *
 * Moving to the Blocks Engine capture vocabulary must not lose power. The
 * corpus holds every entry-point call the test suite made with the retired
 * producer-named capture evidence, recorded verbatim on origin/trunk before
 * the move. Each case is renamed with the published rule and replayed here.
 * Its compile result and WordPress site plan (or projected files, or
 * exception) must be byte-identical to what the pre-move origin/trunk source,
 * with the same rule applied to it, produced for that renamed input.
 *
 * See docs/contracts/capture-evidence.md and
 * tools/capture-evidence-equivalence/README.md.
 *
 * Usage, from php-transformer/:
 *   php tests/contract/capture-evidence-equivalence.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/support/CaptureEvidenceEquivalence.php';

ini_set('memory_limit', '2G');

$serialization = Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization::class;
foreach (array('<?xml encoding="UTF-8"><html>' => '<html>', '<!--?xml encoding="UTF-8"--><html>' => '<html>', '<!--/xml encoding="UTF-8"--><html>' => '<html>') as $serialized => $canonical) {
    if ($canonical !== $serialization::normalizeSerializedHtml($serialized)) {
        fwrite(STDERR, "HTML encoding marker serialization regression.\n");
        exit(1);
    }
}
if ('<!DOCTYPE html>\n<html>' !== $serialization::normalizeSerializedHtml('<!DOCTYPE html>\n<!--?xml encoding="UTF-8"--><html>')) {
    fwrite(STDERR, "HTML encoding marker after doctype regression.\n");
    exit(1);
}
$quoteVariants = array('<div data-state=\'{"open":true}\'></div>', '<div data-state="{&quot;open&quot;:true}"></div>');
if ($quoteVariants[0] !== $serialization::canonicalizeAttributeQuotes($quoteVariants[1])) {
    fwrite(STDERR, "HTML attribute quote canonicalization regression.\n");
    exit(1);
}

$expected = json_decode((string) file_get_contents(CaptureEvidenceEquivalence::EXPECTED), true);
if ( ! is_array($expected) || CaptureEvidenceEquivalence::SCHEMA !== ($expected['schema'] ?? null) ) {
    fwrite(STDERR, "Capture-evidence equivalence expectations are unreadable.\n");
    exit(1);
}
$rows = array();
foreach ( $expected['cases'] as $row ) {
    $rows[$row['id']] = $row;
}

$corpus = CaptureEvidenceEquivalence::corpus();
$root = dirname(__DIR__, 2);
$failures = array();
$entries = array();
foreach ( $corpus as $case ) {
    $row = $rows[$case['id']] ?? null;
    if ( null === $row || $row['entry'] !== $case['entry'] ) {
        $failures[] = "{$case['id']}: no recorded expectation";
        continue;
    }
    $input = json_encode($case['args'], JSON_UNESCAPED_SLASHES);
    if ( ! is_string($input) || ! CaptureEvidenceEquivalence::carriesRetiredVocabulary($input) ) {
        $failures[] = "{$case['id']}: corpus case carries no retired capture name";
        continue;
    }
    $case['args'] = CaptureEvidenceEquivalence::renameValue($case['args']);
    $renamed = json_encode($case['args'], JSON_UNESCAPED_SLASHES);
    if ( ! is_string($renamed) || CaptureEvidenceEquivalence::carriesRetiredVocabulary($renamed) ) {
        $failures[] = "{$case['id']}: rename left a retired name in the input";
        continue;
    }
    $output = CaptureEvidenceEquivalence::replay($case);
    $actual = CaptureEvidenceEquivalence::digest($output, $root);
    $want = $row['expected_sha256'];
    $entries[$case['entry']] ??= array('cases' => 0, 'identical' => 0);
    ++$entries[$case['entry']]['cases'];
    if ( $actual === $want ) {
        ++$entries[$case['entry']]['identical'];
        continue;
    }
    $failures[] = sprintf('%s (%s): output digest %s, expected %s%s', $case['id'], $case['entry'], $actual, $want, isset($output['exception']) ? ' [threw ' . $output['exception'] . ': ' . $output['message'] . ']' : '');
}
if ( count($rows) !== count($corpus) ) {
    $failures[] = sprintf('expectations list %d cases, corpus holds %d', count($rows), count($corpus));
}

$identical = array_sum(array_column($entries, 'identical'));
ksort($entries);
foreach ( $entries as $entry => $tally ) {
    fwrite(STDOUT, sprintf("  %-42s %3d/%3d identical\n", $entry, $tally['identical'], $tally['cases']));
}
if ( array() !== $failures ) {
    fwrite(STDERR, "Capture-evidence equivalence failed:\n- " . implode("\n- ", array_slice($failures, 0, 40)) . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Capture-evidence equivalence passed: %d/%d renamed cases byte-identical to their expectations (origin/trunk %s, plus the deliberate fidelity changes in tools/capture-evidence-equivalence/README.md).\n",
    $identical,
    count($corpus),
    substr((string) $expected['source_commit'], 0, 12)
));
