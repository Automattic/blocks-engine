<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$files = array('index.html' => '<html><head><title>Journal</title></head><body><p>Journal home</p></body></html>');
foreach (array('first', 'second', 'third') as $index => $slug) {
    $day = $index + 1;
    $files[$slug . '.html'] = '<html><head><title>' . $slug . ' story</title><meta property="og:type" content="article"><meta property="article:published_time" content="2024-03-0' . $day . '"></head><body><main><article><h1>' . $slug . ' story</h1><div class="post-date"><div>March ' . $day . ', 2024</div></div><div class="post-body"><div class="lead-media"><img src="/media/' . $slug . '.png" srcset="/media/' . $slug . '.png 500w, /media/' . $slug . '.png 1080w" sizes="100vw" alt="' . $slug . ' image"></div><div class="article-text"><p>Unique body for ' . $slug . '.</p></div></div></article></main></body></html>';
    $files['media/' . $slug . '.png'] = $png;
}
$plan = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray());
WordPressSitePlan::assertValid($plan);
$single = '';
foreach ($plan['templates'] as $template) if ('single' === $template['slug']) $single = $template['canonical_block_markup'];
$assert(str_contains($single, '<!-- wp:post-featured-image'), 'The single template must bind the responsive lead image, not freeze it as chrome.');
$assert(!preg_match('/<(?:img|picture)\b/i', $single), 'The single template contains no literal post image.');
$assert(str_contains($single, 'wp:post-title') && str_contains($single, 'wp:post-date'), 'Title and wrapper-class date still lift.');
$posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === $page['post_type']));
$assert(3 === count($posts), 'All three posts survive.');
foreach ($posts as $post) {
    $slug = basename($post['source_path'], '.html');
    $assert('assets/media/' . $slug . '.png' === ($post['metadata']['featured_image']['target_path'] ?? null), 'Each post owns its own featured asset.');
    $assert($slug . ' image' === ($post['metadata']['featured_image']['alt'] ?? null), 'The image handoff preserves alt text.');
    $assert(str_contains($post['canonical_block_markup'], 'Unique body for ' . $slug) && !preg_match('/<(?:img|picture|h1)\b|March [123], 2024/i', $post['canonical_block_markup']), 'Only the article body remains in post content.');
}
foreach (array('/unowned/', 'https://example.org/unowned/') as $prefix) {
    $unowned = $files;
    foreach (array('first', 'second', 'third') as $slug) {
        unset($unowned['media/' . $slug . '.png']);
        $unowned[$slug . '.html'] = str_replace(array('/media/' . $slug . '.png', $slug . ' image'), array($prefix . 'lead.png', ''), $unowned[$slug . '.html']);
    }
    $fallback = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $unowned))->toArray());
    $single = array_values(array_filter($fallback['templates'], static fn(array $template): bool => 'single' === $template['slug']))[0]['canonical_block_markup'];
    $posts = array_values(array_filter($fallback['pages'], static fn(array $page): bool => 'post' === $page['post_type']));
    $assert(!preg_match('/<(?:img|picture)\b|wp:post-featured-image/i', $single) && 3 === count(array_filter($posts, static fn(array $post): bool => str_contains($post['canonical_block_markup'], 'wp:custom/responsive-media') && str_contains($post['canonical_block_markup'], 'Unique body for ' . basename($post['source_path'], '.html')) && !isset($post['metadata']['featured_image']))), 'Lead images without plan-owned assets stay in each post body, never as constants in single or featured-image bindings.');
}
fwrite(STDOUT, "single-responsive-lead-image: ok\n");
