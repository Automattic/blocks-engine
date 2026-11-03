<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$artifact = array('entrypoint' => 'index.html', 'files' => array());
$rows = array(
    'index' => array('Welcome', 'Publication', 'page'),
    'first' => array('Research methods', 'A guide to research &amp; discovery', 'post'),
    'second' => array('Planning tips', 'How to plan a useful study', 'post'),
    'about' => array('About the publication', 'Meet the team', 'page'),
    'untitled' => array('Field notes', '', 'post'),
    'ambiguous' => array('Collected notes', 'First topic</h1><h1>Second topic', 'post'),
);
foreach ($rows as $slug => [$seo, $headline, $type]) {
    $artifact['files'][] = array(
        'path' => $slug . '.html',
        'metadata' => array('post_type' => $type),
        'content' => '<html><head><title>' . $seo . ' | Neutral Journal</title></head><body><header><p>Neutral Journal</p></header><main><article>'
            . ('' === $headline ? '' : '<h1>' . $headline . '</h1>')
            . '<p><time datetime="2024-03-01">March 1, 2024</time></p><p>Unique body for ' . $slug . '.</p></article></main><footer><p>Publication footer</p></footer></body></html>',
    );
}
$compiler = new ArtifactCompiler();
// The two-post acceptance fixture must lift the headline into the single template.
$postsArtifact = $artifact;
$postsArtifact['files'] = array_slice($artifact['files'], 0, 3);
$plan = $compiler->compile($postsArtifact)->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $plan, 'Compilation produces a site plan.');
WordPressSitePlan::assertValid($plan);
foreach ($plan['pages'] as $page) {
    if ('post' !== $page['post_type']) continue;
    [$seo, $headline] = $rows[pathinfo($page['source_path'], PATHINFO_FILENAME)];
    $assert(html_entity_decode($headline, ENT_QUOTES | ENT_HTML5, 'UTF-8') === $page['title'], 'The visible headline names each post, not its SEO title.');
    $assert($seo . ' | Neutral Journal' === $page['document_metadata']['title'], 'The original document title remains available as SEO metadata.');
    $assert(!preg_match('/<h1\b/i', $page['canonical_block_markup']), 'The lifted H1 is absent from post content.');
}
$single = array_values(array_filter($plan['templates'], static fn(array $template): bool => 'single' === $template['slug']));
$assert(1 === count($single) && str_contains($single[0]['canonical_block_markup'], '<!-- wp:post-title'), 'The single template renders the native post title.');

$fallbackPlan = $compiler->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$fallbacks = array_column($fallbackPlan['pages'], 'title', 'source_path');
$assert('About the publication' === ($fallbacks['about.html'] ?? null), 'Pages retain existing title derivation.');
$assert('Field notes' === ($fallbacks['untitled.html'] ?? null), 'Posts without an H1 retain existing title derivation.');
$assert('Collected notes' === ($fallbacks['ambiguous.html'] ?? null), 'Posts with multiple H1s retain existing title derivation.');
fwrite(STDOUT, "post-headline-title: ok\n");
