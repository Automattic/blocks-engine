<?php
declare(strict_types=1);

/**
 * Harvest hook for the capture-evidence equivalence corpus.
 *
 * Load this file from a disposable pre-rename checkout whose public entry
 * points are wrapped to call be_capture_evidence_harvest() (see README.md in
 * this directory), then run that checkout's test suite with
 * BE_CAPTURE_EVIDENCE_HARVEST_DIR set. Every outermost entry-point call whose
 * input carries the retired producer-named vocabulary is written verbatim to
 * that directory, one JSON file per distinct call.
 */

require_once dirname(__DIR__, 2) . '/tests/contract/support/CaptureEvidenceEquivalence.php';

if ( ! function_exists('be_capture_evidence_harvest') ) {
    /**
     * @param array<string, mixed> $ctor
     * @param list<mixed> $args
     */
    function be_capture_evidence_harvest(string $entry, array $ctor, array $args, callable $run): mixed
    {
        static $depth = 0;
        $dir = getenv('BE_CAPTURE_EVIDENCE_HARVEST_DIR');
        if ( ! is_string($dir) || '' === $dir || $depth > 0 || ! empty($ctor['unrecordable']) ) {
            ++$depth;
            try {
                return $run();
            } finally {
                --$depth;
            }
        }
        ++$depth;
        try {
            $encoded = json_encode(array('entry' => $entry, 'ctor' => $ctor, 'args' => $args), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            if ( is_string($encoded) && CaptureEvidenceEquivalence::carriesRetiredVocabulary($encoded) ) {
                if ( ! is_dir($dir) ) {
                    mkdir($dir, 0777, true);
                }
                file_put_contents($dir . '/' . sha1($encoded) . '.json', $encoded);
            }

            return $run();
        } finally {
            --$depth;
        }
    }
}
