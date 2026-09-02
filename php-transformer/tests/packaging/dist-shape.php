<?php
/**
 * Verify what the Packagist dist actually contains.
 *
 * `.gitattributes` export-ignore is the only thing standing between a monorepo
 * checkout and the archive Packagist serves, and it is invisible to every other
 * suite: the parity and contract runs read the working tree, and the Composer
 * path repository used by install-proof.php copies the working tree verbatim.
 * Nothing else in this repository would notice the harness reappearing in a
 * release.
 *
 * This asserts the archive directly, the same way `git archive` builds the
 * tarball GitHub and Packagist hand to consumers.
 *
 * @package BlocksEnginePhpTransformer
 */

declare(strict_types=1);

$packageRoot = dirname(__DIR__, 2);
$prefix      = basename($packageRoot);
$repoRoot    = dirname($packageRoot);

$entries = archiveEntries($repoRoot, $prefix);

if ( array() === $entries ) {
    fwrite(STDERR, "php-transformer dist shape: git archive produced no entries for {$prefix}/\n");
    exit(1);
}

// Present: what a consumer calls, plus the contracts describing the results.
$requiredPaths = array(
    'composer.json',
    'php-transformer.php',
    'src/HtmlToBlocks/HtmlTransformer.php',
    'src/ArtifactCompiler/ArtifactCompiler.php',
    'src/Contract/VisualParityReportContract.php',
    'docs/contracts/php-transformer-visual-parity-fixture.schema.json',
    'docs/contracts/php-transformer-visual-parity-report.schema.json',
    'docs/contracts/visual-parity-report.md',
    'resources/wordpress-latest-core-block-attributes.json',
    'resources/wordpress-latest-core-block-supports.json',
);

// Absent: everything that only ever runs from a monorepo checkout.
$excludedPrefixes = array(
    'dev/',
    'tests/',
    'docs/consumer-prs/',
    'tools/benchmarks/',
    'tools/content-round-trip/',
    'tools/corpus-diagnostics/',
    'tools/live-wp-parity/',
    'tools/packagist-split/',
    'tools/static-parity/',
    'tools/visual-parity/',
);

$problems = array();

foreach ( $requiredPaths as $requiredPath ) {
    if ( ! in_array($requiredPath, $entries, true) ) {
        $problems[] = "missing from dist: {$requiredPath}";
    }
}

foreach ( $excludedPrefixes as $excludedPrefix ) {
    $shipped = array_values(array_filter(
        $entries,
        static fn (string $entry): bool => str_starts_with($entry, $excludedPrefix)
    ));

    if ( array() !== $shipped ) {
        $problems[] = sprintf(
            'monorepo-only path in dist: %s (%d files, e.g. %s)',
            $excludedPrefix,
            count($shipped),
            $shipped[0]
        );
    }
}

// A Node manifest in a PHP dist means a WordPress plugin vendor directory
// inherits a JavaScript dependency tree it never asked for.
foreach ( $entries as $entry ) {
    $name = basename($entry);
    if ( in_array($name, array( 'package.json', 'package-lock.json' ), true) ) {
        $problems[] = "Node manifest in dist: {$entry}";
    }
}

if ( array() !== $problems ) {
    sort($problems);
    fwrite(STDERR, "php-transformer dist shape contract failed:\n\n  " . implode("\n  ", $problems) . "\n\n");
    fwrite(STDERR, "The dist is produced by `git archive` honouring php-transformer/.gitattributes.\n");
    fwrite(STDERR, "Add an export-ignore entry, or move the file under a path a consumer can call.\n");
    exit(1);
}

printf(
    "Dist shape contract passed: %d files in the %s dist, %d monorepo-only trees excluded.\n",
    count($entries),
    $prefix,
    count($excludedPrefixes)
);

/**
 * Package-relative paths in the archive `git archive` builds for this prefix.
 *
 * @return list<string>
 */
function archiveEntries(string $repoRoot, string $prefix): array
{
    $descriptors = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    // Archive the index rather than HEAD. `git archive` resolves export-ignore
    // from the .gitattributes inside the tree it is archiving, so measuring
    // HEAD would report the previous release's shape and only notice a staged
    // packaging change after it was already committed. The index tree equals
    // HEAD's when nothing is staged, so this stays honest either way.
    $tree = gitOutput($repoRoot, array( 'git', 'write-tree' ));

    $process = proc_open(
        array( 'git', 'archive', '--format=tar', $tree, '--', $prefix ),
        $descriptors,
        $pipes,
        $repoRoot
    );

    if ( ! is_resource($process) ) {
        throw new RuntimeException('Failed to start git archive.');
    }

    fclose($pipes[0]);
    $tar = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    if ( 0 !== proc_close($process) ) {
        throw new RuntimeException('git archive failed: ' . $stderr);
    }

    $entries = array();
    $offset  = 0;
    $length  = strlen($tar);

    while ( $offset + 512 <= $length ) {
        $header = substr($tar, $offset, 512);
        $offset += 512;

        $name = rtrim(substr($header, 0, 100), "\0");
        if ( '' === $name ) {
            continue;
        }

        $size = (int) octdec(trim(rtrim(substr($header, 124, 12), "\0")));
        $type = substr($header, 156, 1);

        // Round the payload up to the next 512-byte record boundary.
        $offset += intdiv($size + 511, 512) * 512;

        if ( '0' !== $type && "\0" !== $type ) {
            continue;
        }

        if ( str_starts_with($name, $prefix . '/') ) {
            $entries[] = substr($name, strlen($prefix) + 1);
        }
    }

    sort($entries);

    return $entries;
}

/**
 * Run a git command and return its trimmed stdout.
 *
 * @param list<string> $command Command and arguments.
 */
function gitOutput(string $cwd, array $command): string
{
    $descriptors = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    $process = proc_open($command, $descriptors, $pipes, $cwd);

    if ( ! is_resource($process) ) {
        throw new RuntimeException('Failed to start: ' . implode(' ', $command));
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    if ( 0 !== proc_close($process) ) {
        throw new RuntimeException(implode(' ', $command) . ' failed: ' . $stderr);
    }

    return trim($stdout);
}
