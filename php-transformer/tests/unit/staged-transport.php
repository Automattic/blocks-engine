<?php
declare(strict_types=1);

/**
 * Unit coverage for staged transport extracted from ArtifactCompiler (#1801).
 *
 * Constructed via ArtifactCompiler — no new Context bag. prepareShared /
 * preparePage / compilePage / compose live on StagedTransport; compile() and
 * finalizeArtifact stay on ArtifactCompiler.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\StagedTransport;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$compilerSource = (string) file_get_contents((new ReflectionClass(ArtifactCompiler::class))->getFileName());
$transportSource = (string) file_get_contents((new ReflectionClass(StagedTransport::class))->getFileName());

$assert(str_contains($compilerSource, 'use StagedTransport;'), 'ArtifactCompiler uses StagedTransport');
$assert(str_starts_with(ltrim($transportSource), '<?php') && str_contains($transportSource, 'trait StagedTransport'), 'StagedTransport is a trait, not a Context bag');
$assert(! str_contains($transportSource, 'HtmlCompilation'), 'StagedTransport has no HtmlCompilation reference');
$assert(! preg_match('/class\s+\w*Context\b/', $transportSource), 'StagedTransport introduces no Context bag');

foreach ( array('prepareShared', 'preparePage', 'preparePages', 'compilePage', 'compose') as $method ) {
    $assert(! preg_match('/function\s+' . $method . '\s*\(/', $compilerSource), $method . ' left ArtifactCompiler.php');
    $assert(preg_match('/function\s+' . $method . '\s*\(/', $transportSource) === 1, $method . ' lives on StagedTransport');
}

$assert(preg_match('/function\s+compile\s*\(/', $compilerSource) === 1, 'canonical compile() stays on ArtifactCompiler');
$assert(preg_match('/function\s+finalizeArtifact\s*\(/', $compilerSource) === 1, 'envelope finalize stays on ArtifactCompiler');
$assert(! preg_match('/function\s+compile\s*\(/', $transportSource), 'canonical compile() did not move onto StagedTransport');
$assert(! preg_match('/function\s+finalizeArtifact\s*\(/', $transportSource), 'finalizeArtifact did not move onto StagedTransport');

$artifact = array(
    'entrypoint' => 'index.html',
    'files' => array(
        array('path' => 'assets/site.css', 'content' => 'main{color:#123}', 'metadata' => array('compilation' => array('scope' => 'shared'))),
        array('path' => 'index.html', 'content' => '<link rel="stylesheet" href="assets/site.css"><main><h1>Home</h1></main>'),
        array('path' => 'about.html', 'content' => '<link rel="stylesheet" href="assets/site.css"><main><h1>About</h1></main>'),
    ),
);

$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$pages = $compiler->preparePages($artifact, $shared);
$receipts = $compiler->compilePreparedPages($shared, $pages);
$stagedPlan = $compiler->compose($shared, array_values($receipts))->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$compiledPlan = $compiler->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$assert($compiledPlan === $stagedPlan, 'Whole and staged compilation yield byte-identical canonical site plans');

if ( $failures ) {
    fwrite(STDERR, $failures . " staged transport test(s) failed\n");
    exit(1);
}

echo 'Staged transport tests: ' . $passes . " passed\n";
