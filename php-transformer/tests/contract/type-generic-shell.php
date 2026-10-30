<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
foreach (array('Post footer B', '', 'variant') as $case) {
    $files = array();
    foreach (array('index', 'about', 'contact') as $name) {
        $files[$name . '.html'] = '<header><p>Dominant header</p></header><main><h1>' . $name . '</h1><p>Page body</p></main><footer><p>Page footer A</p></footer>';
    }
    $expected = array_fill_keys(array_keys($files), 'Page footer A');
    foreach (array('first', 'second', 'third') as $name) {
        $footer = 'variant' === $case ? ('third' === $name ? 'Different footer C' : 'Post footer B') : $case;
        $path = 'posts/' . $name . '.html';
        $files[$path] = '<header><p>Post header</p></header><main><article><h1>' . $name . '</h1><time itemprop="datePublished" datetime="2026-01-01">January 1</time><p>Post body ' . $name . '</p></article></main>' . ('' === $footer ? '' : '<footer><p>' . $footer . '</p></footer>');
        $expected[$path] = $footer;
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    $templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
    $parts = array_column($plan['template_parts'], 'canonical_block_markup', 'slug');
    foreach ($templates as $slug => $markup) {
        preg_match_all('/<!-- wp:template-part (\{.*?\}) \/-->/', $markup, $matches);
        $references = array_map(static fn(string $json): array => json_decode($json, true), $matches[1]);
        $areas = array_column($references, 'slug', 'area');
        $assert(isset($areas['header'], $parts[$areas['header']]), $case . ': ' . $slug . ' references a declared header part');
        $assert(!str_contains($markup, 'Dominant header') && !str_contains($markup, 'Post header') && !str_contains($markup, 'footer A') && !str_contains($markup, 'footer B'), $case . ': ' . $slug . ' does not inline resolved shell markup');
        // A unique route variant stays at its authored position in post-content;
        // absent source areas must not acquire a fallback footer either.
        if (!(('' === $case && 'single' === $slug) || 'single-post-third' === $slug)) {
            $assert(isset($areas['footer'], $parts[$areas['footer']]), $case . ': ' . $slug . ' references a declared footer part');
        }
        if (in_array($slug, array('front-page', 'page'), true)) {
            $assert('header' === ($areas['header'] ?? null) && 'footer' === ($areas['footer'] ?? null), $case . ': ' . $slug . ' keeps the dominant header/footer slugs');
        }
    }
    $expand = static function (string $markup) use ($parts): string {
        return preg_replace_callback('/<!-- wp:template-part (\{.*?\}) \/-->/', static fn(array $match): string => $parts[json_decode($match[1], true)['slug']] ?? $match[0], $markup);
    };
    $overrides = array_values(array_filter(array_keys($templates), static fn(string $slug): bool => str_starts_with($slug, 'single-post-')));
    $assert(('variant' === $case ? array('single-post-third') : array()) === $overrides, $case . ': only genuinely different posts need overrides: ' . implode(', ', $overrides));
    $single = $expand($templates['single']);
    $assert(!str_contains($single, 'Page footer A') && ('' === $case || str_contains($single, 'Post footer B')), $case . ': single uses the posts common footer');
    $assert(str_contains($expand($templates['page']), 'Page footer A'), $case . ': page uses footer A');
    foreach ($plan['pages'] as $page) {
        $isPost = 'post' === $page['post_type'];
        $generic = $isPost ? 'single' : 'page';
        $route = ($isPost ? 'single-post-' : 'page-') . $page['slug'];
        $template = !empty($page['entrypoint']) ? 'front-page' : (isset($templates[$route]) ? $route : $generic);
        $rendered = $expand(preg_replace('/<!-- wp:post-content(?: \{.*?\})? \/-->/', addcslashes($page['canonical_block_markup'], '\\$'), $templates[$template]));
        foreach (array('Page footer A', 'Post footer B', 'Different footer C') as $footer) {
            $assert(substr_count($rendered, $footer) === ($expected[$page['source_path']] === $footer ? 1 : 0), $case . ': source footer preserved exactly once for ' . $page['source_path']);
        }
    }
}
// Match the integration capture: pages share both areas, while the sole
// captured post has no shell. No WordPress runtime is needed to prove that
// front-page still exposes the portable header reference.
$files = array();
foreach (array('index', 'about', 'nested/about') as $name) {
    $files[$name . '.html'] = '<header><p>Integration Header</p></header><main><h1>' . $name . '</h1></main><footer><p>Integration Footer</p></footer>';
}
$files['notes/essay.html'] = '<meta property="article:published_time" content="2024-03-02T10:30:00Z"><main><article>Essay</article></main>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$parts = array_column($plan['template_parts'], 'canonical_block_markup', 'slug');
foreach (array('front-page', 'page') as $slug) {
    foreach (array('header', 'footer') as $area) {
        $assert(isset($parts[$area]) && str_contains($templates[$slug], '<!-- wp:template-part {"slug":"' . $area . '"'), $slug . ' exposes the shared ' . $area . ' template-part reference');
    }
    $assert(!str_contains($templates[$slug], 'Integration Header'), $slug . ' does not inline the resolved header');
}
$assert(!str_contains($templates['single'], 'wp:template-part'), 'A shell-less post does not inherit the dominant shell');
echo "type-generic-shell: passed\n";
