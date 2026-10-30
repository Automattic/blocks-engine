<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// The footer is inside the content root, NOT a body-level sibling. These
// engine-owned wrappers reproduce both device documents in the real capture.
$header = '<header><p>Shared header</p></header>';
$footer = '<footer><p>Shared footer</p></footer>';
$wrap = static function (string $main, string $source): string {
    $html = '<html><body>';
    foreach (array('desktop', 'mobile') as $device) {
        $html .= '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-device-document="' . $device . '" data-blocks-engine-capture-document-scope=""><a href="#content">Skip to content</a>'
            . '<div class="wp-site-blocks"><header><p>Shared header</p></header>' . $main . '<footer><p>Shared footer</p></footer></div>'
            . '<div class="subscription-modal"><p>Subscribe</p></div><div><div class="profile-map">Profile</div></div>'
            . '<div id="loading-overlay-' . $device . '" style="display:none"><p>Loading</p></div>'
            . '<div id="other-readers-' . $device . '"><p>Readers for ' . $source . '</p></div>'
            . '<img id="stats-' . $device . '" src="https://example.com/stats.gif" alt=""><p id="speak-' . $device . '">Notifications</p></div>';
    }
    return $html . '<script>void 0;</script></body></html>';
};
$fixture = require dirname(__DIR__) . '/fixtures/taxonomy/corroborated-category.php';
$fixture['files'][] = array('path' => 'stories/third.html', 'content' => '<article><h1>Third story</h1><p>Third full story.</p></article>', 'metadata' => array('post_type' => 'post'));
foreach ($fixture['files'] as $key => &$file) {
    $html = is_array($file) ? $file['content'] : $file;
    $main = str_replace(array($header, $footer), '', $html);
    if (is_array($file) && 'post' === ($file['metadata']['post_type'] ?? null)) {
        $main = '<main id="content">' . $main . '<div style="display:none"><p>Hidden article content ' . $file['path'] . '</p></div></main>';
    }
    if (is_array($file)) {
        $file['content'] = $wrap($main, $file['path']);
    } else $file = $wrap($main, (string) $key);
}
unset($file);
$fixture['files']['parts/header.html'] = $header;
$fixture['files']['parts/footer.html'] = $footer;
$result = (new ArtifactCompiler())->compile($fixture)->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $plan, 'The complete capture compiles to a site plan.');
$assert(1 === count($plan['taxonomy_entities']), 'The category materializes despite repeated document scaffolds.');
$entity = $plan['taxonomy_entities'][0];
$assert(str_contains($entity['archive']['presentation_markup'], '<!-- wp:query '), 'The category has a native query loop.');
$templates = array_column($plan['templates'], null, 'slug');
foreach ($plan['pages'] as $page) {
    if ('post' !== $page['post_type']) continue;
    $content = $page['canonical_block_markup'];
    $assert(!str_contains($content, 'loading-overlay-') && !str_contains($content, 'subscription-modal') && !str_contains($content, 'other-readers-'), 'Document siblings are not post content.');
    $assert(str_contains($content, 'Hidden article content'), 'Hidden article content stays in the article.');
    $assert(!isset($page['route_template']), 'Document chrome does not require a per-post route template.');
    $template = $templates['single']['canonical_block_markup'];
    $assert(!str_contains($template, 'other-readers-'), 'Per-post runtime output is absent from the shared template.');
    $diagnostics = array_filter($plan['diagnostics'], static fn(array $diagnostic): bool => 'wordpress_site_plan_document_chrome_varying_omitted' === $diagnostic['code'] && $page['source_path'] === ($diagnostic['source_path'] ?? '') && str_contains($diagnostic['anchor_or_class'] ?? '', 'other-readers-'));
    $assert(array() !== $diagnostics, 'Varying siblings have a diagnostic naming the source and anchor.');
    foreach (array('desktop', 'mobile') as $device) {
        $assert(1 === substr_count($template, 'id="loading-overlay-' . $device . '"'), 'Each device scaffold renders once from the post template.');
        $assert(str_contains($template, 'data-blocks-engine-capture-' . $device . '-document'), 'The scaffold retains its responsive scope.');
    }
}
$assert(1 === count(array_filter($plan['templates'], static fn(array $template): bool => 'single' === $template['slug'])), 'One shared single template is emitted.');
$assert(array() === array_filter(array_keys($templates), static fn(string $slug): bool => str_starts_with($slug, 'single-post-')), 'No document chrome per-post templates are emitted.');
$archive = $templates['category-field-notes']['canonical_block_markup'];
foreach (array('desktop', 'mobile') as $device) {
    $assert(1 === substr_count($archive, 'id="loading-overlay-' . $device . '"'), 'The archive retains one scaffold per device, outside the query cards.');
    $assert(strpos($archive, 'id="loading-overlay-' . $device . '"') > strpos($archive, '<!-- /wp:post-template -->'), 'The scaffold follows the query cards rather than belonging to a repeated card.');
}

// Boundary policy: position within an engine capture document, not hidden
// styling, is the evidence. Ordinary authored containers are not evidence.
$group = static fn(string $class, string $inner): string => '<!-- wp:group {"className":"' . $class . '"} --><div class="' . $class . '">' . $inner . '</div><!-- /wp:group -->';
$hidden = $group('hidden-inside-main', '<!-- wp:paragraph --><p>Keep me</p><!-- /wp:paragraph -->');
$main = '<!-- wp:group {"tagName":"main"} --><main>' . $hidden . '</main><!-- /wp:group -->';
$root = $group('content-root', $main);
$tail = $group('visible-scaffold', '<!-- wp:paragraph --><p>Once per page</p><!-- /wp:paragraph -->');
$separate = static function (string $markup, array $declarations = array()): array {
    $page = array('post_type' => 'post', 'source_path' => 'post.html', 'slug' => 'post', 'canonical_block_markup' => $markup);
    return (new ShellExtraction(new WordPressSitePlan()))->separateCaptureDocumentChrome(array($page), $declarations);
};
$flat = $group('data-blocks-engine-capture-desktop-document', $main . $tail);
$assert($flat === $separate($flat)['pages'][0]['canonical_block_markup'], 'A flat main/footer shape stays with ordinary shell extraction.');
$ordinary = $group('authored-document', $root . $tail);
$assert($ordinary === $separate($ordinary)['pages'][0]['canonical_block_markup'], 'Ordinary authored containers are untouched.');
$captured = $group('data-blocks-engine-capture-desktop-document', $root . $tail);
$split = $separate($captured);
$assert(str_contains($split['pages'][0]['canonical_block_markup'], 'Keep me') && !str_contains($split['pages'][0]['canonical_block_markup'], 'Once per page'), 'Main owns its hidden content; trailing visible scaffolds move.');
$assert(str_contains($split['chrome']['single'], 'Once per page'), 'Visible scaffolds survive in page chrome.');
$bound = array(array('payload' => array('entities' => array(array('bindings' => array(array('source_path' => 'post.html', 'search_block_markup' => $tail)))))));
$assert($captured === $separate($captured, $bound)['pages'][0]['canonical_block_markup'], 'Runtime-bound siblings stay with their source rather than losing their anchor.');
$unbound = $group('unbound-scaffold', '<!-- wp:paragraph --><p>Shared scaffold</p><!-- /wp:paragraph -->');
$mixed = $separate($group('data-blocks-engine-capture-desktop-document', $root . $tail . $unbound), $bound);
$assert(str_contains($mixed['pages'][0]['canonical_block_markup'], 'Once per page') && !str_contains($mixed['pages'][0]['canonical_block_markup'], 'Shared scaffold'), 'One bound sibling does not prevent extracting its unbound neighbor.');
$assert(str_contains($mixed['chrome']['single'], 'Shared scaffold'), 'Unbound chrome survives beside a runtime-bound sibling.');

// Shared identity ignores only generated classes and responsive id suffixes.
$posts = array();
foreach (array('aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb', 'cccccccccccccccc') as $index => $hash) {
    $scaffold = $group('stable-scaffold be-inline-geometry-' . $hash, '<!-- wp:paragraph {"anchor":"scaffold' . (1 === $index ? '--data-blocks-engine-capture-mobile' : '') . '"} --><p id="scaffold' . (1 === $index ? '--data-blocks-engine-capture-mobile' : '') . '">Shared</p><!-- /wp:paragraph -->');
    $varying = $group('request-output', '<!-- wp:paragraph --><p>Reader ' . $index . '</p><!-- /wp:paragraph -->');
    $posts[] = array('post_type' => 'post', 'source_path' => 'post-' . $index . '.html', 'canonical_block_markup' => $group('data-blocks-engine-capture-desktop-document', $root . $scaffold . $varying));
}
$shared = (new ShellExtraction(new WordPressSitePlan()))->separateCaptureDocumentChrome($posts, array());
$assert(1 === substr_count($shared['chrome']['single'], '<p id="scaffold">Shared</p>'), 'Generated identity differences do not split shared chrome.');
$assert(!str_contains($shared['chrome']['single'], 'Reader '), 'Per-request sibling content is not frozen into the template.');
$assert(3 === count($shared['diagnostics']), 'Every dropped per-post sibling is diagnosed.');
$posts[] = array('post_type' => 'post', 'source_path' => 'without-chrome.html', 'canonical_block_markup' => $root);
$intersection = (new ShellExtraction(new WordPressSitePlan()))->separateCaptureDocumentChrome($posts, array());
$assert(array() === $intersection['chrome'], 'The intersection includes posts with no capture chrome.');
$ambiguous = $group('data-blocks-engine-capture-desktop-document', $root . $root . $tail);
$assert($ambiguous === $separate($ambiguous)['pages'][0]['canonical_block_markup'], 'Ambiguous main-owning roots are untouched.');

echo "capture-document-trailing-chrome: passed\n";
