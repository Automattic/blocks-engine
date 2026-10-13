<?php
declare(strict_types=1);

/**
 * Cross-name semantic report for the capture-evidence move (report only).
 *
 * Replays every corpus case twice: verbatim (retired names) through an
 * unmodified pre-rename checkout, and renamed through this checkout. The
 * pre-rename output is renamed with the same rule, and both are compared in
 * length-neutral form: a longer name legitimately moves byte counts, offsets,
 * excerpt cut points and content digests, so those are masked. Everything
 * else must match; the report lists each case that still differs with the
 * first differing context.
 *
 * Usage, from php-transformer/:
 *   php tools/capture-evidence-equivalence/semantic-report.php <unmodified pre-rename php-transformer root>
 */

require_once dirname(__DIR__, 2) . '/tests/contract/support/CaptureEvidenceEquivalence.php';

ini_set('memory_limit', '4G');

/** @return array<string, string> */
function capture_evidence_semantic_dump(string $root, bool $rename): array
{
    $out = array();
    foreach ( CaptureEvidenceEquivalence::corpus() as $case ) {
        if ( $rename ) {
            $case['args'] = CaptureEvidenceEquivalence::renameValue($case['args']);
        }
        $output = CaptureEvidenceEquivalence::replay($case);
        if ( ! $rename ) {
            $output = CaptureEvidenceEquivalence::renameValue($output);
        }
        $out[$case['id']] = $case['entry'] . "\n" . str_replace(array($root, (string) realpath($root)), '<transformer-root>', (string) json_encode(
            CaptureEvidenceEquivalence::normalized($output, $root, true),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        ));
    }

    return $out;
}

if ( '--dump' === ($argv[1] ?? '') ) {
    require $argv[2] . '/vendor/autoload.php';
    file_put_contents($argv[3], serialize(capture_evidence_semantic_dump($argv[2], false)));
    exit(0);
}

$pre = rtrim((string) ($argv[1] ?? ''), '/');
if ( '' === $pre || ! is_file($pre . '/vendor/autoload.php') ) {
    fwrite(STDERR, "Usage: php {$argv[0]} <unmodified pre-rename php-transformer root>\n");
    exit(2);
}
$dump = tempnam(sys_get_temp_dir(), 'capture-evidence-semantic-');
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --dump ' . escapeshellarg($pre) . ' ' . escapeshellarg((string) $dump), $status);
if ( 0 !== $status ) {
    exit(1);
}
$before = unserialize((string) file_get_contents((string) $dump));
unlink((string) $dump);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$after = capture_evidence_semantic_dump($root, true);

$differing = array();
foreach ( $after as $id => $text ) {
    if ( $text === ($before[$id] ?? null) ) {
        continue;
    }
    $other = (string) ($before[$id] ?? '');
    $at = strspn($text ^ $other, "\0");
    $differing[] = sprintf("%s %s\n  before: %s\n  after:  %s", $id, strtok($text, "\n"), substr(str_replace("\n", ' ', $other), max(0, $at - 120), 220), substr(str_replace("\n", ' ', $text), max(0, $at - 120), 220));
}
fwrite(STDOUT, implode("\n", $differing) . ("" === implode('', $differing) ? '' : "\n"));
fwrite(STDOUT, sprintf("Length-neutral semantic match: %d/%d cases\n", count($after) - count($differing), count($after)));
