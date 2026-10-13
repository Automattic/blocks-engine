<?php
declare(strict_types=1);

/**
 * Pack a harvest directory into the committed equivalence corpus.
 *
 * Usage, from php-transformer/:
 *   php tools/capture-evidence-equivalence/build-corpus.php <harvest-dir> <source-commit>
 */

require_once dirname(__DIR__, 2) . '/tests/contract/support/CaptureEvidenceEquivalence.php';

[$script, $dir, $commit] = array_pad($argv, 3, '');
if ( '' === $dir || ! is_dir($dir) || 1 !== preg_match('/^[0-9a-f]{40}$/', $commit) ) {
    fwrite(STDERR, "Usage: php {$script} <harvest-dir> <source-commit>\n");
    exit(2);
}

$cases = array();
foreach ( glob(rtrim($dir, '/') . '/*.json') ?: array() as $file ) {
    $case = json_decode((string) file_get_contents($file), true);
    if ( ! is_array($case) || ! is_string($case['entry'] ?? null) || ! is_array($case['args'] ?? null) ) {
        fwrite(STDERR, "Unreadable harvest record: {$file}\n");
        exit(1);
    }
    $cases[] = array('id' => basename($file, '.json'), 'entry' => $case['entry'], 'ctor' => $case['ctor'] ?? array(), 'args' => $case['args']);
}
usort($cases, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

$corpus = json_encode(
    array('schema' => CaptureEvidenceEquivalence::SCHEMA, 'source_commit' => $commit, 'cases' => $cases),
    JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
);
file_put_contents(CaptureEvidenceEquivalence::CORPUS, gzencode($corpus, 9));
fwrite(STDOUT, sprintf("Packed %d cases from %s\n", count($cases), $commit));
