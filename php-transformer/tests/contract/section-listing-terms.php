<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$files = array();
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
foreach (array(1, 2, 3) as $day) $files[] = array('path' => "media/card-$day.png", 'content' => $png);
$cards = array();
$hubCards = array();
foreach (array('journal', 'podcast') as $section) {
    for ($day = 3; $day >= 1; --$day) {
        $title = ucfirst($section) . ' entry ' . $day;
        $files[] = array('path' => "$section/entry-$day/index.html", 'metadata' => array('post_type' => 'post'), 'content' => '<meta property="article:published_time" content="2024-01-0' . $day . 'T00:00:00Z"><main><article><h1>' . $title . '</h1><time datetime="2024-01-0' . $day . '">January ' . $day . ', 2024</time><img src="/media/card-' . $day . '.png" alt=""><p>Unique body ' . $title . '.</p></article></main>');
        if ('journal' === $section) {
            $label = 2 === $day ? 'New topics' : '';
            $hubCards[] = '<div class="collection-item"><a class="article-card" href="/journal/entry-' . $day . '/index.html" style="background-image:url(/media/card-' . $day . '.png);padding:12px"><div class="card-detail"><div class="card-detail-main"><div class="article-card-date"><div>Jan ' . $day . ', 2024</div></div><h3 class="article-headline">' . $title . '</h3><div class="card-category' . ('' === $label ? ' is-empty-binding' : '') . '">' . $label . '</div></div><div class="see-more"><div class="btn-text">See <span>more</span></div></div></div></a></div>';
        }
        $cards[$section][] = '<article><h2><a href="/' . $section . '/entry-' . $day . '/">' . $title . '</a></h2><p>Read this entry.</p></article>';
    }
}
$journal = '<section><h1>Journal dispatches</h1>' . implode('', $cards['journal']) . '</section>';
$curated = '<section><h2>Selected episodes</h2>' . implode('', array_slice($cards['podcast'], 0, 2)) . '</section>';
$files[] = array('path' => 'index.html', 'content' => '<style>.is-empty-binding{display:none!important}</style><main><h1>Hub</h1><section class="tab-pane"><h2>Journal dispatches</h2>' . implode('', $hubCards) . '</section>' . $curated . '</main>');
$files[] = array('path' => 'journal/index.html', 'content' => '<main>' . $journal . '</main>');
$files[] = array('path' => 'podcast/index.html', 'content' => '<main><h1>Podcast</h1>' . implode('', array_reverse($cards['podcast'])) . '</main>');
$compile = static fn(array $input): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $input))->toArray();
$result = $compile($files);
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$assert(isset($plan['pages']), 'Plan must compile: ' . json_encode($result['diagnostics'] ?? $result));
WordPressSitePlan::assertValid($plan);
$pages = array_column($plan['pages'], null, 'source_path');

$terms = array_column($plan['taxonomy_entities'], null, 'slug');
$assert(count($terms) === 2 && $terms['journal']['name'] === 'Journal dispatches', 'Two named section categories.');
foreach ($terms as $slug => $term) {
    $expected = array_map(static fn(int $day): string => "$slug/entry-$day/index.html", array(1, 2, 3));
    $assert($term['membership_source_paths'] === $expected && !isset($term['archive']) && $term['evidence'] === array('membership' => true, 'name' => true), 'Archive-less membership contract.');
}
foreach (array('index.html', 'journal/index.html') as $source) {
    $markup = $pages[$source]['canonical_block_markup'];
    $assert(substr_count($markup, '<!-- wp:query ') === 1 && str_contains($markup, '"taxQuery":{"category":["{{wordpress-site-plan:term:category:journal}}"]}'), 'Complete journal listings use the portable term token.');
}
$hubMarkup = $pages['index.html']['canonical_block_markup'];
$assert(str_contains($hubMarkup, '<!-- wp:post-date ') && str_contains($hubMarkup, '<!-- wp:post-title '), 'Wrapper-class dates and whole-card titles become native post slots.');
preg_match('~<!-- wp:post-template.*?<!-- /wp:post-template -->~s', $hubMarkup, $templateMatch);
$template = $templateMatch[0] ?? '';
$assert(str_contains($template, 'article-card') && str_contains($template, 'blocks-engine-listing-background') && str_contains($template, 'wp:post-featured-image') && str_contains($template, 'blocks-engine-listing-overlay') && str_contains($template, 'wp:read-more'), 'Card box, native per-post background and overlay link survive.');
$assert(!str_contains($template, 'background-image') && !str_contains($template, '/journal/entry-') && !str_contains($template, '/media/card-') && !str_contains($template, '{{wordpress-site-plan:asset-'), 'No first-post literal image or href in the template.');
$document = new DOMDocument();
$document->loadHTML('<?xml encoding="UTF-8"><body>' . $template . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($document);
$assert($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " collection-item ")]')->length === 1 && $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " article-card ")]')->length === 1, 'Saved HTML retains exactly one item and one card box.');
$assert(str_contains($template, 'padding:12px'), 'Non-per-post card styles survive.');
preg_match_all('~<!-- wp:(?:[^ ]+/)?layout-shell (.*?) -->\\s*<([^ >]+)([^>]*)>~s', $template, $shells, PREG_SET_ORDER);
$assert(str_contains($template, '<!-- wp:group {"className":"collection-item"}') && 1 === count($shells), 'The item remains native while the listing background card retains its owned shell.');
foreach ($shells as $shell) {
    $attrs = json_decode($shell[1], true);
    $wrapper = $attrs['wrappers'][0];
    $assert(count($attrs['wrappers']) === 1 && $wrapper['tagName'] === $shell[2], 'Shell tag matches its declared wrapper.');
    foreach ($wrapper['attributes'] as $key => $value) $assert(str_contains($shell[3], $key . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'), 'Shell saved attributes match its declaration.');
}
$assert(preg_match('/"source":"core\/post-meta","args":\{"key":"(blocks_engine_listing_labels_[a-f0-9]+)"\}/', $hubMarkup, $binding) === 1, 'Varying plain labels use native post-meta bindings.');
foreach (array(3 => '', 2 => 'New topics', 1 => '') as $day => $label) {
    $assert(($pages["journal/entry-$day/index.html"]['metadata']['post_meta'][$binding[1]] ?? null) === $label, 'Each post owns its label, including empty values.');
    $assert(str_ends_with($pages["journal/entry-$day/index.html"]['metadata']['featured_image']['target_path'] ?? '', "media/card-$day.png"), 'Each card background resolves to that post’s featured image.');
}
$mismatched = $files;
foreach ($mismatched as &$file) {
    if ('index.html' === $file['path']) $file['content'] = str_replace('url(/media/card-1.png)', 'url(/media/card-3.png)', $file['content']);
}
unset($file);
$mismatchedPages = array_column($compile($mismatched)['source_reports']['wordpress_site_plan']['pages'], null, 'source_path');
$assert(!str_contains($mismatchedPages['index.html']['canonical_block_markup'], '<!-- wp:query '), 'A background mismatch on any card declines dynamic conversion.');
$linkedFiles = $files;
foreach ($linkedFiles as &$file) {
    if ('index.html' === $file['path']) $file['content'] = str_replace('>New topics</div>', '><a href="https://example.org/topics">New topics</a></div>', $file['content']);
}
unset($file);
$linkedPlan = $compile($linkedFiles)['source_reports']['wordpress_site_plan'];
$linkedPages = array_column($linkedPlan['pages'], null, 'source_path');
$assert(!str_contains($linkedPages['index.html']['canonical_block_markup'], '<!-- wp:query '), 'Structurally different linked labels decline the hub listing.');
// Missing non-label leaves and moving a label to another parent must not
// masquerade as an empty value at the corresponding label slot.
foreach (array(
    array('<div class="article-card-date"><div>Jan 3, 2024</div></div>', ''),
    array('<div class="card-category">New topics</div>', '<div class="label-wrapper"><div class="card-category">New topics</div></div>'),
) as [$before, $after]) {
    $differentFiles = $files;
    foreach ($differentFiles as &$file) {
        if ('index.html' === $file['path']) $file['content'] = str_replace($before, $after, $file['content']);
    }
    unset($file);
    $differentPlan = $compile($differentFiles)['source_reports']['wordpress_site_plan'];
    $differentPages = array_column($differentPlan['pages'], null, 'source_path');
    $assert(!str_contains($differentPages['index.html']['canonical_block_markup'], '<!-- wp:query '), 'Only an omitted plain label under the same parent may align.');
}
$assert(str_contains($pages['index.html']['canonical_block_markup'], 'Podcast entry 3') && str_contains($pages['index.html']['canonical_block_markup'], 'Podcast entry 2'), 'Curated podcast cards stay static.');
$assert(!str_contains($pages['podcast/index.html']['canonical_block_markup'], '<!-- wp:query '), 'Non-date order stays static.');
$single = $compile(array_values(array_filter($files, static fn(array $file): bool => !str_starts_with($file['path'], 'podcast/'))));
$singlePlan = $single['source_reports']['wordpress_site_plan'];
$assert($singlePlan['taxonomy_entities'] === array() && !str_contains(json_encode($singlePlan['pages']), 'taxQuery'), 'Single-section output remains unscoped.');
echo "section listing term contract passed\n";
