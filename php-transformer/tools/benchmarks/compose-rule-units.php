<?php
declare(strict_types=1);

// Exercise the real compose projection pass. Instrument a process-local copy of
// the transformer, not production counters. "before" bypasses only memoization.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

if ( ! isset($argv[1]) ) {
    foreach ( array(60, 120) as $routes ) {
        $results = array();
        foreach ( array('before', 'after') as $mode ) {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $mode . ' ' . $routes;
            exec($command, $lines, $status);
            if ( 0 !== $status ) throw new RuntimeException('Benchmark worker failed.');
            $results[$mode] = json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
            unset($lines);
        }
        if ( $results['before']['output_sha256'] !== $results['after']['output_sha256'] ) {
            throw new RuntimeException('Compose output changed.');
        }
        if ( 1 !== $results['after']['shared_scans'] || $results['before']['shared_scans'] < $routes ) {
            throw new RuntimeException('Shared stylesheet must be parsed once, independent of route count.');
        }
        echo json_encode(array('routes' => $routes, 'results' => $results), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
    exit(0);
}

$mode = $argv[1];
$routes = (int) $argv[2];
$source = file_get_contents(dirname(__DIR__, 2) . '/src/Css/CssStylesheetTransformer.php');
if ( 'before' === $mode ) {
    $source = str_replace('if ( ! isset($this->ruleUnitsByStylesheet[$css]) )', 'if ( true )', $source);
}
foreach ( array('collectRuleUnits' => 'collect_calls', 'scanRuleUnits' => 'scans') as $method => $counter ) {
    $signature = 'private function ' . $method . '(string $css, array $context, array &$units): void' . "\n    {";
    $instrument = "\n        " . '$GLOBALS["' . $counter . '"]++;';
    if ( 'scans' === $counter ) {
        $instrument .= "\n        " . 'if ($css === $GLOBALS["shared_css"]) $GLOBALS["shared_scans"]++;';
    }
    $source = str_replace($signature, $signature . $instrument, $source);
}
eval(substr($source, strpos($source, 'namespace ')));

$shared = '';
for ( $i = 0; strlen($shared) < 100 * 1024; ++$i ) {
    $shared .= '.shared-' . $i . '{color:red;background:linear-gradient(red,blue);padding:1px}';
}
$GLOBALS['shared_css'] = $shared;
$GLOBALS['collect_calls'] = $GLOBALS['scans'] = $GLOBALS['shared_scans'] = 0;
$files = array(array('path' => 'style.css', 'kind' => 'css', 'content' => $shared));
$projections = array(array('path' => 'style.css', 'content' => $shared));
for ( $i = 0; $i < $routes; ++$i ) {
    $owner = 'route-' . $i;
    $projections[] = array('path' => 'style.css', 'owner' => $owner, 'content' => '.page-' . $i . '{color:blue}');
}
$compiler = (new ReflectionClass(ArtifactCompiler::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(ArtifactCompiler::class, 'applyAuthorStylesheetProjections');
$start = hrtime(true);
$output = $method->invoke($compiler, $files, $projections, array(array('path' => 'style.css', 'content' => '.base{display:block}')));
echo json_encode(array(
    'projection_compose_seconds' => round((hrtime(true) - $start) / 1e9, 3),
    'shared_bytes' => strlen($shared),
    'collectRuleUnits_calls' => $GLOBALS['collect_calls'],
    'rule_unit_scans' => $GLOBALS['scans'],
    'shared_scans' => $GLOBALS['shared_scans'],
    'output_sha256' => hash('sha256', serialize($output)),
), JSON_THROW_ON_ERROR) . "\n";
