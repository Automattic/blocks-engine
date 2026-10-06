<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ThemePreferenceOwnership;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanView;

require dirname(__DIR__) . '/vendor/autoload.php';

$artifactPath = (string) (getenv('NICK_THEME_ARTIFACT_PATH') ?: '');
if ('' === $artifactPath || !is_readable($artifactPath)) throw new RuntimeException('NICK_THEME_ARTIFACT_PATH must reference the Playwright-captured public-source artifact.');
$artifact = json_decode((string) file_get_contents($artifactPath), true, 512, JSON_THROW_ON_ERROR);
$findTheme = static function (array $blocks) use (&$findTheme): array {
    foreach ($blocks as $block) {
        if (is_array($block) && 'custom/theme-toggle' === ($block['blockName'] ?? null)) return $block;
        $nested = is_array($block['innerBlocks'] ?? null) ? $findTheme($block['innerBlocks']) : array();
        if (array() !== $nested) return $nested;
    }
    return array();
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$declaration = current(array_filter($artifact['runtime_declarations'] ?? array(), static fn (array $row): bool => ThemePreferenceOwnership::DECLARATION_KIND === ($row['kind'] ?? null))) ?: array();
$ownership = $declaration['payload']['ownership'] ?? array();
$hash = (string) ($ownership['runtime_script_sha256'] ?? '');
$path = (string) ($ownership['runtime_script_path'] ?? '');

$whole = (new ArtifactCompiler())->compile($artifact);
$wholeBlock = $findTheme($whole->blocks);
$assert('custom/theme-toggle' === ($wholeBlock['blockName'] ?? null) && array() === $whole->fallbacks, 'whole ArtifactCompiler input failed to restore the exact source control with no fallback.');

$stagedCompiler = new ArtifactCompiler();
$shared = $stagedCompiler->prepareShared($artifact);
$pagePlans = $stagedCompiler->preparePages($artifact, $shared);
$pageReceipts = $stagedCompiler->compilePreparedPages($shared, $pagePlans);
$staged = $stagedCompiler->compose($shared, $pageReceipts);
$stagedBlock = $findTheme($staged->blocks);
$assert(($wholeBlock['attrs'] ?? null) === ($stagedBlock['attrs'] ?? false) && array() === $staged->fallbacks, 'staged ArtifactCompiler transport changed or lost the source ownership contract.');

// SSI's normal inert client-script policy removes executable JS files before
// compilation. The canonical declaration must still provide its hash-bound
// bounded evidence to the compiler without causing that code to be enqueued.
$inertArtifact = $artifact;
$inertArtifact['files'] = array_values(array_filter($inertArtifact['files'], static fn (array $file): bool => !preg_match('/\.(?:m?js)$/i', (string) ($file['path'] ?? ''))));
$inert = (new ArtifactCompiler())->compile($inertArtifact);
$inertBlock = $findTheme($inert->blocks);
$assert(($wholeBlock['attrs'] ?? null) === ($inertBlock['attrs'] ?? false) && array() === $inert->fallbacks, 'canonical ownership evidence was lost when SSI inert policy removed the source runtime asset file.');
$assert(!str_contains((string) ($inert->serializedBlocks ?? ''), $path), 'source runtime evidence was analyzed but not emitted into WordPress page markup.');

$view = (new WordPressSitePlanView())->fromResult($whole);
$plan = $view['wordpress_site_plan'] ?? array();
$assert(ThemePreferenceOwnership::DECLARATION_SCHEMA === ($plan['runtime_declarations'][0]['payload']['schema'] ?? null), 'whole compilation omitted the qualified declaration from the canonical site plan.');
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://wp.test/wp-content/themes/nick', 'runtime_capabilities' => array()));
$resolvedDeclaration = current(array_filter($resolved['runtime_declarations'], static fn (array $row): bool => ThemePreferenceOwnership::DECLARATION_KIND === ($row['kind'] ?? null)));
$assert($hash === ($resolvedDeclaration['payload']['ownership']['runtime_script_sha256'] ?? null), 'canonical plan resolution changed the owning Nick runtime hash.');
$export = (new WordPressSitePlanView())->compact($view);
$roundTrip = WordPressSitePlanView::materialize($export);
$roundTripDeclaration = current(array_filter($roundTrip['wordpress_site_plan']['runtime_declarations'], static fn (array $row): bool => ThemePreferenceOwnership::DECLARATION_KIND === ($row['kind'] ?? null)));
$assert($hash === ($roundTripDeclaration['payload']['ownership']['runtime_script_sha256'] ?? null), 'canonical compact export/reimport lost the qualified Nick declaration.');

$summary = array(
    'source_path' => $declaration['source_path'] ?? null,
    'runtime_script_path' => $path,
    'runtime_script_sha256' => $hash,
    'whole_block' => $wholeBlock['blockName'],
    'staged_block' => $stagedBlock['blockName'],
    'inert_policy_block' => $inertBlock['blockName'],
    'fallbacks' => array('whole' => count($whole->fallbacks), 'staged' => count($staged->fallbacks), 'inert' => count($inert->fallbacks)),
    'plan_declarations' => count($plan['runtime_declarations'] ?? array()),
    'resolved_runtime_hash' => $resolvedDeclaration['payload']['ownership']['runtime_script_sha256'],
    'export_reimport_runtime_hash' => $roundTripDeclaration['payload']['ownership']['runtime_script_sha256'],
    'storage_key' => $ownership['storage_key'] ?? null,
    'root' => $ownership['root'] ?? array(),
    'browser_observations' => $ownership['observed_transitions'] ?? array(),
);
$outputPath = (string) (getenv('NICK_THEME_HANDOFF_RESULT_PATH') ?: '');
$encoded = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if ('' !== $outputPath) file_put_contents($outputPath, $encoded);
fwrite(STDOUT, $encoded);
