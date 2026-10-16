<?php
/**
 * Topology-diff harness.
 *
 * Compiles website fixtures the way an import does and compares each entry
 * page's source element tree with the element tree of the saved block markup.
 * Reports where the rendered tree adds, removes, wraps or retags elements,
 * ranked by how many fixtures each divergence appears in. Pure PHP: no
 * WordPress runtime, no browser, no network.
 *
 * Usage:
 *   composer topology-diff -- [--corpus <dir>] [--fixture <dir>]... [--output <file>] [--top <n>]
 *       [--baseline <file>] [--update-baseline <file>] [--shard <i>/<n>]
 *
 * Options:
 *   --corpus <dir>   Corpus root whose subdirectories are fixtures (default: <repo>/fixtures/websites).
 *   --fixture <dir>  Measure only this fixture directory (repeatable; overrides --corpus).
 *   --output <file>  Write the machine-readable JSON report to this path.
 *   --top <n>        Number of ranked divergences to print (default: 40).
 *   --baseline <file>         Fail when any fixture's divergence counts differ from the
 *                             baseline. A rising weighted score (retag 1, other kinds 2) is a
 *                             regression; a falling or unchanged score must still be recorded.
 *   --update-baseline <file>  Write the measured counts as the new baseline.
 *   --shard <i>/<n>           Measure only the i-th of n interleaved fixture shards (1-based).
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Topology\TopologyCorpusRunner;

$options = array( 'fixture' => array() );
for ( $i = 1; $i < $argc; $i++ ) {
    $arg = $argv[$i];
    foreach ( array( 'corpus', 'fixture', 'output', 'top', 'baseline', 'update-baseline', 'shard' ) as $name ) {
        $value = null;
        if ( '--' . $name === $arg && $i + 1 < $argc ) {
            $value = $argv[++$i];
        } elseif ( str_starts_with($arg, '--' . $name . '=') ) {
            $value = substr($arg, strlen($name) + 3);
        }
        if ( null !== $value ) {
            if ( 'fixture' === $name ) {
                $options['fixture'][] = $value;
            } else {
                $options[$name] = $value;
            }
            continue 2;
        }
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(2);
}

$fixtures = $options['fixture'];
if ( array() === $fixtures ) {
    $corpus = $options['corpus'] ?? dirname(__DIR__, 3) . '/fixtures/websites';
    $fixtures = glob(rtrim($corpus, '/') . '/*', GLOB_ONLYDIR) ?: array();
    sort($fixtures);
}
if ( isset($options['shard']) ) {
    if ( 1 !== preg_match('/^(\d+)\/(\d+)$/', $options['shard'], $shard) || (int) $shard[1] < 1 || (int) $shard[1] > (int) $shard[2] ) {
        fwrite(STDERR, "--shard must be <i>/<n> with 1 <= i <= n.\n");
        exit(2);
    }
    $fixtures = array_values(array_filter($fixtures, static fn (int $index): bool => $index % (int) $shard[2] === (int) $shard[1] - 1, ARRAY_FILTER_USE_KEY));
}
if ( array() === $fixtures ) {
    fwrite(STDERR, "No fixtures found.\n");
    exit(1);
}

$runner = new TopologyCorpusRunner();
$report = $runner->run($fixtures, static function (string $name, array $result): void {
    fwrite(STDERR, sprintf("%-48s matched=%d divergences=%d\n", $name, $result['matched'] ?? 0, array_sum((array) ( $result['kinds'] ?? array() ))));
});

if ( isset($options['output']) ) {
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ( false === $json || false === file_put_contents($options['output'], $json . "\n") ) {
        fwrite(STDERR, "Failed to write report to: {$options['output']}\n");
        exit(1);
    }
}

fwrite(STDOUT, $runner->renderSummary($report, (int) ( $options['top'] ?? 40 )));

if ( isset($options['update-baseline']) ) {
    $json = json_encode(TopologyCorpusRunner::baseline($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ( false === $json || false === file_put_contents($options['update-baseline'], $json . "\n") ) {
        fwrite(STDERR, "Failed to write baseline to: {$options['update-baseline']}\n");
        exit(1);
    }
    fwrite(STDOUT, "\nWrote baseline: {$options['update-baseline']}\n");
}

if ( isset($options['baseline']) ) {
    $baseline = json_decode((string) @file_get_contents($options['baseline']), true);
    if ( ! is_array($baseline) || TopologyCorpusRunner::BASELINE_SCHEMA !== ( $baseline['schema'] ?? null ) ) {
        fwrite(STDERR, "Unreadable baseline: {$options['baseline']}\n");
        exit(1);
    }
    $result = TopologyCorpusRunner::check($report, $baseline);
    foreach ( $result['regressions'] as $line ) {
        fwrite(STDOUT, "REGRESSION {$line}\n");
    }
    foreach ( $result['improvements'] as $line ) {
        fwrite(STDOUT, "IMPROVED   {$line}\n");
    }
    foreach ( $result['changes'] as $line ) {
        fwrite(STDOUT, "CHANGED    {$line}\n");
    }
    if ( array() !== $result['regressions'] ) {
        fwrite(STDOUT, "\nA weighted topology score rose: the change adds, removes or retags elements author CSS cannot see. Remove the divergence, or declare why in the pull request and update the baseline.\n");
    }
    if ( array() !== $result['regressions'] || array() !== $result['improvements'] || array() !== $result['changes'] ) {
        fwrite(STDOUT, "\nRecord the measured counts so the baseline stays exact:\n  composer topology-diff -- --update-baseline tools/topology-diff/baseline.json\n");
        exit(1);
    }
    fwrite(STDOUT, "\nTopology matches the baseline.\n");
}
exit(0);
