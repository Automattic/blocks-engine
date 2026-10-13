<?php
declare(strict_types=1);

/**
 * Producer-neutral source guard (Automattic/data-liberation-agent#787).
 *
 * Blocks Engine reads capture evidence through its own published vocabulary
 * (docs/contracts/capture-evidence.md) and carries no knowledge of a specific
 * capture producer. This guard fails when a producer-specific token appears
 * in the runtime source of either package or in their composer/package
 * scripts. Matching is case-insensitive and word-ish: the token must not be
 * part of a longer lowercase word, so `childLayout` passes and `dlaSection`
 * or `DLA_ROOT` fail. The tokens are assembled from fragments so this file
 * stays clean under its own rule.
 */

$transformer = dirname(__DIR__, 2);
$repository = dirname($transformer);
$short = 'd' . 'la';
$long = 'liberation';
$pattern = '/(?:(?<![A-Za-z])(?i:' . $short . ')|(?<=[a-z])' . ucfirst($short) . ')(?![a-z])|(?i:data-' . $long . '|' . $long . '-agent)/';

/** @return list<string> */
$filesUnder = static function (string $dir): array {
    if ( ! is_dir($dir) ) {
        return array();
    }
    $files = array();
    foreach ( new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file ) {
        if ( $file->isFile() ) {
            $files[] = $file->getPathname();
        }
    }
    sort($files, SORT_STRING);

    return $files;
};

$failures = array();
$scanned = 0;
$scan = static function (string $label, string $text) use ($pattern, &$failures, &$scanned): void {
    ++$scanned;
    foreach ( preg_split('/\R/', $text) ?: array() as $index => $line ) {
        if ( 1 === preg_match($pattern, $line, $match) ) {
            $failures[] = sprintf('%s:%d names a capture producer (%s): %s', $label, $index + 1, $match[0], substr(trim($line), 0, 140));
        }
    }
};

$roots = array($transformer . '/src');
if ( is_dir($repository . '/packages/blocks-engine') ) {
    $roots[] = $repository . '/packages/blocks-engine/src';
}
foreach ( $roots as $root ) {
    foreach ( $filesUnder($root) as $file ) {
        $scan(substr($file, strlen($repository) + 1), (string) file_get_contents($file));
    }
}

$manifests = array_merge(
    array($transformer . '/composer.json', $repository . '/packages/blocks-engine/package.json'),
    glob($transformer . '/tools/*/package.json') ?: array()
);
foreach ( $manifests as $manifest ) {
    if ( ! is_file($manifest) ) {
        continue;
    }
    $data = json_decode((string) file_get_contents($manifest), true);
    $scripts = is_array($data['scripts'] ?? null) ? $data['scripts'] : array();
    $scan(substr($manifest, strlen($repository) + 1) . ' scripts', (string) json_encode($scripts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

if ( array() !== $failures ) {
    fwrite(STDERR, "Producer-neutral source guard failed:\n- " . implode("\n- ", array_slice($failures, 0, 60)) . (count($failures) > 60 ? "\n- ... " . (count($failures) - 60) . ' more' : '') . "\n");
    exit(1);
}

fwrite(STDOUT, "Producer-neutral source guard passed: {$scanned} runtime sources and script manifests carry no capture-producer names.\n");
