<?php
declare(strict_types=1);

/**
 * Apply the capture-evidence rename rule to a disposable pre-rename checkout.
 *
 * Rewrites every src/ PHP file of the given php-transformer root with
 * CaptureEvidenceEquivalence::rename() and swaps the two recognized capture
 * runtime identities for the currently published digests (the bodies in
 * tests/fixtures/capture-disclosure-runtime.js and
 * tests/fixtures/capture-gallery-runtime.js). The result is pre-rename
 * behavior under the published names and runtime bodies: the reference the
 * equivalence gate compares this checkout against. Never run it on a working
 * checkout.
 *
 * Usage, from php-transformer/:
 *   php tools/capture-evidence-equivalence/rename-checkout.php <disposable php-transformer root>
 */

require_once dirname(__DIR__, 2) . '/tests/contract/support/CaptureEvidenceEquivalence.php';

$root = rtrim((string) ($argv[1] ?? ''), '/');
if ( '' === $root || ! is_dir($root . '/src') || realpath($root) === realpath(dirname(__DIR__, 2)) ) {
    fwrite(STDERR, "Usage: php {$argv[0]} <disposable pre-rename php-transformer root>\n");
    exit(2);
}

$fixtures = dirname(__DIR__, 2) . '/tests/fixtures';
$identities = array(
    // Pre-rename identity => currently published identity.
    'db750332c5e6db4d359188bc5217f5fab88e4badc5e9911bf6fd95f3d8624614' => hash('sha256', trim((string) file_get_contents($fixtures . '/capture-disclosure-runtime.js'))),
    '1321b380fa549db7e589c3e7cf67dfec91c2810da2f4c237dc9fefb5a649c5a7' => hash('sha256', trim((string) file_get_contents($fixtures . '/capture-gallery-runtime.js'))),
);

$changed = 0;
foreach ( new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file ) {
    if ( 'php' !== $file->getExtension() ) {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    $renamed = strtr(CaptureEvidenceEquivalence::rename($source), $identities);
    if ( $renamed !== $source ) {
        file_put_contents($file->getPathname(), $renamed);
        ++$changed;
    }
}
fwrite(STDOUT, "Renamed capture vocabulary in {$changed} source files under {$root}/src\n");
