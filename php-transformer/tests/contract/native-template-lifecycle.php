<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$header = '<header><p>Shared site header</p></header>';
$footer = '<footer><p>Shared site footer</p></footer>';
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $header . '<main><h1>Captured homepage</h1></main>' . $footer,
    'about.html' => $header . '<main><h1>Captured about page</h1></main>' . $footer,
));
$plan = (new ArtifactCompiler())->compile($artifact)->toWordPressSitePlanView()['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$templates = array_column($plan['templates'], null, 'slug');
$assert(isset($templates['single'], $templates['archive'], $templates['404']), 'A page-only import still supplies future native WordPress routes.');
$assert(str_contains($templates['single']['canonical_block_markup'], 'wp:post-title') && str_contains($templates['single']['canonical_block_markup'], 'wp:post-content') && !str_contains($templates['single']['canonical_block_markup'], 'wp:query '), 'A newly authored native post renders title/body rather than an archive loop.');
$assert(str_contains($templates['archive']['canonical_block_markup'], '"type":"archive"') && str_contains($templates['archive']['canonical_block_markup'], '"inherit":true') && str_contains($templates['archive']['canonical_block_markup'], 'wp:query-pagination') && str_contains($templates['archive']['canonical_block_markup'], 'wp:query-no-results'), 'Archives inherit the active request and include pagination and no-results semantics.');
$assert(str_contains($templates['404']['canonical_block_markup'], 'Page not found') && str_contains($templates['404']['canonical_block_markup'], 'wp:search ') && !str_contains($templates['404']['canonical_block_markup'], 'wp:query '), 'A missing route offers search recovery instead of listing unrelated posts.');
foreach (array('single', 'archive', '404') as $slug) {
    $assert('generated_native_lifecycle' === $templates[$slug]['source_relation'], 'Native defaults identify their generated source relationship.');
    $assert(!str_contains($templates[$slug]['canonical_block_markup'], 'wp:html'), 'Native defaults do not introduce raw HTML fallback blocks.');
    foreach ($plan['template_parts'] as $part) {
        if ('shared_shell' === ($part['placement']['kind'] ?? null) && in_array('index', $part['placement']['template_slugs'] ?? array(), true)) {
            $assert(in_array($slug, $part['placement']['template_slugs'], true) && str_contains($templates[$slug]['canonical_block_markup'], '"slug":"' . $part['slug'] . '"'), 'Source-proven shared site chrome reaches every native lifecycle route.');
        }
    }
}
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://portable.example/themes/lifecycle'));
$writes = array_column($resolved['writes'], null, 'target_path');
$assert(isset($writes['templates/single.html'], $writes['templates/archive.html'], $writes['templates/404.html']), 'Canonical resolution retains every native template write.');
echo "Native template lifecycle contract passed.\n";
