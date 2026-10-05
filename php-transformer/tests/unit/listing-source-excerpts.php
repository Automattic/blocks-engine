<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
$first = "Author's summary " . implode(' ', array_fill(0, 70, 'detail')) . '.';
$second = 'Second authored description, independent of the much longer article.';
$card = static fn(string $slug, string $title, string $date, string $excerpt, string $label): string => '<article class="card"><a class="cover-link" href="/' . $slug . '.html" aria-hidden="true" tabindex="-1"></a><div class="metadata"><div class="labels"><a href="/category/topic/">' . $label . '</a></div><p><time datetime="' . date('Y-m-d', strtotime($date)) . '">' . $date . '</time></p></div><h2><a class="active-link" href="/' . $slug . '.html">' . $title . '</a></h2><p class="description" style="font-size:14px;line-height:22px">' . htmlspecialchars($excerpt, ENT_QUOTES) . '</p></article>';
$article = static fn(string $title, string $description, string $date): string => '<html><head><title>' . $title . '</title><meta property="og:type" content="article"><meta name="description" content="' . htmlspecialchars($description, ENT_QUOTES) . '"><meta property="article:published_time" content="' . $date . '"></head><body><main><h1>' . $title . '</h1><p>' . str_repeat('LONG ARTICLE BODY. ', 200) . '</p></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<style>@layer reset{a{text-decoration:inherit}}.labels{display:flex}.cover-link{position:absolute;inset:0}:where(.cards>:not(:last-child)){margin-bottom:24px}@media(min-width:600px){:where(.cards>:not(:last-child)){margin-bottom:32px}}</style><main><section class="cards">' . $card('first', 'First', 'Jan 2, 2026', $first, 'Topic') . $card('second', 'Second', 'Jan 1, 2026', $second, 'Other label') . '</section></main>',
    'first.html' => $article('First', $first, '2026-01-02T00:00:00Z'),
    'second.html' => $article('Second', $second, '2026-01-01T00:00:00Z'),
    'category/topic/index.html' => '<main><h1>Topic</h1></main>',
)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$pages = array_column($plan['pages'], null, 'source_path');
$markup = $pages['index.html']['canonical_block_markup'];
if (!str_contains($markup, 'wp:post-excerpt') || str_contains($markup, 'wp:post-content')) fwrite(STDERR, $markup . "\n" . json_encode(array_map(static fn(array $page): array => array_intersect_key($page, array_flip(array('source_path', 'post_type', 'metadata'))), $pages)) . "\n");
$assert(str_contains($markup, 'wp:query') && str_contains($markup, 'wp:post-excerpt') && !str_contains($markup, 'wp:post-content'), 'compact descriptions use an excerpt slot, never the entire article');
$assert(($pages['first.html']['metadata']['excerpt'] ?? null) === $first && ($pages['second.html']['metadata']['excerpt'] ?? null) === $second, 'all authored descriptions are handed off without truncation');
$assert(str_contains($pages['first.html']['canonical_block_markup'], 'LONG ARTICLE BODY'), 'full article content stays on its own post');
$assert(str_contains($markup, 'description') && str_contains($markup, 'fontSize') && str_contains($markup, 'lineHeight'), 'source description presentation stays on native excerpt');
$assert(str_contains($markup, 'Topic') && str_contains($markup, 'wp:post-date') && str_contains($markup, 'wp:post-title'), 'metadata does not consume the description field');
$assert(str_contains($markup, '"excerptLength":72'), 'native excerpt bound comes from authored words rather than a fixed default');
$assert(str_contains($markup, 'core/post-meta') && count($pages['first.html']['metadata']['post_meta'] ?? array()) === 1 && str_contains(implode('', $pages['second.html']['metadata']['post_meta'] ?? array()), 'Other label'), 'linked metadata belongs to each post, not the first repeated card');
$assert(str_contains($markup, 'blocks-engine-synthetic-anchor-undecorated'), 'ordinary metadata links retain source-proved inherited decoration resets');
$wrapped = (new HtmlTransformer())->transform('<style>@layer base{a{text-decoration:inherit}}.labels{display:flex}</style><span class="labels"><a class="muted" href="/topic">Topic</a></span>')->toArray();
$assert(str_contains($wrapped['serialized_blocks'], 'blocks-engine-synthetic-anchor-undecorated'), 'lowered inline wrappers keep anchor decoration ownership on their synthetic paragraph');
$assert(str_contains($markup, 'wp:read-more') && str_contains($markup, 'blocks-engine-listing-overlay') && str_contains($markup, 'active-link'), 'card overlay resolves its own post permalink and title retains source interaction classes');
$bootstrap = '';
foreach ($plan['writes'] as $write) if ('functions.php' === ($write['target_path'] ?? '')) $bootstrap = (string) ($write['payload']['data'] ?? '');
$assert(str_contains($bootstrap, '.editor-styles-wrapper .blocks-engine-listing-overlay{display:none}') && str_contains($bootstrap, '.editor-styles-wrapper .blocks-engine-listing-bound-meta{display:none}') && str_contains($markup, 'blocks-engine-listing-bound-meta') && str_contains($bootstrap, "'aria-hidden'") && str_contains($bootstrap, "'tabindex'"), 'editor projection withholds listing chrome and unresolved bound-meta placeholders without changing frontend link semantics');
$css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === $asset['kind']), 'content'));
if (!str_contains($css, '.wp-block-post-template)>:where(li)')) fwrite(STDERR, $css . "\n");
$assert(str_contains($css, '.blocks-engine-listing-query)>:where(.wp-block-post-template)>:where(li):not(:last-child)>:where(article)') && str_contains($css, 'margin-bottom:32px'), 'source child spacing and conditional cascade replay across native transport wrappers');
$body = '<p>Full article body with <a href="https://example.test/reference">an external reference</a> and substantial independent text.</p>';
$full = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><section>' . str_replace('</article>', $body . '</article>', $card('first', 'First', 'Jan 2, 2026', $first, 'Topic')) . str_replace('</article>', $body . '</article>', $card('second', 'Second', 'Jan 1, 2026', $second, 'Topic')) . '</section></main>',
    'first.html' => str_replace('</main>', $body . '</main>', $article('First', $first, '2026-01-02T00:00:00Z')),
    'second.html' => str_replace('</main>', $body . '</main>', $article('Second', $second, '2026-01-01T00:00:00Z')),
    'category/topic/index.html' => '<main><h1>Topic</h1></main>',
)))->toArray();
$fullPages = array_column((new WordPressSitePlan())->fromCompilerResult($full)['pages'], null, 'source_path');
$assert(str_contains($fullPages['index.html']['canonical_block_markup'], 'wp:post-content') && !str_contains($fullPages['index.html']['canonical_block_markup'], 'wp:post-excerpt'), 'a full-content listing retains article ownership even when its post declares a description');
fwrite(STDOUT, "Listing source excerpts passed\n");
