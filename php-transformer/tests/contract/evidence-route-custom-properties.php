<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\EvidenceRouteStylesheets;

/*
 * Issue #6445: an evidence route's blocks and carriers still read the author's
 * custom properties (Tailwind `calc(var(--spacing) * 24)`, `var(--text-4xl)`).
 * Retiring the author sheet must keep those definitions for that route, also
 * when legacy routes keep loading the sheet whole.
 */
$assert = static function (bool $ok, string $message, string $detail = ''): void {
    if (!$ok) throw new RuntimeException($message . ('' !== $detail ? ': ' . $detail : ''));
    echo "PASS: $message\n";
};
$page = static fn(string $source, string $route, string $markup, bool $evidence): array => array(
    'source_path' => $source,
    'post_type' => 'page',
    'route' => array('path' => $route),
    'reconciliation_identity' => hash('sha256', $source),
    'document_metadata' => $evidence ? array('engine_owned_styles' => true) : array(),
    'canonical_block_markup' => $markup,
);
$about = $page('website/about/index.html', '/about/', '<!-- wp:navigation {"style":{"typography":{"fontSize":"var(\u002d\u002dtext-xs)"}}} /--><!-- wp:group --><div class="wp-block-group mt-24" style="margin-top:calc(var(--spacing) * 24)"></div><!-- /wp:group -->', true);
$home = $page('website/index.html', '/', '<p class="mt-24">Home</p>', false);
$carrier = array('kind' => 'css', 'source' => 'engine-support', 'content' => ':root .h{font-size:var(--text-4xl)}', 'scopes' => array(array('kind' => 'global')));
$author = array(
    'kind' => 'css', 'source' => 'files', 'source_path' => 'website/assets/styles.css', 'target_path' => 'assets/website/assets/styles.css',
    'token' => 'asset-author', 'reconciliation_identity' => hash('sha256', 'author'),
    'content' => '@layer theme{:root,:host{--spacing:.25rem;--text-xs:.75rem;--text-4xl:2.25rem;--unused:1px}}.mt-24{margin-top:calc(var(--spacing) * 24)}.btn:hover{opacity:.5}',
    'scopes' => array(array('kind' => 'global')),
    'stylesheet_instances' => array(
        array('source_path' => 'website/about/index.html', 'order' => 1, 'media' => '', 'type' => ''),
        array('source_path' => 'website/index.html', 'order' => 1, 'media' => '', 'type' => ''),
    ),
);

$assets = EvidenceRouteStylesheets::retire(array($carrier, $author), array($about, $home));
$byTarget = array_column($assets, null, 'target_path');
$whole = $byTarget['assets/website/assets/styles.css'] ?? null;
$retained = $byTarget['assets/website/assets/styles.evidence-routes.css'] ?? null;
$assert(is_array($whole) && $whole['content'] === $author['content'] && array('website/index.html') === array_column($whole['scopes'], 'source_path'), 'legacy routes keep loading the author sheet whole', (string) json_encode($whole));
$assert(is_array($retained), 'evidence routes the shared author sheet styled get its retained part', (string) json_encode(array_keys($byTarget)));
$css = (string) $retained['content'];
$assert(str_contains($css, '--spacing:.25rem') && str_contains($css, '--text-xs:.75rem') && str_contains($css, '--text-4xl:2.25rem'), 'custom properties the route markup, block attributes and carriers read keep their definitions', $css);
$assert(!str_contains($css, '--unused') && !str_contains($css, '.mt-24{'), 'unread definitions and resting author rules stay retired', $css);
$assert(str_contains($css, '.btn:hover{opacity:.5}'), 'state rules reach the evidence routes', $css);
$assert(array('website/about/index.html') === array_column($retained['scopes'], 'source_path') && array('website/about/index.html') === array_column($retained['stylesheet_instances'], 'source_path'), 'the retained part is delivered at the evidence route link occurrence', (string) json_encode($retained));
$assert($retained['token'] !== $author['token'] && $retained['reconciliation_identity'] !== $author['reconciliation_identity'], 'the retained part is its own resource');

// A sheet only evidence routes load is retired in place with the same definitions.
$author['scopes'] = array(array('kind' => 'page', 'source_path' => 'website/about/index.html', 'route_path' => 'about', 'reconciliation_identity' => $about['reconciliation_identity'], 'front_page' => false));
$assets = EvidenceRouteStylesheets::retire(array($carrier, $author), array($about, $home));
$css = (string) $assets[1]['content'];
$assert(2 === count($assets) && str_contains($css, '--spacing:.25rem') && str_contains($css, '--text-4xl:2.25rem') && !str_contains($css, '--unused'), 'a fully retired sheet keeps the definitions its route reads', $css);

// Template parts render unchanged on evidence routes and carry no carriers:
// author rules reaching them stay, confined to part content.
$footer = array('slug' => 'footer', 'canonical_block_markup' => '<!-- wp:group {"className":"mt-24 grid md:grid-cols-4"} --><div class="wp-block-group mt-24 grid md:grid-cols-4"></div><!-- /wp:group -->');
$author['content'] = '*,:before{box-sizing:border-box;border:0 solid}:root{--spacing:.25rem}.grid{display:grid}@media (min-width:48rem){.md\:grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}}.mt-24{margin-top:calc(var(--spacing) * 24)}.grid.hero{color:red}.price{color:red}';
$css = (string) EvidenceRouteStylesheets::retire(array($author), array($about, $home), array($footer))[0]['content'];
$assert(str_contains($css, '.grid:where(.wp-block-template-part *){display:grid}') && str_contains($css, '.md\:grid-cols-4:where(.wp-block-template-part *){grid-template-columns:repeat(4,minmax(0,1fr))}') && str_contains($css, '*:where(.wp-block-template-part *),:where(.wp-block-template-part *):before{box-sizing:border-box;border:0 solid}') && str_contains($css, '.mt-24:where(.wp-block-template-part *){margin-top:calc(var(--spacing) * 24)}'), 'author rules reaching template parts stay, confined to part content', $css);
$assert(!str_contains($css, '.price') && !str_contains($css, '.hero') && str_contains($css, '--spacing:.25rem'), 'rules requiring classes no part element carries stay retired; definitions the kept rules read stay', $css);
