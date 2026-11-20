<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$files = array('index.html' => '<main><ul><li><a href="/news/one/">One award</a></li><li><a href="/news/two/">Two award</a></li><li><a href="/news/three/">Three award</a></li></ul></main>');
foreach (array('One award', 'Two award', 'Three award') as $i => $title) {
    $day = $i + 1;
    $slug = strtolower(strtok($title, ' '));
    $files['news/' . $slug . '/index.html'] = '<html><head><title>' . $title . ' | Dept</title><meta property="og:type" content="article"></head><body><header><a href="/">Dept</a></header>'
        . '<div id="content" class="column" role="main"><nav class="breadcrumb"><ol><li><a href="/">Home</a> › </li><li><a href="/news/">News</a> › </li><li>' . $title . '</li></ol></nav>'
        . '<a id="main-content"></a><h1 class="page__title title">' . $title . '</h1>'
        . '<article class="node node-news"><div class="field-date"><span class="date-display-single" content="2025-01-0' . $day . 'T00:00:00-08:00">Wednesday, January ' . $day . ', 2025</span></div>'
        . '<div class="field-body"><p>First paragraph of ' . $title . '.</p><p>Second paragraph of ' . $title . '.</p></div></article></div>'
        . '<footer><p>© Dept</p></footer></body></html>';
}
$plan = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray());
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$posts = array_filter($plan['pages'], static fn(array $page): bool => 'post' === $page['post_type']);
$assert(3 === count($posts), 'All three news articles must become posts.');
foreach ($posts as $post) {
    $markup = $templates[$post['route_template'] ?? 'single'];
    $content = strpos($markup, '<!-- wp:post-content');
    $title = strpos($markup, '<!-- wp:post-title');
    $assert(false !== $content && false !== $title && $title < $content, 'The heading title must precede post content.');
    $document = new DOMDocument();
    @$document->loadHTML($markup);
    $xpath = new DOMXPath($document);
    $assert(0 === $xpath->query('//nav//comment()[contains(., "wp:post-content")] | //ol//comment()[contains(., "wp:post-content")] | //ul//comment()[contains(., "wp:post-content")]')->length, 'Post content must never render inside navigation or a list.');
    $assert(1 === $xpath->query('//nav[contains(@class, "breadcrumb")]//ol')->length, 'Keep the authored breadcrumb list.');
    $assert(3 === $xpath->query('//nav[contains(@class, "breadcrumb")]//li')->length, 'Keep all three breadcrumb items.');
    $assert(str_contains($markup, '"source":"core/post-data","args":{"field":"title"}'), 'The last breadcrumb item binds to the current post title.');
    $assert(str_contains($markup, '"level":1,"className":"page__title title"'), 'The post title replaces the authored H1.');
    $assert(strpos($markup, '<article') > $title && strpos($markup, '<article') < $content, 'The article wrapper remains after the H1 and owns the body slot.');
    $assert(str_contains($markup, 'field-date') && strpos($markup, 'field-date') < $content, 'The article date remains ahead of the body.');
    $assert(str_contains($post['canonical_block_markup'], 'First paragraph of ' . $post['title']) && str_contains($post['canonical_block_markup'], 'Second paragraph of ' . $post['title']), 'Each post retains both article paragraphs.');
}
echo "post-chrome-breadcrumb-title: ok\n";
