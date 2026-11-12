<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TemplateSlugs;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$files = array();
foreach (array('page', 'post') as $type) foreach (array('a', 'b') as $suffix) {
    $title = $type . ' ' . $suffix;
    $footer = '<div class="footer"><div class="footer-content"><p>Shared colophon</p><a href="/page-a.html">About</a><a href="/page-b.html">Contact</a></div></div>';
    $body = '<div class="article-body"><h1>' . $title . '</h1><p>Body ' . $title . '</p></div>' . $footer;
    if ('page' === $type) $body = '<div class="page-content">' . $body . '</div>';
    $files[] = array('path' => $type . '-' . $suffix . '.html', 'content' => '<!doctype html><html><head><title>' . $title . '</title></head><body>' . $body . '<script>window.fixtureReady = true;</script></body></html>', 'metadata' => array('post_type' => $type));
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'page-a.html', 'files' => $files))->toArray();
// Also exercise empty embeds that a capture can retain at the plan boundary.
foreach (array(false, true) as $retainEmbeds) {
    $input = $result;
    if ($retainEmbeds) foreach ($input['source_reports']['compiled_site']['pages'] as &$row) {
        $row['block_markup'] .= '<!-- wp:group {"className":"w-embed"} --><div class="wp-block-group w-embed"></div><!-- /wp:group --><!-- wp:html --><script>window.fixtureReady = true;</script><!-- /wp:html -->';
    }
    unset($row);
    $plan = (new WordPressSitePlan())->fromResult($input);
    WordPressSitePlan::assertValid($plan);
    $footers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === $part['area']));
    $retained = array_column(array_filter($plan['pages'], static fn(array $page): bool => str_contains($page['canonical_block_markup'], 'Shared colophon')), 'source_path');
    $evidence = json_encode(array('footer_parts' => array_column($footers, 'slug'), 'footer_in_content' => $retained));
    $assert(4 === count($plan['pages']), 'Two pages and two posts exercise different footer depths.');
    $assert(1 === count($footers) && array() === $retained, $evidence);
    $assert(str_contains($footers[0]['canonical_block_markup'], 'Shared colophon'), 'The footer part retains its copy.');
    $assert(4 === count($footers[0]['placement']['source_paths'] ?? array_keys($footers[0]['provenance']['sources'] ?? array())), 'One footer cluster spans every route: ' . $evidence);
    $templates = WordPressSitePlan::renderedTemplates($plan);
    foreach ($plan['pages'] as $page) {
        $markup = $page['canonical_block_markup'];
        $assert(!str_contains($markup, 'wp:template-part') && str_contains($markup, 'Body ' . $page['title']), 'Route content retains only its own body: ' . $page['source_path']);
        $template = $templates[TemplateSlugs::forPage($page)] ?? $templates[$page['entrypoint'] ? 'front-page' : ('post' === $page['post_type'] ? 'single' : 'page')] ?? '';
        $assert(str_contains($template, '"slug":"' . $footers[0]['slug'] . '"'), 'Route template references the shared footer: ' . $page['source_path']);
    }
}
fwrite(STDOUT, "Trailing edge clusters contract passed.\n");
