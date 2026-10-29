<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
// Neutral reciprocal tag archive, with the unbindable card text from #3636.
$header = '<header><nav><a href="/">Field Journal</a></nav></header>';
$footer = '<footer><p>Field Journal footer</p></footer>';
$files = array('index.html' => $header . '<main><h1>Home</h1></main>' . $footer);
$cards = '';
foreach (array('one', 'two') as $index => $slug) {
    $title = 'Field report ' . $slug;
    $cards .= '<article class="card"><a href="/posts/' . $slug . '/"><div class="image-wrap"><img src="/images/' . $slug . '.png" alt="' . $title . '"><div class="badge" aria-hidden="true">#' . (701 + $index) . '</div></div></a><div><h2><a href="/posts/' . $slug . '/">' . $title . '</a></h2><p>Summary ' . $slug . '.</p></div></article>';
    $files['images/' . $slug . '.png'] = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
    $files['posts/' . $slug . '/index.html'] = '<html><head><meta name="description" content="Summary ' . $slug . '."></head><body>' . $header . '<main><article itemscope itemtype="https://schema.org/BlogPosting"><h1 itemprop="headline">' . $title . '</h1><p>Full report ' . $slug . '.</p><a href="/tag/field-notes/">Field Notes</a></article></main>' . $footer;
}
$files['tag/field-notes/index.html'] = $header . '<main><h1>Field Notes</h1><p>Dispatches from the open trail.</p><section>' . $cards . '</section></main>' . $footer;
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$assert(1 === count($plan['taxonomy_entities']), 'Reciprocal membership proves the tag.');
$writes = array_column($plan['writes'], null, 'target_path');
$markup = $writes['templates/tag-field-notes.html']['payload']['data'] ?? '';
foreach (array('Dispatches from the open trail.', 'Field report one', 'Field report two', '#701', '#702', '"slug":"header"', '"slug":"footer"') as $text) {
    $assert(str_contains($markup, $text), 'Archive template retains ' . $text);
}
$assert(!str_contains($markup, 'wp:post-content') && !str_contains($markup, 'wp:query'), 'Captured cards are static, never post bodies or a live query.');
$diagnostics = array_values(array_filter($plan['diagnostics'], static fn(array $d): bool => 'wordpress_site_plan_taxonomy_archive_presentation_static' === $d['code']));
$assert(1 === count($diagnostics) && 'warning' === $diagnostics[0]['severity'] && 'tag/field-notes/index.html' === $diagnostics[0]['source_path'] && '/tag/field-notes' === $diagnostics[0]['source_route'], 'Static archive warning is source-owned.');
$generic = $writes['templates/archive.html']['payload']['data'];
$assert(str_contains($generic, '"slug":"header"') && str_contains($generic, '"slug":"footer"'), 'Generic archive includes both shell parts.');
WordPressSitePlan::assertValid($plan);
echo "tag archive static badges: passed\n";
