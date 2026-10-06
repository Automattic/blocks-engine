<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeJsonProjection;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ($condition) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};
$artifact = require dirname(__DIR__) . '/fixtures/route-style-defaults.php';
$plans = array();
foreach (array(false, true) as $reverse) {
    $input = $artifact;
    if ($reverse) $input['files'] = array_reverse($input['files'], true);
    $plan = (new ArtifactCompiler())->compile($input)->sourceReports['wordpress_site_plan'];
    $plans[] = $plan;
    $theme = array();
    foreach ($plan['writes'] as $write) if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    $assert(array() !== $theme, 'generated theme.json exists');
    $assert(!isset($theme['styles']['typography']['fontFamily']), 'route body families stay source-owned');
    $assert(!isset($theme['styles']['color']['background']), 'route body backgrounds stay source-owned');
    $assert(!isset($theme['styles']['elements']['h1']['typography']['fontFamily']), 'route heading families stay source-owned');
    $assert(!isset($theme['styles']['elements']['h1']['typography']['fontWeight']), 'route heading weights stay source-owned');
    $assert(!isset($theme['styles']['elements']['h1']['typography']['fontSize']), 'responsive heading sizes stay source-owned');
    $assert(isset($theme['styles']['color']['text']), 'shared foreground projects');
    $assert('1px' === ($theme['styles']['elements']['h1']['typography']['letterSpacing'] ?? null), 'shared heading spacing projects');
    $assert(3 === count($theme['settings']['typography']['fontFamilies'] ?? array()), 'all route families remain editor presets');
}
$themePayload = static fn(array $plan): string => array_values(array_filter($plan['writes'], static fn(array $write): bool => 'theme.json' === $write['target_path']))[0]['payload']['data'];
$assert($themePayload($plans[0]) === $themePayload($plans[1]), 'page inventory order cannot change Global Styles');

$projector = new ThemeJsonProjection();
$asset = static fn(string $css, array $paths): array => array('kind' => 'css', 'content' => $css, 'scopes' => array_map(static fn(string $path): array => array('kind' => 'page', 'source_path' => $path), $paths));
$documents = array('a.html', 'b.html', 'unstyled.html');
$css = 'body{font-family:Arial,sans-serif;background-color:#fff}h1{font-weight:700}';
$partial = $projector->project(array($asset($css, array('a.html', 'b.html'))), $documents);
$assert(!isset($partial['theme']['styles']['typography']['fontFamily']), 'agreement on styled subset cannot affect unstyled document');
$complete = $projector->project(array($asset($css, array('a.html')), $asset($css, array('b.html', 'unstyled.html'))), $documents);
$assert(isset($complete['theme']['styles']['typography']['fontFamily']), 'equal values covering every document can project');
$global = array('kind' => 'css', 'content' => $css, 'scopes' => array(array('kind' => 'global')));
$conflict = $projector->project(array($global, $asset('body{font-family:Georgia,serif;background-color:#333}h1{font-weight:300}', array('b.html'))), $documents);
$assert(!isset($conflict['theme']['styles']['typography']['fontFamily']) && !isset($conflict['theme']['styles']['elements']['h1']['typography']['fontWeight']), 'route override prevents promoting shared contender');
$variables = $projector->project(array(
    $asset(':root{--font:Arial,sans-serif}body{font-family:var(--font,serif)}', array('a.html')),
    $asset(':root{--font:Georgia,serif}body{font-family:var(--font,serif)}', array('b.html')),
), array('a.html', 'b.html'));
$assert(!isset($variables['theme']['styles']['typography']['fontFamily']), 'route-dependent variables cannot resolve to last route or fallback');
$partialVariable = $projector->project(array(
    $asset(':root{--font:Arial,sans-serif}', array('a.html')),
    array('kind' => 'css', 'content' => 'body{font-family:var(--font,serif)}', 'scopes' => array(array('kind' => 'global'))),
), array('a.html', 'b.html'));
$assert(!isset($partialVariable['theme']['styles']['typography']['fontFamily']), 'partial variable definition cannot become a shared fallback default');
$sharedVariableAssets = array(
    $asset(':root{--font:Arial,sans-serif}body{font-family:var(--font)}', array('a.html')),
    $asset(':root{--font:Arial,sans-serif}body{font-family:var(--font)}', array('b.html')),
);
$sharedVariable = $projector->project($sharedVariableAssets, array('a.html', 'b.html'));
$assert(isset($sharedVariable['theme']['styles']['typography']['fontFamily']), 'shared variable definitions with complete coverage can project');
$assert($sharedVariableAssets === $sharedVariable['assets'], 'projection retains authored route CSS byte-for-byte');
$scopedVariable = $projector->project(array($global, $asset('.special{--font:Georgia,serif}body{font-family:var(--font,serif)}', array('a.html', 'b.html'))), array('a.html', 'b.html'));
$assert(!isset($scopedVariable['theme']['styles']['typography']['fontFamily']), 'class-local variables cannot supply global defaults');

// Optional retained plan for the real WordPress frontend/editor runtime gate.
if (isset($argv[1])) file_put_contents($argv[1], json_encode($plans, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fwrite(STDOUT, "Route defaults: {$passes} passed, {$failures} failed\n");
exit($failures ? 1 : 0);
