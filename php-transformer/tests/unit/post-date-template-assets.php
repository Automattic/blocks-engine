<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$files = array('index.html' => '<html><body><a href="/story.html">Story</a></body></html>');
// Icons on both sides exercise the split's leaf and ancestor serialization.
$icon = '<svg width="12" height="12" viewBox="0 0 12 12"><circle cx="6" cy="6" r="5"></circle></svg>';
$files['story.html'] = '<html><head><title>Story</title><script type="application/ld+json">{"@context":"https://schema.org","@type":"BlogPosting","headline":"Story","datePublished":"2024-01-02"}</script></head><body><main><article><h1>Story</h1><p><span>Published ' . $icon . '</span><time datetime="2024-01-02">January 2, 2024</time><span>' . $icon . ' end</span></p><div class="wp-block-post-content"><p>Story body.</p></div></article></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($plan), 'Date chrome assets must not reject the site plan: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array()));
WordPressSitePlan::assertValid($plan);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$single = $templates['single'] ?? '';
$assert(1 === substr_count($single, '<!-- wp:post-date'), 'The template retains one dynamic date.');
$assert(2 === substr_count($single, '<img'), 'Both label icons survive the date split.');
$assert(!str_contains($single, '%7B%7Bwordpress-site-plan:'), 'No encoded asset placeholder survives.');
$writes = array_column($plan['writes'], null, 'target_path');
$bootstrap = $writes['functions.php']['payload']['data'] ?? '';
foreach ($plan['reference_tokens'] as $reference) {
    $token = WordPressSitePlan::TOKEN_PREFIX . $reference['token'] . '}}';
    if (!str_contains($single, $token)) continue;
    $assert(str_contains($bootstrap, var_export($token, true) . ' => ' . var_export($reference['target_path'], true)), 'Template icons use the ordinary runtime asset resolver.');
}
$assert(str_contains($single, WordPressSitePlan::TOKEN_PREFIX), 'The icons carry declared asset tokens.');
echo "post date template assets: passed\n";
