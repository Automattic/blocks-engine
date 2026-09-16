<?php
declare(strict_types=1);

/**
 * Unit coverage for WordPress site-plan production extracted from ArtifactCompiler (#1823).
 *
 * compile() returns the transformer envelope; WordPressSitePlanComposer derives
 * the plan via fromResult() and view emission. No Context bag. No HtmlCompilation
 * or NavigationPatternContext edits.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\WordPressSitePlanComposer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPatternContext;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanView;

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
$composerSource = (string) file_get_contents((new ReflectionClass(WordPressSitePlanComposer::class))->getFileName());

$assert(! is_a(WordPressSitePlanComposer::class, ArtifactCompiler::class, true), 'composer-is-not-artifactcompiler');
$assert(! str_contains($composerSource, 'HtmlCompilation'), 'composer has no HtmlCompilation reference');
$assert(! str_contains($composerSource, 'NavigationPatternContext'), 'composer has no NavigationPatternContext reference');
$assert(! preg_match('/class\s+\w*Context\b/', $composerSource), 'composer introduces no Context bag');
$assert(! preg_match('/fromCompilerInput\s*\(/', $compilerSource), 'fromCompilerInput left ArtifactCompiler.php');
$assert(! preg_match('/WordPressSitePlan(?:View|Input)/', $compilerSource), 'view emission and plan input left ArtifactCompiler.php');
$assert(! preg_match('/->fromResult\s*\(/', $compilerSource), 'fromResult left ArtifactCompiler.php');
$assert(str_contains($compilerSource, 'WordPressSitePlanComposer'), 'ArtifactCompiler delegates to WordPressSitePlanComposer');
$assert(preg_match('/function\s+fromResult\s*\(/', $composerSource) === 1, 'fromResult lives on WordPressSitePlanComposer');
$assert(preg_match('/function\s+view\s*\(/', $composerSource) === 1, 'view emission lives on WordPressSitePlanComposer');
$assert(preg_match('/function\s+compile\s*\(/', $compilerSource) === 1, 'canonical compile() stays on ArtifactCompiler');

$htmlCompilationPath = (new ReflectionClass(HtmlCompilation::class))->getFileName();
$navigationContextPath = (new ReflectionClass(NavigationPatternContext::class))->getFileName();
$assert(is_string($htmlCompilationPath) && is_string($navigationContextPath), 'parallel files remain loadable');

$artifact = array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<main><h1>Home</h1></main>',
        'about.html' => '<main><h1>About</h1></main>',
    ),
);
$compiled = (new ArtifactCompiler())->compile($artifact);
$plan = $compiled->sourceReports['wordpress_site_plan'] ?? array();
$assert(array() !== $plan, 'compile still attaches the derived site plan');

$envelope = $compiled->toArray();
unset($envelope['source_reports']['wordpress_site_plan'], $envelope['source_reports']['wordpress_site_plan_diagnostics']);
unset($envelope['metrics']['html_document_transform_count'], $envelope['metrics']['normalization_count'], $envelope['metrics']['analysis_count'], $envelope['metrics']['terminal_reduction_count']);
$composer = new WordPressSitePlanComposer();
$assert($plan === $composer->fromResult($envelope), 'fromResult derives the attached plan from the transformer envelope');
$assert($plan === (new WordPressSitePlan())->fromResult($envelope), 'public fromResult matches composer derivation');
$assert($compiled->toWordPressSitePlanView() === $composer->view($compiled), 'composer view matches result view emission');
$assert(WordPressSitePlanView::SCHEMA === ($composer->view($compiled)['schema'] ?? null), 'composer view exposes the canonical view schema');

if ( $failures ) {
    fwrite(STDERR, $failures . " wordpress site-plan composer test(s) failed\n");
    exit(1);
}

echo 'WordPress site-plan composer tests: ' . $passes . " passed\n";
