<?php
declare(strict_types=1);

/**
 * Site builders stamp every element id of a document with that document's
 * instance (`comp-m1_r_comp-k3m` on one route, `comp-m1_r_comp-q7v` on the
 * next) and repeat it as a class token. Such headers are one design: they
 * share one header part. The entry route's header also links to one of its
 * own sections; that per-route anchor item gets a route variant of the part,
 * so every template references a header part and no route is excluded.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$css = '<style>.site-header{display:flex;gap:1rem}.menu{display:flex;gap:2rem}.menu a{color:#333}</style>';
$page = static function (string $stamp, string $title, bool $anchor, string $workLabel = 'Work') use ($css): string {
    $id = static fn (string $element): string => 'comp-' . $element . '_r_comp-' . $stamp;
    $item = static fn (string $element, string $href, string $label): string => '<li id="' . $id($element) . '" class="item ' . $id($element) . '"><a href="' . $href . '">' . $label . '</a></li>';
    return '<!doctype html><html lang="en"><head><title>' . $title . '</title>' . $css . '</head><body>'
        . '<header class="site-header" id="' . $id('h1') . '"><a class="brand" href="/">Studio</a><nav class="menu" id="' . $id('n1') . '"><ul>'
        . $item('m1', '/', 'Home') . $item('m2', '/work', $workLabel) . $item('m3', '/about', 'About') . ($anchor ? $item('m4', '#contact', 'Contact') : '')
        . '</ul></nav></header><main><h1>' . $title . '</h1><p>' . $title . ' copy.</p>'
        . ($anchor ? '<section id="contact"><h2>Contact</h2><p>Write to us.</p></section>' : '')
        . '</main><footer class="site-footer"><p>Studio footer</p></footer></body></html>';
};
$compile = static fn (array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$headerDiagnostic = static fn (array $plan): ?array => array_values(array_filter($plan['diagnostics'], static fn (array $d): bool => 'wordpress_site_plan_shell_extracted' === $d['code'] && 'header' === $d['area']))[0] ?? null;

$plan = $compile(array(
    'index.html' => $page('k3m', 'Home', true),
    'work/index.html' => $page('q7v', 'Work', false),
    'about/index.html' => $page('x9d', 'About', false),
    'journal/index.html' => $page('z2w', 'Journal', false),
));

$extracted = $headerDiagnostic($plan);
$assert(null !== $extracted && 3 === $extracted['page_count'], 'Headers differing only by document-generated ids share one part: ' . json_encode(array_column($plan['diagnostics'], 'code')));
$assert(array() === $extracted['exclusions'], 'No route is excluded from the header shell: ' . json_encode($extracted['exclusions']));
$headers = array_column(array_filter($plan['template_parts'], static fn (array $part): bool => 'header' === $part['area']), null, 'slug');
$assert(isset($headers['header']) && 2 === count($headers), 'The shared header and one route variant exist: ' . json_encode(array_keys($headers)));
foreach ($plan['templates'] as $template) {
    preg_match_all('/<!-- wp:template-part \{"slug":"([^"]+)","area":"header"/', $template['canonical_block_markup'], $refs);
    $assert(1 === count($refs[1]) && isset($headers[$refs[1][0]]), 'Template ' . $template['slug'] . ' references exactly one header part: ' . json_encode($refs[1]));
    if ('front-page' !== $template['slug']) $assert('header' === $refs[1][0], 'Template ' . $template['slug'] . ' references the shared header.');
}
foreach ($plan['pages'] as $document) {
    $assert(!str_contains($document['canonical_block_markup'], 'Studio</a>'), $document['source_path'] . ' keeps no header copy of its own.');
}
$menus = array_column($plan['menus'], 'block_markup', 'token');
$menuOf = static fn (string $slug): string => preg_match('/navigation:([\w-]+)/', $headers[$slug]['canonical_block_markup'], $ref) ? ($menus[$ref[1]] ?? '') : '';
$variant = (string) array_key_last(array_diff_key($headers, array('header' => true)));
$assert(str_contains($menuOf($variant), '"url":"#contact"'), 'The entry route variant keeps its in-page anchor item.');
$assert(!str_contains($menuOf('header'), 'Contact'), 'The shared header does not gain the entry route anchor item.');

// Generated ids never merge headers whose copy differs.
$relabeled = $compile(array(
    'index.html' => $page('k3m', 'Home', false),
    'work/index.html' => $page('q7v', 'Work', false),
    'about/index.html' => $page('x9d', 'About', false, 'Projects'),
));
$shared = array_values(array_filter($relabeled['template_parts'], static fn (array $part): bool => 'header' === $part['slug']))[0] ?? array();
$sources = array_keys($shared['provenance']['sources'] ?? array());
sort($sources);
$assert(array('index.html', 'work/index.html') === $sources && !str_contains((string) $shared['canonical_block_markup'], 'Projects'), 'A relabeled header stays out of the shared part: ' . json_encode($sources));
// It is still that route's site header, so it becomes the route's own part.
$relabeledVariant = array_values(array_filter($relabeled['template_parts'], static fn (array $part): bool => 'header' === $part['area'] && array('about/index.html') === ($part['placement']['source_paths'] ?? null)))[0] ?? null;
$assert(null !== $relabeledVariant, 'The relabeled header is the route\'s own header variant part.');
echo "shared-shell-document-generated-ids: ok\n";
