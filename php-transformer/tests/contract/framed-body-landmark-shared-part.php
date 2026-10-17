<?php
declare(strict_types=1);

/**
 * One header, two placements: inside an app frame on `/`, directly in the
 * body elsewhere. Both convert through the same element converter, so every
 * route shares one header part and one navigation, and an inline declaration
 * with no block support (`backdrop-filter`) still reaches the part's carrier.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (! $condition) {
        throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 2000)));
    }
};

$header = '<header class="fixed top-0 border-b" style="background: rgba(5, 11, 24, 0.72); border-color: #123; backdrop-filter: blur(14px);"><nav><a href="/">Home</a><a href="/about/">About</a></nav></header>';
$head = '<link rel="stylesheet" href="/style.css">';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'style.css' => '*{border:0 solid;margin:0;padding:0} .border-b{border-bottom-style:var(--tw-border-style);border-bottom-width:1px}',
        'index.html' => $head . '<div class="wrap">' . $header . '<main><h1>Home</h1><p>Home copy.</p></main></div>',
        'about/index.html' => $head . $header . '<main><h1>About</h1><p>About copy.</p></main>',
        'contact/index.html' => $head . $header . '<main><h1>Contact</h1><p>Contact copy.</p></main>',
    ),
))->toArray()['source_reports']['wordpress_site_plan'];

$headers = array_values(array_filter($plan['template_parts'] ?? array(), static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'framed and body-level copies of one header share one header part', json_encode(array_column($headers, 'slug')));

foreach ($plan['pages'] as $page) {
    $markup = (string) ($page['canonical_block_markup'] ?? '');
    $assert(! str_contains($markup, '<!-- wp:navigation'), $page['source_path'] . ' renders its header from the shared part, not page content', $markup);
}

$navigations = array_values(array_filter($plan['menus'] ?? array(), static fn(array $menu): bool => '' !== (string) ($menu['block_markup'] ?? '')));
$assert(1 === count(array_unique(array_map(static fn(array $menu): string => (string) ($menu['slug'] ?? $menu['block_markup']), $navigations))), 'the shared header owns one navigation', json_encode(array_column($navigations, 'slug')));

$css = '';
foreach ($plan['writes'] ?? array() as $write) {
    if (str_ends_with((string) ($write['target_path'] ?? ''), '.css')) $css .= (string) ($write['payload']['data'] ?? '');
}
$assert(1 === preg_match('/backdrop-filter:\s*blur\(14px\)/', $css), 'the header keeps its inline backdrop-filter on its carrier');

fwrite(STDOUT, "framed body landmark shared part contract passed\n");
