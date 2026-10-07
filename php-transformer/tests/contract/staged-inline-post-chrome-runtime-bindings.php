<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$roundTrip = static fn(array $value): array => json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

// Neutral dated listed posts. A repeated nested newsletter-compact article
// shell compiles per post with a distinct generated document marker (the
// `main p` author rule needs a per-document source-tag hook), and a
// page-local newsletter-controls form sits inside the shared shell. Shell
// identity strips the markers, so `sharedPostChromeIdentities` recognizes the
// repeated ancestors and article factoring wants to move the whole shell --
// form anchors included -- into one global single template while every form
// declaration keeps its post as the owner document.
$slugs = array('first', 'second', 'third');
$titles = array('index' => 'Journal', 'first' => 'First story', 'second' => 'Second story', 'third' => 'Third story');
$dates = array('first' => array('2024-03-01', 'March 1, 2024'), 'second' => array('2024-03-02', 'March 2, 2024'), 'third' => array('2024-03-03', 'March 3, 2024'));
$artifact = array('entrypoint' => 'index.html', 'files' => array());
$artifact['files'][] = array('path' => 'site.css', 'content' => "main p{line-height:1.7}\n.newsletter-compact{max-width:640px}\n");
$latest = '<ul class="post-list">';
foreach ($slugs as $slug) {
    $latest .= '<li><a href="' . $slug . '.html">' . $titles[$slug] . '</a> <time datetime="' . $dates[$slug][0] . '">' . $dates[$slug][1] . '</time></li>';
}
$latest .= '</ul>';
foreach ($titles as $slug => $title) {
    if ('index' === $slug) {
        $html = '<html><head><title>' . $title . '</title><link rel="stylesheet" href="site.css"></head><body><main><h1>' . $title . '</h1>' . $latest . '</main></body></html>';
        $artifact['files'][] = array('path' => 'index.html', 'content' => $html, 'metadata' => array('post_type' => 'page'));
        continue;
    }
    $form = '<form class="newsletter-controls" action="/subscribe" method="post"><label>Email <input type="email" name="email"></label><button type="submit">Subscribe</button></form>';
    $html = '<html><head><title>' . $title . '</title><link rel="stylesheet" href="site.css"></head><body><main><article>'
        . '<h1>' . $title . '</h1>'
        . '<p><time datetime="' . $dates[$slug][0] . '">' . $dates[$slug][1] . '</time></p>'
        . '<div class="newsletter-compact"><p>Join the dispatch.</p>' . $form . '</div>'
        . '<p>Unique body for ' . $slug . '.</p>'
        . '</article></main></body></html>';
    $artifact['files'][] = array('path' => $slug . '.html', 'content' => $html, 'metadata' => array('post_type' => 'post'));
}

$compiler = new ArtifactCompiler();
$shared = $roundTrip($compiler->prepareShared($artifact));
$prepared = $roundTrip($compiler->preparePages($artifact, $shared));
$receipts = $roundTrip($compiler->compilePreparedPages($shared, $prepared));
fwrite(STDOUT, "Compiled dated post receipts; composing with reversed receipt order.\n");
$result = $compiler->compose($shared, array_reverse($receipts))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
if (array() === $plan) {
    foreach ($result['diagnostics'] ?? array() as $diagnostic) {
        if ('wordpress_site_plan' === substr((string) ($diagnostic['code'] ?? ''), 0, 19)) fwrite(STDOUT, json_encode($diagnostic, JSON_THROW_ON_ERROR) . "\n");
    }
}
$assert(array() !== $plan, 'Staged composition must produce a canonical plan, not a wordpress_site_plan_not_self_contained placement diagnostic.');
WordPressSitePlan::assertValid($plan);

$posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === ($page['post_type'] ?? null)));
$assert(3 === count($posts), 'All three dated posts survive composition: ' . json_encode(array_column($plan['pages'], 'post_type')));
$postMarkupBySource = array_column($posts, 'canonical_block_markup', 'source_path');
foreach ($posts as $post) {
    $markup = $post['canonical_block_markup'];
    $assert(str_contains($markup, 'newsletter-compact'), "The complete article stays page-owned for {$post['source_path']}.");
    $assert(1 === substr_count($markup, 'newsletter-controls'), "Each post keeps exactly one page-local newsletter form: {$post['source_path']}.");
    $assert(str_contains($markup, '<time datetime="' . $dates[basename((string) $post['source_path'], '.html')][0] . '"'), "The retained article keeps its visible date: {$post['source_path']}.");
}
foreach ($plan['templates'] as $template) {
    $assert(!str_contains($template['canonical_block_markup'], 'newsletter-controls'), 'No template carries a page-local newsletter form, so the form is never duplicated behind a shared template.');
}
$assert(array() === array_filter($plan['template_parts'], static fn(array $part): bool => str_contains($part['canonical_block_markup'], 'newsletter-controls')), 'No template part owns a page-local newsletter form either.');

// Every form binding keeps an exact occurrence and position anchor on its own
// owner document, inline or manifest-backed.
$manifestRecords = RuntimeEntityManifest::normalizeRecords($plan['runtime_entity_records']);
$materialized = RuntimeDeclarations::materialize($plan['runtime_declarations'], RuntimeDeclarations::normalizeRecords($plan['runtime_records'] ?? array()));
$bindingCount = 0;
foreach ($materialized as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $binding) {
    $source = $binding['source_path'] ?? null;
    $search = $binding['search_block_markup'] ?? null;
    if (!is_string($source) || !isset($postMarkupBySource[$source]) || !is_string($search) || '' === $search) continue;
    $markup = $postMarkupBySource[$source];
    $position = $binding['position'] ?? null;
    $assert(true === WordPressSitePlan::bindingPosition($position, $markup, $search), "The binding anchors one exact block of {$source}.");
    $occurrenceOffset = null;
    $cursor = 0;
    $seen = 0;
    while (false !== ($found = strpos($markup, $search, $cursor))) {
        ++$seen;
        if ($seen === $binding['occurrence']) { $occurrenceOffset = $found; break; }
        $cursor = $found + strlen($search);
    }
    $assert(null !== $occurrenceOffset && $occurrenceOffset === $position['offset'], "The binding occurrence maps onto its exact position offset in {$source}.");
    ++$bindingCount;
}
$assert(0 < $bindingCount, 'The fixture declares runtime form bindings that the retained articles must keep.');

foreach ($plan['runtime_declarations'] as $declaration) {
    if (RuntimeEntityManifest::SCHEMA !== ($declaration['payload']['schema'] ?? null)) continue;
    foreach (RuntimeEntityManifest::resolve($declaration['payload'], $manifestRecords) as $entity) foreach ($entity['bindings'] ?? array() as $binding) {
        $source = $binding['source_path'] ?? null;
        if (!isset($postMarkupBySource[$source])) continue;
        $assert(true === WordPressSitePlan::bindingPosition($binding['position'] ?? null, $postMarkupBySource[$source], (string) $binding['search_block_markup']), "A manifest-backed binding anchors exactly on {$source}.");
    }
}

// The whole driver plans the same pages, parts, and anchors: one conversion,
// two drivers, and the retained post-chrome ownership does not depend on which
// one ran.
$whole = $roundTrip($compiler->compile($artifact)->toArray())['source_reports']['wordpress_site_plan'] ?? array();
WordPressSitePlan::assertValid($whole);
$normalize = static fn(string $markup): string => preg_replace('/blocks-engine-[a-z-]+-[0-9a-f]{12}-\d+/', 'blocks-engine-marker', $markup) ?? $markup;
$byPath = static function (array $plan) use ($normalize): array {
    $pages = array();
    foreach ($plan['pages'] ?? array() as $page) $pages[$page['source_path']] = $normalize((string) $page['canonical_block_markup']);
    ksort($pages);
    return $pages;
};
$assert(array_keys($byPath($plan)) === array_keys($byPath($whole)), 'Both drivers plan the same pages.');
$wholePages = $byPath($whole);
foreach ($byPath($plan) as $path => $markup) {
    $assert($markup === ($wholePages[$path] ?? ''), "Both drivers retain the same article for {$path}.");
    if ('.html' === substr($path, -5) && isset($dates[basename($path, '.html')])) {
        $assert(1 === substr_count($markup, 'newsletter-controls'), "Both drivers keep exactly one page-local form for {$path}.");
    }
}
$partSlugs = static fn(array $plan): array => array_values(array_map(static fn(array $part): string => (string) $part['slug'], $plan['template_parts'] ?? array()));
$planSlugs = $partSlugs($plan);
$wholeSlugs = $partSlugs($whole);
sort($planSlugs);
sort($wholeSlugs);
$assert($planSlugs === $wholeSlugs, 'Both drivers extract the same template parts.');
$anchors = static function (array $plan) use ($normalize): array {
    $anchors = array();
    foreach (RuntimeDeclarations::materialize($plan['runtime_declarations'], RuntimeDeclarations::normalizeRecords($plan['runtime_records'] ?? array())) as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $binding) {
        $source = $binding['source_path'] ?? null;
        if (!is_string($source)) continue;
        $anchors[$source . "\n" . $normalize((string) $binding['search_block_markup'])] = $binding['position'] ?? null;
    }
    ksort($anchors);
    return $anchors;
};
$assert($anchors($plan) === $anchors($whole), 'Both drivers anchor every runtime entity binding on the same exact blocks.');

// Unit view of the factoring decision: a bound newsletter form strands, a
// bound content block does not, and unbound posts still factor.
$makePost = static fn(string $title, string $date, string $body): string => '<!-- wp:group {"tagName":"article"} --><article class="wp-block-group"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">' . $title . '</h1><!-- /wp:heading --><!-- wp:paragraph --><p><time datetime="' . $date . '">' . $date . '</time></p><!-- /wp:paragraph --><!-- wp:group {"className":"newsletter-compact"} --><div class="wp-block-group newsletter-compact"><!-- wp:paragraph --><p>Join the dispatch.</p><!-- /wp:paragraph --><!-- wp:html --><form class="newsletter-controls" action="/subscribe" method="post"><label>Email <input type="email" name="email"></label><button type="submit">Subscribe</button></form><!-- /wp:html --></div><!-- /wp:group --><!-- wp:paragraph --><p>' . $body . '</p><!-- /wp:paragraph --></article><!-- /wp:group -->';
$unitPosts = array();
foreach (array(array('first.html', 'First story', '2024-03-01', 'Unique body for first.'), array('second.html', 'Second story', '2024-03-02', 'Unique body for second.')) as $row) {
    $unitPosts[] = array('source_path' => $row[0], 'post_type' => 'post', 'title' => $row[1], 'publication_timestamp' => $row[2], 'route' => array('path' => '/' . basename($row[0], '.html')), 'canonical_block_markup' => $makePost($row[1], $row[2], $row[3]));
}
$form = '<!-- wp:html --><form class="newsletter-controls" action="/subscribe" method="post"><label>Email <input type="email" name="email"></label><button type="submit">Subscribe</button></form><!-- /wp:html -->';
$boundEntities = array();
foreach ($unitPosts as $post) {
    $markup = $post['canonical_block_markup'];
    $offset = strpos($markup, $form);
    $assert(false !== $offset, 'The unit fixture contains the bound form block.');
    $index = null;
    foreach (WordPressSitePlan::blockRanges($markup) as $blockIndex => $range) if ($range['offset'] === $offset) $index = $blockIndex;
    $assert(null !== $index, 'The bound form block resolves to one emitted block position.');
    $boundEntities[] = array('bindings' => array(array('role' => 'form', 'source_path' => $post['source_path'], 'search_block_markup' => $form, 'occurrence' => 1, 'position' => array('schema' => 'blocks-engine/runtime-binding-position/v1', 'block_index' => $index, 'offset' => $offset, 'length' => strlen($form)))));
}
$extract = new ReflectionMethod(WordPressSitePlan::class, 'extractPostArticleChrome');
$extract->setAccessible(true);
$engine = new WordPressSitePlan();

// Manifest-backed records: a bound form whose entity lives in a manifest
// record still keeps the complete articles page-owned.
$manifest = RuntimeEntityManifest::fromEntities('generic/forms/v1', $boundEntities);
$manifestDeclarations = RuntimeDeclarations::normalizeList(array(array('kind' => 'entity_collection', 'type' => 'forms', 'source_path' => 'index.html', 'payload' => $manifest['payload'])));
$retained = $extract->invoke($engine, $unitPosts, array(), $manifestDeclarations, $manifest['records']);
$assert(null === $retained['single'], 'Factoring retains the articles when a manifest record owns the bound newsletter forms.');
foreach ($retained['pages'] as $page) if ('post' === ($page['post_type'] ?? null)) {
    $assert(str_contains($page['canonical_block_markup'], 'newsletter-controls'), "The manifest-backed form stays in its owner post: {$page['source_path']}.");
}

// The same bindings held inline behave identically.
$inlineDeclarations = RuntimeDeclarations::normalizeList(array(array('kind' => 'entity_collection', 'type' => 'forms', 'source_path' => 'index.html', 'payload' => array('schema' => 'generic/forms/v1', 'entities' => $boundEntities))));
$retained = $extract->invoke($engine, $unitPosts, array(), $inlineDeclarations, array());
$assert(null === $retained['single'], 'Factoring retains the articles when an inline declaration owns the bound newsletter forms.');
foreach ($retained['pages'] as $page) if ('post' === ($page['post_type'] ?? null)) {
    $assert(str_contains($page['canonical_block_markup'], 'newsletter-controls'), "The inline-bound form stays in its owner post: {$page['source_path']}.");
}

// A binding whose exact region is the retained post-content does not block
// factoring: the bound paragraph stays in its owner document either way.
$contentEntities = array();
foreach ($unitPosts as $post) {
    $markup = $post['canonical_block_markup'];
    $search = '<!-- wp:paragraph --><p>Unique body for ' . basename($post['source_path'], '.html') . '.</p><!-- /wp:paragraph -->';
    $offset = strpos($markup, $search);
    $index = null;
    foreach (WordPressSitePlan::blockRanges($markup) as $blockIndex => $range) if ($range['offset'] === $offset) $index = $blockIndex;
    $contentEntities[] = array('bindings' => array(array('role' => 'summary', 'source_path' => $post['source_path'], 'search_block_markup' => $search, 'occurrence' => 1, 'position' => array('schema' => 'blocks-engine/runtime-binding-position/v1', 'block_index' => $index, 'offset' => $offset, 'length' => strlen($search)))));
}
$contentDeclarations = RuntimeDeclarations::normalizeList(array(array('kind' => 'entity_collection', 'type' => 'forms', 'source_path' => 'index.html', 'payload' => array('schema' => 'generic/forms/v1', 'entities' => $contentEntities))));
$factored = $extract->invoke($engine, $unitPosts, array(), $contentDeclarations, array());
$assert(null !== $factored['single'] && str_contains($factored['single'], '<!-- wp:post-content'), 'Factoring proceeds when the only bound region is the retained post-content.');
foreach ($factored['pages'] as $page) if ('post' === ($page['post_type'] ?? null)) {
    $assert(str_contains($page['canonical_block_markup'], 'Unique body for ' . basename($page['source_path'], '.html') . '.'), "The bound content region stays in its owner document: {$page['source_path']}.");
}

// Unbound posts still factor into the global single template.
$unbound = $extract->invoke($engine, $unitPosts, array(), array(), array());
$assert(null !== $unbound['single'] && str_contains($unbound['single'], '<!-- wp:post-content'), 'Unbound posts still factor into one global single template.');
foreach ($unbound['pages'] as $page) if ('post' === ($page['post_type'] ?? null)) {
    $assert(!str_contains($page['canonical_block_markup'], 'newsletter-compact'), "Factored chrome leaves the post body: {$page['source_path']}.");
}

fwrite(STDOUT, "staged-inline-post-chrome-runtime-bindings: ok\n");
