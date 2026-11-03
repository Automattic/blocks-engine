<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$artifact = array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => '<html><head><title>Home</title></head><body><p>Welcome</p></body></html>')));
foreach (array('en' => 'Read stories', 'fr' => 'Lire les articles', 'en/audio' => 'Read stories') as $section => $label) {
    foreach (array('first', 'second') as $slug) {
        $title = $section . ' ' . $slug;
        $day = 'first' === $slug ? '1' : '2';
        $article = '<h1>' . $title . '</h1><p><time datetime="2024-03-0' . $day . '">March ' . $day . ', 2024</time></p><p>Unique body for ' . $title . '.</p>';
        if ('en' === $section) {
            $image = 'media/' . $slug . '.png';
            $article = str_replace('<p>Unique body', '<div class="lead-media"><img src="/' . $image . '" alt="' . $title . '"></div><p>Unique body', $article);
            $artifact['files'][] = array('path' => $image, 'content' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        }
        if ('en/audio' === $section) $article = '<div class="episode-layout"><section class="episode-details">' . $article . '</section></div>';
        $artifact['files'][] = array('path' => $section . '/' . $slug . '.html', 'metadata' => array('post_type' => 'post'), 'content' => '<html><head><title>' . $title . '</title><meta property="article:published_time" content="2024-03-0' . $day . 'T00:00:00Z"></head><body><header><a href="/index.html">' . $label . '</a></header><main><article>' . $article . '</article></main><footer><p>Publication footer</p></footer></body></html>');
    }
}
$plan = (new ArtifactCompiler())->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $plan, 'The neutral cluster fixture must produce a valid site plan.');
WordPressSitePlan::assertValid($plan);
$templates = array_column($plan['templates'], null, 'slug');
$bindings = array();
foreach ($plan['pages'] as $post) {
    if ('post' !== $post['post_type']) continue;
    $slug = $post['route_template'] ?? 'single';
    $markup = $templates[$slug]['canonical_block_markup'];
    $assert(str_contains($markup, '<!-- wp:post-title'), 'Every bound template renders its posts\' headline: ' . $slug);
    $assert(str_contains($markup, '<!-- wp:post-date'), 'Every bound template renders its posts\' date: ' . $slug);
    $assert(!str_contains($post['canonical_block_markup'], '<h1'), 'Lifted headlines no longer remain in post content.');
    $episode = str_starts_with($post['source_path'], 'en/audio/');
    $assert($episode === str_contains($markup, 'episode-layout'), 'Each layout retains only its own article wrappers.');
    if ('en' === dirname($post['source_path'])) $assert('assets/media/' . basename($post['source_path'], '.html') . '.png' === ($post['metadata']['featured_image']['target_path'] ?? null), 'The lead-image group resolves each post\'s own featured asset.');
    $bindings[$post['source_path']] = $slug;
}
$assert(6 === count($bindings), 'All six posts survive.');
$assert(3 === count(array_unique($bindings)), 'Two shell clusters and the distinct article layout render through three templates.');
fwrite(STDOUT, "post-chrome-template-clusters: ok\n");
