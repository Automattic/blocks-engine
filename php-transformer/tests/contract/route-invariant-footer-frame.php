<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$document = static fn(string $copy, string $extra = ''): string => '<!doctype html><html><head><style>.site-wrapper{min-height:100vh;padding:24px}.site-main{max-width:640px;margin:auto}.inner{display:flex;flex-direction:column;gap:20px}.inner>section{padding:30px}footer{padding:12px}</style></head><body><div class="site-wrapper"><div class="site-main"><div class="inner' . $extra . '"><section><p>' . $copy . '</p></section><section><p>More ' . $copy . '</p></section><footer><p>Shared footer</p></footer></div></div></div></body></html>';
$compile = static fn(array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$files = array('index.html' => $document('Welcome'), 'about.html' => $document('About'), 'contact.html' => $document('Contact'));
$plan = $compile($files);
WordPressSitePlan::assertValid($plan);
foreach ($plan['pages'] as $page) {
    if (str_contains($page['canonical_block_markup'], 'wp:template-part') || str_contains($page['canonical_block_markup'], 'site-wrapper') || str_contains($page['canonical_block_markup'], 'site-main') || str_contains($page['canonical_block_markup'], 'inner')) throw new RuntimeException('Only page-specific sections belong in post content.');
}
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
foreach (array('front-page', 'page', 'single') as $slug) {
    // front-page is emitted only when it differs from page.
    if ('front-page' === $slug && !isset($templates[$slug])) continue;
    $markup = $templates[$slug] ?? '';
    if (!str_contains($markup, 'site-wrapper') || !str_contains($markup, 'site-main') || !str_contains($markup, 'inner') || !str_contains($markup, '"slug":"footer"') || ('single' !== $slug && !str_contains($markup, WordPressSitePlan::FRAME_CONTENT_CLASS))) throw new RuntimeException('The template owns the wrapper chain, neutral content slot and footer: ' . $slug);
}
// Route-varying chains cannot use cross-route structure as content evidence.
$files['contact.html'] = $document('Contact', ' different');
$varying = $compile($files);
foreach ($varying['pages'] as $page) {
    if (!str_contains($page['canonical_block_markup'], 'wp:template-part')) throw new RuntimeException('Varying chains retain in-place transport.');
}
echo "route-invariant-footer-frame: ok\n";
