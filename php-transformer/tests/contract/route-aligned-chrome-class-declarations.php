<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// An atomic CSS builder numbers each document's utility classes in the order
// that document first uses them, in the document's own style element: the
// same header declaration is `c1-1` on one route and `c1-3` on another, and
// `c1-1` means something else there. Route alignment gives each such meaning
// one name on every route. A compile that prepares each page on its own
// re-reads the page's style elements, so the aligned name must carry its
// rules there too: every class the shared header wears resolves to the
// declarations it had in its source document. A builder can also serve a
// route's atomic sheet as a stylesheet file only that route links; the file
// is that route's own sheet, read like a style element.
$meanings = array(
    'font' => 'font-family:Georgia,serif',
    'color' => 'color:rgb(200, 10, 10)',
    'gap' => 'display:flex;gap:24px',
    'shadow' => 'box-shadow:0 1px 2px rgb(0, 0, 0);position:relative',
    'caps' => 'text-transform:uppercase;letter-spacing:2px',
);
$pages = array(
    'index.html' => array('Home', array('font' => 'c1-1', 'color' => 'c1-2', 'gap' => 'c1-3', 'shadow' => 'c1-4', 'caps' => 'c1-5'), ''),
    'a/index.html' => array('Route A', array('font' => 'c1-2', 'color' => 'c1-3', 'gap' => 'c1-4', 'shadow' => 'c1-5', 'caps' => 'c1-6'), 'c1-1'),
    'b/index.html' => array('Route B', array('font' => 'c1-3', 'color' => 'c1-4', 'gap' => 'c1-1', 'shadow' => 'c1-6', 'caps' => 'c1-5'), 'c1-2'),
);
$artifact = array('entrypoint' => 'index.html', 'files' => array());
foreach ($pages as $path => [$title, $c, $lead]) {
    $css = array();
    foreach ($meanings as $meaning => $declarations) $css[$c[$meaning]] = $declarations;
    if ('' !== $lead) $css[$lead] = 'margin-top:40px';
    ksort($css);
    $style = '';
    foreach ($css as $class => $declarations) $style .= ".{$class}{{$declarations}}";
    $style .= '@media (min-width:768px){.' . $c['gap'] . '{gap:32px}}';
    $links = '';
    foreach (array('/' => 'Home', '/a/' => 'Route A', '/b/' => 'Route B') as $href => $label) $links .= '<li><a href="' . $href . '" class="' . $c['color'] . '">' . $label . '</a></li>';
    $linked = 'b/index.html' === $path;
    if ($linked) $artifact['files'][] = array('path' => 'assets/b-atoms.css', 'content' => $style);
    $artifact['files'][] = array('path' => $path, 'content' => '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title>' . ($linked ? '<link rel="stylesheet" href="/assets/b-atoms.css">' : '<style>' . $style . '</style>') . '</head><body>'
        . '<header class="site-header"><div class="bar ' . $c['gap'] . ' ' . $c['shadow'] . '"><span class="tag ' . $c['caps'] . '">New</span>'
        . '<h3 class="logo ' . $c['font'] . ' ' . $c['color'] . '">Acme</h3><nav><ul class="' . $c['gap'] . '">' . $links . '</ul></nav></div></header>'
        . '<main>' . ('' === $lead ? '' : '<p class="' . $lead . '">Lead</p>') . '<h1>' . $title . '</h1><p>' . $title . ' body text.</p></main>'
        . '<footer><p>Copyright Acme.</p></footer></body></html>');
}

$roundTrip = static fn (array $value): array => json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$compiler = new ArtifactCompiler();
$shared = $roundTrip($compiler->prepareShared($artifact));
$prepared = $roundTrip($compiler->preparePages($artifact, $shared));
$staged = $compiler->compose($shared, $roundTrip($compiler->compilePreparedPages($shared, $prepared)))->toArray();
$plan = $staged['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$headers = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Routes sharing one header design share one header part, got ' . count($headers));
$markup = (string) $headers[0]['canonical_block_markup'];

// Each class rule of the emitted stylesheets, by class, with its declarations.
$rules = array();
foreach ($staged['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null)) continue;
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) ($asset['content'] ?? ''), $matches, PREG_SET_ORDER);
    foreach ($matches as [, $selectors, $body]) {
        foreach (explode(',', $selectors) as $selector) {
            if (preg_match('/^\s*\.([A-Za-z_][A-Za-z0-9_-]*)(?::not\([^()]*\))*\s*$/', $selector, $class)) $rules[$class[1]][] = preg_replace('/\s+/', '', $body);
        }
    }
}
$resolves = static function (string $tag, string $declarations) use ($markup, $rules): bool {
    if (! preg_match('/<' . $tag . '\b[^>]*\bclass="([^"]*)"/', $markup, $element)) return false;
    $wanted = preg_replace('/\s+/', '', $declarations);
    foreach (preg_split('/\s+/', trim($element[1])) ?: array() as $class) {
        foreach ($rules[$class] ?? array() as $body) if (str_contains(';' . $body . ';', ';' . $wanted . ';') || str_contains(';' . $body, ';' . $wanted)) return true;
    }
    return false;
};
$assert($resolves('h3', $meanings['font']), 'The shared logo heading wears a class that keeps its source font-family declaration: ' . $markup);
$assert($resolves('div', $meanings['shadow']), 'The shared bar wears a class that keeps its source box-shadow declarations: ' . $markup);

// Preparing pages one by one reads the same chrome a whole compile does.
$whole = $compiler->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'];
$wholeHeaders = array_values(array_filter($whole['template_parts'], static fn (array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($wholeHeaders) && $wholeHeaders[0]['canonical_block_markup'] === $markup, 'A staged compile emits the header part a whole compile emits.');

echo "route-aligned chrome class declarations: ok\n";
