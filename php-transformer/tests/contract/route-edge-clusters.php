<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TemplateSlugs;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$files = array();
foreach (array('en' => 'Explore', 'fr' => 'Découvrir') as $language => $label) {
    foreach (array('page', 'post', 'embed', 'hero', 'episode-a', 'episode-b') as $shape) {
        $title = $language . ' ' . $shape;
        $navbar = '<div class="navbar"><a href="/en-page.html">' . $label . '</a><a href="/fr-page.html">Language</a></div>';
        $content = '<main><h1>' . $title . '</h1><p>Body ' . $title . '</p></main>';
        if ('hero' === $shape) $navbar = '<header>' . $navbar . '<h2>Episode ' . $title . '</h2><p>Date ' . $title . '</p></header>';
        $body = $navbar . $content;
        if ('page' === $shape) $body = '<div class="page-content">' . $body . '</div>';
        if ('embed' === $shape) $body = '<div class="w-embed"></div>' . $body;
        $path = str_starts_with($shape, 'episode-') ? ('en' === $language ? 'podcast/' : 'podcasts-fr/') . substr($shape, 8) . '.html' : $language . '-' . $shape . '.html';
        $files[$path] = '<!doctype html><html><head><title>' . $title . '</title></head><body><div class="global-style"><div class="w-embed"></div></div>' . $body . '</body></html>';
    }
}
$artifacts = array();
foreach ($files as $path => $html) $artifacts[] = array('path' => $path, 'content' => $html, 'metadata' => array('post_type' => str_contains($path, '-page.html') ? 'page' : 'post'));
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'en-page.html', 'files' => $artifacts))->toArray();
// The compiler prunes inert HTML embeds. Also retain their captured block
// shape at the public plan boundary, so alignment itself must skip them.
foreach (array(false, true) as $retainEmbeds) {
    $input = $result;
    if ($retainEmbeds) foreach ($input['source_reports']['compiled_site']['pages'] as &$row) {
        $empty = '<!-- wp:group {"className":"w-embed"} --><div class="wp-block-group w-embed"></div><!-- /wp:group -->';
        $row['block_markup'] = $empty . (str_contains($row['source_path'], '-embed.html') ? $empty : '') . $row['block_markup'];
    }
    unset($row);
    $plan = (new WordPressSitePlan())->fromResult($input);
    WordPressSitePlan::assertValid($plan);
    $assert(10 === count(array_filter($plan['pages'], static fn(array $page): bool => 'post' === $page['post_type'])), 'The fixture exercises two pages and ten posts, including same-slug episodes under different parents.');
    $headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
    $navbarSources = array_column(array_filter($plan['pages'], static fn(array $page): bool => str_contains($page['canonical_block_markup'], 'navbar')), 'source_path');
    $singleUse = array_column(array_filter($headers, static fn(array $part): bool => 1 === count($part['placement']['source_paths'] ?? array_keys($part['provenance']['sources'] ?? array()))), 'slug');
    $evidence = json_encode(array('headers' => array_column($headers, 'slug'), 'navbar_in_content' => $navbarSources, 'single_use' => $singleUse));
    $assert(2 === count($headers) && array() === $navbarSources && array() === $singleUse, $evidence);
    foreach ($headers as $header) {
        $assert(6 === count($header['placement']['source_paths'] ?? array_keys($header['provenance']['sources'] ?? array())), 'One part per six-route language cluster: ' . $evidence);
        $assert(!str_contains($header['canonical_block_markup'], 'Episode') && !str_contains($header['canonical_block_markup'], 'Date'), 'Hero is not chrome.');
    }
    $templates = WordPressSitePlan::renderedTemplates($plan);
    $episodes = array_column(array_values(array_filter($plan['pages'], static fn(array $page): bool => str_contains($page['source_path'], '/'))), null, 'source_path');
    $assert($episodes['podcast/a.html']['slug'] === $episodes['podcasts-fr/a.html']['slug'], 'Episode routes share a terminal slug.');
    $assert(TemplateSlugs::forPage($episodes['podcast/a.html']) === TemplateSlugs::forPage($episodes['podcast/b.html']), 'Identical episode frames share a template within EN.');
    $assert(TemplateSlugs::forPage($episodes['podcasts-fr/a.html']) === TemplateSlugs::forPage($episodes['podcasts-fr/b.html']), 'Identical episode frames share a template within FR.');
    $assert(TemplateSlugs::forPage($episodes['podcast/a.html']) !== TemplateSlugs::forPage($episodes['podcasts-fr/a.html']), 'Shared templates are partitioned by header cluster.');
    foreach ($plan['pages'] as $page) {
        $markup = $page['canonical_block_markup'];
        $assert(!str_contains($markup, 'wp:template-part') && str_contains($markup, 'Body ' . $page['title']), 'Content stays route-owned; parts belong to templates: ' . $page['source_path']);
        $template = $templates[TemplateSlugs::forPage($page)] ?? $templates[$page['entrypoint'] ? 'front-page' : ('post' === $page['post_type'] ? 'single' : 'page')] ?? '';
        foreach ($headers as $header) $assert(in_array($page['source_path'], $header['placement']['source_paths'] ?? array_keys($header['provenance']['sources'] ?? array()), true) === str_contains($template, '"slug":"' . $header['slug'] . '"'), 'Template uses only its cluster: ' . $page['source_path']);
        if (str_contains($page['source_path'], '-hero')) $assert(str_contains($markup, 'Episode ' . $page['title']) && str_contains($markup, 'Date ' . $page['title']), 'Hero remains content.');
    }
}
// Plain pages need no authored route-specific frame. Shared lifecycles must
// nevertheless keep both edges of each language cluster together.
$artifacts = array();
foreach (array('en' => array('Explore', 'Contact us'), 'fr' => array('Découvrir', 'Contactez-nous')) as $language => [$nav, $footer]) {
    foreach (array('home', 'about', 'post') as $shape) {
        $path = $language . '-' . $shape . '.html';
        $artifacts[] = array('path' => $path, 'metadata' => array('post_type' => 'post' === $shape ? 'post' : 'page'), 'content' => '<!doctype html><html lang="' . $language . '"><head><title>' . $language . ' ' . $shape . '</title></head><body><div class="navbar"><a href="/en-home.html">' . $nav . '</a><a href="/fr-home.html">Language</a></div><h1>' . $language . ' ' . $shape . '</h1><p>Body ' . $path . '</p><footer><p>' . $footer . '</p></footer></body></html>');
    }
}
$plan = (new WordPressSitePlan())->fromResult((new ArtifactCompiler())->compile(array('entrypoint' => 'en-home.html', 'files' => $artifacts))->toArray());
WordPressSitePlan::assertValid($plan);
$templates = WordPressSitePlan::renderedTemplates($plan);
$bindings = array();
foreach ($plan['pages'] as $page) {
    $key = TemplateSlugs::forPage($page);
    $key = isset($templates[$key]) ? $key : ($page['entrypoint'] ? 'front-page' : ('post' === $page['post_type'] ? 'single' : 'page'));
    $bindings[$page['source_path']] = $key;
}
foreach ($plan['pages'] as $page) {
    $template = $templates[$bindings[$page['source_path']]];
    $assert(!str_contains($page['canonical_block_markup'], 'navbar') && !str_contains($page['canonical_block_markup'], 'Contact') && !str_contains($page['canonical_block_markup'], 'wp:template-part'), 'Chrome leaves route content: ' . $page['source_path']);
    foreach ($plan['template_parts'] as $part) {
        if (!in_array($part['area'], array('header', 'footer'), true)) continue;
        $owns = in_array($page['source_path'], $part['placement']['source_paths'] ?? array_keys($part['provenance']['sources'] ?? array()), true);
        $assert($owns === str_contains($template, '"slug":"' . $part['slug'] . '"'), 'Route template → cluster binding: ' . json_encode($bindings) . '; ' . $page['source_path'] . ' → ' . $part['slug']);
    }
}
foreach (array('index', '404', 'archive', 'search') as $slug) foreach ($plan['template_parts'] as $part) {
    if (!in_array($part['area'], array('header', 'footer'), true)) continue;
    $entryOwns = in_array('en-home.html', $part['placement']['source_paths'] ?? array_keys($part['provenance']['sources'] ?? array()), true);
    $assert($entryOwns === str_contains($templates[$slug], '"slug":"' . $part['slug'] . '"'), 'Fallback takes entry cluster: ' . $slug . ' → ' . $part['slug']);
}
fwrite(STDOUT, "Route edge clusters contract passed.\n");
