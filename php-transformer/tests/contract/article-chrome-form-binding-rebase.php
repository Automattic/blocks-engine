<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$roundTrip = static fn(array $value): array => json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

// Factoring the leading hero shortens each post, but its trailing form and
// runtime binding must stay page-owned and anchored on the shortened markup.
$slugs = array('first', 'second', 'third');
$artifact = array('entrypoint' => 'index.html', 'files' => array(array('path' => 'site.css', 'content' => ".post-hero{padding:2rem}\n.signup{max-width:640px}\n")));
$listing = '<ul>';
foreach ($slugs as $i => $slug) {
    $listing .= '<li><a href="' . $slug . '.html">Story ' . $slug . '</a> <time datetime="2024-03-0' . ($i + 1) . '">March ' . ($i + 1) . ', 2024</time></li>';
}
$listing .= '</ul>';
$artifact['files'][] = array('path' => 'index.html', 'content' => '<html><head><title>Journal</title><link rel="stylesheet" href="site.css"></head><body><main><h1>Journal</h1>' . $listing . '</main></body></html>', 'metadata' => array('post_type' => 'page'));
foreach ($slugs as $i => $slug) {
    $html = '<html><head><title>Story ' . $slug . '</title><link rel="stylesheet" href="site.css"></head><body><main><article>'
        . '<div class="post-hero"><p>Journal</p><p>Shared post chrome every story repeats.</p></div>'
        . '<h1>Story ' . $slug . '</h1><p><time datetime="2024-03-0' . ($i + 1) . '">March ' . ($i + 1) . ', 2024</time></p>'
        . '<p>Unique body for ' . $slug . '.</p>'
        . '<form class="signup" action="/register-' . $slug . '" method="post"><label>Email <input type="email" name="email"></label><button type="submit">Register</button></form>'
        . '</article></main></body></html>';
    $artifact['files'][] = array('path' => $slug . '.html', 'content' => $html, 'metadata' => array('post_type' => 'post'));
}

$compiler = new ArtifactCompiler();
$shared = $roundTrip($compiler->prepareShared($artifact));
$prepared = $roundTrip($compiler->preparePages($artifact, $shared));
$receipts = $roundTrip($compiler->compilePreparedPages($shared, $prepared));
$result = $compiler->compose($shared, $receipts)->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
if (array() === $plan) {
    foreach ($result['diagnostics'] ?? array() as $diagnostic) {
        if (str_starts_with((string) ($diagnostic['code'] ?? ''), 'wordpress_site_plan')) fwrite(STDOUT, json_encode($diagnostic, JSON_THROW_ON_ERROR) . "\n");
    }
}
$assert(array() !== $plan, 'Article chrome factoring must produce a canonical plan with rebased page-owned form bindings.');
WordPressSitePlan::assertValid($plan);

$posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === ($page['post_type'] ?? null)));
$assert(3 === count($posts), 'All three dated posts survive composition.');
foreach ($posts as $post) {
    $markup = $post['canonical_block_markup'];
    $assert(!str_contains($markup, 'post-hero') && !str_contains($markup, 'Shared post chrome'), "The hero leaves the post body: {$post['source_path']}.");
    $assert(1 === substr_count($markup, '<form '), "Each post keeps exactly one page-local form: {$post['source_path']}.");
}
$assert(array() !== array_filter($plan['templates'], static fn(array $template): bool => str_contains($template['canonical_block_markup'], 'post-hero') && str_contains($template['canonical_block_markup'], 'Shared post chrome')), 'A template carries the factored hero.');

fwrite(STDOUT, "article-chrome-form-binding-rebase: ok\n");
