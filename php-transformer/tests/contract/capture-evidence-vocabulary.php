<?php
declare(strict_types=1);

/**
 * Capture-evidence vocabulary contract (Automattic/data-liberation-agent#787).
 *
 * The published vocabulary (src/Contract/CaptureEvidence.php) is the single
 * place a capture name is spelled:
 *
 * - no PHP string in src/ outside the vocabulary spells a capture name, so
 *   every name the transformer reads or emits comes from a constant;
 * - every constant is used by src/, so the vocabulary has no dead entries,
 *   except those explicitly flagged in PUBLISHED_FOR_DOWNSTREAM: names Blocks
 *   Engine publishes for downstream importers and must itself never read;
 * - docs/contracts/capture-evidence.md documents exactly the published names,
 *   with the downstream-only names in their own section;
 * - each recognized capture runtime identity is the digest of its published
 *   body under tests/fixtures/.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

$root = dirname(__DIR__, 2);
$vocabularyFile = $root . '/src/Contract/CaptureEvidence.php';
$constants = (new ReflectionClass(CaptureEvidence::class))->getConstants();
$downstream = $constants['PUBLISHED_FOR_DOWNSTREAM'];
unset($constants['PUBLISHED_FOR_DOWNSTREAM']);
$failures = array();
if ( 'data-blocks-engine-capture-inert-control' !== ($constants['INERT_CONTROL'] ?? null) ) {
    $failures[] = 'CaptureEvidence::INERT_CONTROL must publish the capture-proven inert control attribute';
}
foreach ( $downstream as $name ) {
    if ( ! is_string($constants[$name] ?? null) ) {
        $failures[] = "PUBLISHED_FOR_DOWNSTREAM flags {$name}, which is not a published name";
    }
}
if ( count($downstream) !== count(array_unique($downstream)) ) {
    $failures[] = 'PUBLISHED_FOR_DOWNSTREAM flags a name twice';
}
$namePattern = '/(?:data-)?blocks-engine-capture(?:-|\/)/';

$uses = array_fill_keys(array_keys($constants), 0);
$sources = 0;
foreach ( new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file ) {
    if ( 'php' !== $file->getExtension() || realpath($file->getPathname()) === realpath($vocabularyFile) ) {
        continue;
    }
    ++$sources;
    $relative = substr($file->getPathname(), strlen($root) + 1);
    $source = (string) file_get_contents($file->getPathname());
    foreach ( token_get_all($source) as $token ) {
        if ( is_array($token) && in_array($token[0], array(T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML), true) && 1 === preg_match($namePattern, $token[1]) ) {
            $failures[] = "{$relative}:{$token[2]} spells a capture name instead of using CaptureEvidence: " . substr(trim($token[1]), 0, 120);
        }
    }
    if ( preg_match_all('/\bCaptureEvidence::([A-Z][A-Z0-9_]*)\b/', $source, $matches) ) {
        foreach ( $matches[1] as $name ) {
            if ( ! array_key_exists($name, $uses) ) {
                $failures[] = "{$relative} references undefined CaptureEvidence::{$name}";
                continue;
            }
            ++$uses[$name];
        }
    }
}
foreach ( $uses as $name => $count ) {
    $flagged = in_array($name, $downstream, true);
    if ( 0 === $count && ! $flagged ) {
        $failures[] = "CaptureEvidence::{$name} is published but nothing in src/ uses it; flag it in PUBLISHED_FOR_DOWNSTREAM only if a downstream importer reads it";
    }
    if ( 0 < $count && $flagged ) {
        $failures[] = "CaptureEvidence::{$name} is flagged as downstream-only but src/ uses it; remove it from PUBLISHED_FOR_DOWNSTREAM";
    }
}

foreach ( $constants as $name => $value ) {
    $isDigest = str_ends_with($name, '_SHA256');
    if ( $isDigest ? 1 !== preg_match('/^[0-9a-f]{64}$/', $value) : 1 !== preg_match($namePattern, $value) ) {
        $failures[] = "CaptureEvidence::{$name} is outside the published namespace: {$value}";
    }
}

$doc = (string) file_get_contents($root . '/docs/contracts/capture-evidence.md');
preg_match_all('/^\|\s*`([^`]+)`/m', $doc, $documented);
$documented = $documented[1];
$published = array_values($constants);
foreach ( array_diff($published, $documented) as $missing ) {
    $failures[] = "docs/contracts/capture-evidence.md does not document {$missing}";
}
foreach ( array_diff($documented, $published) as $extra ) {
    $failures[] = "docs/contracts/capture-evidence.md documents {$extra}, which CaptureEvidence does not publish";
}
if ( count($documented) !== count(array_unique($documented)) ) {
    $failures[] = 'docs/contracts/capture-evidence.md lists a name twice';
}
$downstreamSection = preg_match('/^### Published for downstream importers\n(.*?)(?=^#{2,3} |\z)/ms', $doc, $section) ? $section[1] : '';
preg_match_all('/^\|\s*`([^`]+)`/m', $downstreamSection, $documentedDownstream);
$publishedDownstream = array_map(static fn (string $name): string => $constants[$name] ?? '', $downstream);
sort($publishedDownstream);
$documentedDownstream = $documentedDownstream[1];
sort($documentedDownstream);
if ( $publishedDownstream !== $documentedDownstream ) {
    $failures[] = 'docs/contracts/capture-evidence.md "Published for downstream importers" must list exactly the PUBLISHED_FOR_DOWNSTREAM names';
}

$runtimes = array(
    'DISCLOSURE_RUNTIME_SHA256' => 'capture-disclosure-runtime.js',
    'GALLERY_RUNTIME_SHA256' => 'capture-gallery-runtime.js',
    'CHOICE_RUNTIME_SHA256' => 'capture-choice-runtime.js',
);
foreach ( $runtimes as $name => $fixture ) {
    $body = trim((string) file_get_contents($root . '/tests/fixtures/' . $fixture));
    if ( $constants[$name] !== hash('sha256', $body) ) {
        $failures[] = "CaptureEvidence::{$name} is not the digest of tests/fixtures/{$fixture}";
    }
}

if ( array() !== $failures ) {
    fwrite(STDERR, "Capture-evidence vocabulary contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Capture-evidence vocabulary contract passed: %d published names (%d consumed by Blocks Engine, %d for downstream importers), all documented; %d source files scanned.\n",
    count($constants),
    count($constants) - count($downstream),
    count($downstream),
    $sources
));
