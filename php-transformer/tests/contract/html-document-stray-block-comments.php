<?php
declare(strict_types=1);

/**
 * A captured HTML page can carry block comments in its text, for example
 * block-editor content pasted into a page builder's text widget leaves
 * `<p><!-- wp:paragraph --></p>`. Such a page is still an HTML document: it
 * must be converted to blocks, not passed through as if it were serialized
 * block markup. Real block markup entries still pass through unchanged.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$article = "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><title>Article</title></head><body><main><h1>Article title</h1><div class=\"text-widget\">"
    . "<p><!-- wp:paragraph --></p>\n<p>First paragraph.</p>\n<p><!-- /wp:paragraph --></p>\n"
    . "<p><!-- wp:heading --></p>\n<h2>Section heading</h2>\n<p><!-- /wp:heading --></p>"
    . "</div></main></body></html>";
$artifact = array(
    'entrypoint' => 'index.html',
    'files'      => array(
        'index.html'         => '<!doctype html><html><head><title>Home</title></head><body><main><h1>Home</h1><p>Welcome.</p></main></body></html>',
        'article/index.html' => $article,
    ),
);
$isConverted = static fn(string $markup): bool => str_starts_with(ltrim($markup), '<!-- wp:') && false === stripos($markup, '<html') && false === stripos($markup, '<!doctype') && str_contains($markup, 'First paragraph.') && str_contains($markup, 'Section heading');

// Staged compilation (one page per call, as importers run it).
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$staged = null;
foreach ( $compiler->preparePages($artifact, $shared) as $plan ) {
    $receipt = ( new ArtifactCompiler() )->compilePreparedPages($shared, array($plan));
    $staged = $receipt[$plan['page_id']]['compiled_documents']['article/index.html'] ?? $staged;
}
$assert(is_array($staged) && $isConverted((string) ($staged['serialized_blocks'] ?? '')), 'A full HTML page with stray block comments in its text compiles to real blocks, not the raw document: ' . json_encode(substr((string) ($staged['serialized_blocks'] ?? ''), 0, 80)));

// Whole compilation: the page's site-plan markup is converted block markup.
$whole = ( new ArtifactCompiler() )->compile($artifact)->toArray();
$page = null;
foreach ( $whole['source_reports']['wordpress_site_plan']['pages'] ?? array() as $candidate ) {
    if ( 'article/index.html' === ( $candidate['source_path'] ?? null ) ) $page = $candidate;
}
$assert(is_array($page) && $isConverted((string) $page['canonical_block_markup']), 'The site plan page for a full HTML document with stray block comments holds converted blocks: ' . json_encode(substr((string) ($page['canonical_block_markup'] ?? ''), 0, 80)));

// Real serialized block markup still passes through unchanged.
$fragment = '<!-- wp:heading {"level":2} --><h2>Block heading</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Block body.</p><!-- /wp:paragraph -->';
$explicit = ( new ArtifactCompiler() )->compile(array('entrypoint' => 'content.blocks', 'files' => array(array('path' => 'content.blocks', 'kind' => 'blocks', 'content' => $fragment))))->toArray();
$assert($fragment === ( $explicit['serialized_blocks'] ?? null ), 'An explicit block markup entry passes through unchanged.');
$inferred = ( new ArtifactCompiler() )->compile(array('files' => array(array('path' => 'page.fragment', 'content' => "\n  " . $fragment))))->toArray();
$assert("\n  " . $fragment === ( $inferred['serialized_blocks'] ?? null ), 'A block markup fragment with leading whitespace passes through unchanged.');

echo "HTML document with stray block comments contract passed\n";
