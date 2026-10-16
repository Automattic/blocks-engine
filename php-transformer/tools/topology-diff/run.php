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
 *
 * Options:
 *   --corpus <dir>   Corpus root whose subdirectories are fixtures (default: <repo>/fixtures/websites).
 *   --fixture <dir>  Measure only this fixture directory (repeatable; overrides --corpus).
 *   --output <file>  Write the machine-readable JSON report to this path.
 *   --top <n>        Number of ranked divergences to print (default: 40).
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Topology\TopologyCorpusRunner;

$options = array( 'fixture' => array() );
for ( $i = 1; $i < $argc; $i++ ) {
    $arg = $argv[$i];
    foreach ( array( 'corpus', 'fixture', 'output', 'top' ) as $name ) {
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
exit(0);
