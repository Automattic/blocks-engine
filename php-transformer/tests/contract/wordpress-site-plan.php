<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};
$throws = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (\InvalidArgumentException) {
        return;
    }
    $assert(false, $message);
};

$artifact = array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<main><h1>Home</h1></main>',
        'about.html' => '<main><h1>About</h1></main>',
        'parts/header.html' => '<header><p>Header</p></header>',
        'assets/site.css' => 'main{color:#123}',
        'visual-repair.css' => '.wp-site-blocks{min-height:100vh}',
    ),
);
$first = ( new ArtifactCompiler() )->compile($artifact)->toArray();
$second = ( new ArtifactCompiler() )->compile($artifact)->toArray();
$plan = $first['source_reports']['wordpress_site_plan'] ?? array();

$assert(WordPressSitePlan::SCHEMA === ($plan['schema'] ?? null), 'Compiler projects the canonical WordPress site plan.');
$assert(str_contains((string) ($plan['pages'][0]['final_block_markup'] ?? ''), '<!-- wp:heading'), 'Pages carry final block markup.');
$assert('parts/header.html' === ($plan['template_parts'][0]['source_path'] ?? null), 'Template parts are projected.');
$assert('assets/site.css' === ($plan['writes'][0]['target_path'] ?? null), 'Theme asset writes retain their safe target paths.');
$assert('utf8' === ($plan['writes'][0]['payload']['encoding'] ?? null), 'Theme asset writes include payload encoding.');
$assert('/' === ($plan['routes'][0]['target_path'] ?? null) && '/about' === ($plan['routes'][1]['target_path'] ?? null), 'Plan retains route target identities.');
$assert('page' === ($plan['pages'][0]['post_type'] ?? null) && true === ($plan['pages'][0]['entrypoint'] ?? false), 'Plan retains page materialization metadata.');
$assert('header' === ($plan['template_parts'][0]['area'] ?? null), 'Plan retains template part area.');
$assert('assets/site.css' === ($plan['assets'][0]['source_path'] ?? null) && 'assets/site.css' === ($plan['assets'][0]['target_path'] ?? null), 'Plan retains canonical asset source and target identities.');
$assert(str_contains((string) ($plan['visual_repair']['css'] ?? ''), 'min-height:100vh'), 'Plan retains visual repair data.');
$assert($plan === ($second['source_reports']['wordpress_site_plan'] ?? null), 'Canonical WordPress site plans are deterministic.');
$assert($first['serialized_blocks'] === $second['serialized_blocks'], 'Projection does not change existing compilation output.');

$malformedSchema = $plan;
$malformedSchema['schema'] = 'blocks-engine/wordpress-site-plan/v0';
$throws(static fn () => WordPressSitePlan::assertValid($malformedSchema), 'Validation rejects unsupported schemas.');
$missingMarkup = $plan;
$missingMarkup['pages'][0]['final_block_markup'] = '';
$throws(static fn () => WordPressSitePlan::assertValid($missingMarkup), 'Validation rejects pages without final block markup.');
$invalidCompiledPage = $first;
$invalidCompiledPage['source_reports']['compiled_site']['pages'][0]['block_markup'] = '';
$throws(static fn () => ( new WordPressSitePlan() )->fromResult($invalidCompiledPage), 'Projection rejects compiled pages without final block markup.');
$invalidCompiledPart = $first;
$invalidCompiledPart['source_reports']['compiled_site']['template_parts'][0]['source_path'] = '';
$throws(static fn () => ( new WordPressSitePlan() )->fromResult($invalidCompiledPart), 'Projection rejects template parts without source identity.');
$unsafePath = $plan;
$unsafePath['writes'][0]['target_path'] = '../escape.css';
$throws(static fn () => WordPressSitePlan::assertValid($unsafePath), 'Validation rejects root-escaping write paths.');
$windowsPath = $plan;
$windowsPath['writes'][0]['target_path'] = 'C:\\theme\\site.css';
$throws(static fn () => WordPressSitePlan::assertValid($windowsPath), 'Validation rejects Windows drive write paths.');
$uncPath = $plan;
$uncPath['writes'][0]['target_path'] = '\\\\server\\share\\site.css';
$throws(static fn () => WordPressSitePlan::assertValid($uncPath), 'Validation rejects UNC write paths.');
$invalidCompiledAsset = $first;
$invalidCompiledAsset['source_reports']['compiled_site']['assets'][0]['target_path'] = 'C:\\theme\\site.css';
$throws(static fn () => ( new WordPressSitePlan() )->fromResult($invalidCompiledAsset), 'Projection rejects Windows asset target paths.');
$invalidEncoding = $plan;
$invalidEncoding['writes'][0]['payload']['encoding'] = 'rot13';
$throws(static fn () => WordPressSitePlan::assertValid($invalidEncoding), 'Validation rejects unsupported payload encodings.');
$invalidWrite = $plan;
unset($invalidWrite['writes'][0]['payload']);
$throws(static fn () => WordPressSitePlan::assertValid($invalidWrite), 'Validation rejects structurally invalid writes.');

fwrite(STDOUT, "wordpress-site-plan contract passed\n");
