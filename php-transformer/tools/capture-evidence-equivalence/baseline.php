<?php
declare(strict_types=1);

/**
 * Record the expected outputs of the equivalence corpus.
 *
 * Pass a disposable checkout of the pre-rename source commit after
 * rename-checkout.php has applied the published names to it. Each corpus case
 * is renamed with the same rule and replayed through that checkout; the
 * digest of its output is the expectation the gate holds this checkout to.
 *
 * Usage, from php-transformer/:
 *   php tools/capture-evidence-equivalence/baseline.php <renamed pre-rename php-transformer root> <source-commit>
 */

require_once dirname(__DIR__, 2) . '/tests/contract/support/CaptureEvidenceEquivalence.php';

[$script, $root, $commit] = array_pad($argv, 3, '');
if ( '' === $root || ! is_file($root . '/vendor/autoload.php') || 1 !== preg_match('/^[0-9a-f]{40}$/', $commit) ) {
    fwrite(STDERR, "Usage: php {$script} <renamed pre-rename php-transformer root with vendor/> <source-commit>\n");
    exit(2);
}
require $root . '/vendor/autoload.php';

$rows = array();
foreach ( CaptureEvidenceEquivalence::corpus() as $case ) {
    $case['args'] = CaptureEvidenceEquivalence::renameValue($case['args']);
    $output = CaptureEvidenceEquivalence::replay($case);
    $rows[] = array(
        'id' => $case['id'],
        'entry' => $case['entry'],
        'expected_sha256' => CaptureEvidenceEquivalence::digest($output, $root),
        'outcome' => isset($output['exception']) ? 'exception' : 'output',
    );
}

file_put_contents(
    CaptureEvidenceEquivalence::EXPECTED,
    json_encode(array('schema' => CaptureEvidenceEquivalence::SCHEMA, 'source_commit' => $commit, 'cases' => $rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);
fwrite(STDOUT, sprintf("Recorded %d expected outputs from %s\n", count($rows), $commit));
