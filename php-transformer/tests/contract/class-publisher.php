<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ClassPublisher;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\InlineSvgImageRuntime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationAnchorRuntime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RuntimeAttributesRuntime;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// The publish step: with the theme's class namespace, a compiled site ships
// in the theme's own class names. Engine stages keep their internal
// vocabulary; one final pass renames what ships, the same way everywhere.
$css = '.site-header{display:flex;justify-content:space-between;padding:16px 24px;background:#111;color:#fff}.site-header a{color:#fff;margin-right:12px}'
    . '.hero{padding:48px;background:#f4f0e8}.hero h1{font-size:48px}.card-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.card{border:1px solid #ddd;padding:12px}';
$page = static fn (string $title): string => '<!doctype html><html><head><title>' . $title . ' | Acme</title><link rel="stylesheet" href="site.css"></head><body>'
    . '<header class="site-header"><a href="index.html" class="logo"><svg class="logo-mark" width="24" height="24" viewBox="0 0 24 24" style="margin-right:8px"><circle cx="12" cy="12" r="10" fill="#c00"/></svg>Acme</a>'
    . '<nav><a href="index.html">Home</a><a href="about.html">About</a></nav></header>'
    . '<main><section class="hero"><h1>' . $title . '</h1><p style="margin-top:24px;max-width:480px">Welcome to ' . $title . '.</p></section>'
    . '<div class="card-grid"><div class="card"><h2>One</h2><p>A</p></div><div class="card"><h2>Two</h2><p>B</p></div><div class="card" style="transform:translateX(4px)"><h2>Three</h2><p>C</p></div></div>'
    . '<div style="display:flex;gap:20px;padding:10px"><p>Left</p><p>Right</p></div></main>'
    . '<footer><p>&copy; Acme</p></footer></body></html>';
$artifact = array(
    'entrypoint' => 'index.html',
    'files' => array('site.css' => $css, 'index.html' => $page('Home'), 'about.html' => $page('About')),
);
$compile = static fn (array $artifact): array => (new ArtifactCompiler())->compile($artifact)->toWordPressSitePlanView()['wordpress_site_plan'];

$engineIdent = '/(?<![A-Za-z0-9_-])(?:blocks-engine-|be-)[A-Za-z0-9_-]+/';
$content = static function (array $plan): string {
    $markup = array();
    foreach (array('pages', 'template_parts', 'templates', 'synced_patterns', 'menus') as $group) foreach ($plan[$group] ?? array() as $unit) {
        foreach (array('canonical_block_markup', 'block_markup') as $field) if (is_string($unit[$field] ?? null)) $markup[] = $unit[$field];
    }
    return implode("\n", $markup);
};
$stylesheets = static function (array $plan): string {
    $css = array();
    foreach ($plan['assets'] as $asset) if ('css' === ($asset['kind'] ?? null)) $css[] = (string) ($asset['content'] ?? '');
    foreach ($plan['writes'] as $write) if ('theme.json' === $write['target_path']) {
        $theme = json_decode($write['payload']['data'], true);
        array_walk_recursive($theme, static function (mixed $value, mixed $key) use (&$css): void {
            if ('css' === $key && is_string($value)) $css[] = $value;
        });
    }
    return implode("\n", $css);
};
$bootstrap = static function (array $plan): string {
    foreach ($plan['writes'] as $write) if ('functions.php' === $write['target_path']) return $write['payload']['data'];
    return '';
};
$classTokens = static function (string $markup): array {
    $tokens = array();
    preg_match_all('/\sclass="([^"]*)"|"className":"([^"]*)"/', $markup, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) foreach (preg_split('/\s+/', trim(($match[1] ?? '') . ($match[2] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $token) $tokens[$token] = true;
    return $tokens;
};

$internal = $compile($artifact);
$published = $compile($artifact + array('class_namespace' => 'acme'));

// Without the option the plan is published unchanged: engine names, no map.
$assert(!isset($internal[ClassPublisher::MAP_KEY]), 'Without a class namespace the plan carries no published name map.');
$assert(1 === preg_match($engineIdent, $content($internal)), 'Without a class namespace content keeps its engine classes.');
$blank = $compile($artifact + array('class_namespace' => ' '));
$assert($content($blank) === $content($internal) && $blank['assets'] === $internal['assets'] && $bootstrap($blank) === $bootstrap($internal), 'A blank class namespace publishes nothing.');
$assert($internal['plan_identity'] === $compile($artifact)['plan_identity'], 'The unpublished plan is deterministic.');

// The map is deterministic: same input, same names.
$again = $compile($artifact + array('class_namespace' => 'acme'));
$assert($published['plan_identity'] === $again['plan_identity'], 'Publishing is deterministic.');
$map = $published[ClassPublisher::MAP_KEY] ?? null;
$assert(is_array($map) && array() !== $map, 'The published plan exposes its name map.');
$assert(!array_filter($published['diagnostics'], static fn (array $diagnostic): bool => ClassPublisher::DIAGNOSTIC === ($diagnostic['code'] ?? null)), 'The fixture publishes without falling back.');

// No engine token or digest is left in shipped content or CSS.
$publishedContent = $content($published);
$publishedCss = $stylesheets($published);
preg_match_all($engineIdent, $publishedContent . "\n" . $publishedCss, $left);
$assert(array() === $left[0], 'Shipped content and CSS name no engine class: ' . implode(', ', array_unique($left[0])));
foreach (array_keys($classTokens($publishedContent)) as $token) {
    $assert(1 !== preg_match('/(?:^|-)[a-f0-9]{8,}(?:-|$)/', $token), 'Shipped content carries no digest class: ' . $token);
}
preg_match_all('/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', (string) preg_replace('/\{[^{}]*\}/', '{}', $publishedCss), $selectorClasses);
foreach ($selectorClasses[1] as $class) $assert(1 !== preg_match('/(?:^|-)[a-f0-9]{8,}(?:-|$)/', $class), 'Shipped CSS selects no digest class: ' . $class);

// Every renamed class is theme-namespaced, and every one content carries is
// still selected by what the theme delivers.
$delivered = $publishedCss . "\n" . $bootstrap($published);
$carried = $classTokens($publishedContent);
foreach ($map as $engine => $name) {
    $assert(1 === preg_match($engineIdent, $engine), 'The map is keyed by engine classes.');
    $assert(str_starts_with($name, 'acme-') && 1 !== preg_match('/[a-f0-9]{8,}/', $name), 'A published name is theme-namespaced and readable: ' . $name);
    if (isset($carried[$name])) $assert(1 === preg_match('/\.' . preg_quote($name, '/') . '(?![A-Za-z0-9_-])/', $delivered), 'Delivered CSS still selects ' . $name . '.');
}
$assert(count(array_unique($map)) === count($map), 'Each engine class has its own published name.');
$assert(isset($carried['hero']) && in_array('acme-paragraph', $map, true), 'Source classes stay and engine classes are named from their evidence.');
foreach ($map as $engine => $name) if (str_starts_with($engine, 'be-inline-geometry-')) $assert(1 !== preg_match('/^acme-.*-[a-f0-9]{6,}$/', $name), 'Geometry carriers take readable names: ' . $name);

// One engine class has one name site-wide: the shared header ships once,
// in published names, and every route keeps its identical copy.
$headers = array_values(array_filter($published['template_parts'], static fn (array $part): bool => 'header' === $part['slug']));
$assert(1 === count($headers), 'The shared header is one template part.');
$internalHeaders = array_values(array_filter($internal['template_parts'], static fn (array $part): bool => 'header' === $part['slug']));
$assert($internalHeaders[0]['canonical_block_markup'] !== $headers[0]['canonical_block_markup'], 'The shared header ships in published names.');
foreach ($published['pages'] as $route) $assert(!str_contains($route['canonical_block_markup'], 'site-header'), 'No route restates the shared header.');
foreach ($published['templates'] as $template) if (str_contains($template['canonical_block_markup'], '"slug":"header"')) {
    $assert(1 !== preg_match($engineIdent, $template['canonical_block_markup']), 'Templates reference the shared header in published names.');
}
$assert($content($again) === $publishedContent, 'Shared parts and route copies are identical across compiles.');

// An engine class nothing shipped references is removed, not renamed.
$navigationInstance = null;
foreach (array_keys($classTokens($internalHeaders[0]['canonical_block_markup'])) as $token) if (str_starts_with($token, 'blocks-engine-navigation-instance-')) $navigationInstance = $token;
$assert(is_string($navigationInstance), 'The internal header carries a navigation instance class.');
$assert(!str_contains($stylesheets($internal) . $bootstrap($internal), $navigationInstance), 'No stylesheet or runtime references the navigation instance class.');
$assert(!isset($map[$navigationInstance]), 'The unreferenced class has no published name.');
$assert(str_contains($headers[0]['canonical_block_markup'], '<!-- wp:navigation {"className":"acme-'), 'The navigation keeps only its referenced classes.');

// Block markup still parses, and each block's comment className matches its
// rendered class.
$runtime = new Runtime();
$checkBlocks = static function (array $blocks) use (&$checkBlocks, $assert): int {
    $checked = 0;
    foreach ($blocks as $block) {
        $className = $block['attrs']['className'] ?? null;
        $html = trim((string) ($block['innerHTML'] ?? ''));
        if (is_string($className) && '' !== $html && preg_match('/^<[a-z][a-z0-9-]*\b[^>]*\sclass="([^"]*)"/i', $html, $match)) {
            $rendered = preg_split('/\s+/', $match[1], -1, PREG_SPLIT_NO_EMPTY) ?: array();
            $missing = array_diff(preg_split('/\s+/', $className, -1, PREG_SPLIT_NO_EMPTY) ?: array(), $rendered);
            $assert(array() === $missing, 'Block ' . $block['blockName'] . ' renders its className: ' . implode(' ', $missing));
            ++$checked;
        }
        $checked += $checkBlocks($block['innerBlocks'] ?? array());
    }
    return $checked;
};
$checked = 0;
foreach (array_merge($published['pages'], $published['template_parts']) as $unit) {
    $blocks = $runtime->parseBlocks($unit['canonical_block_markup']);
    $assert(array() !== $blocks && null !== ($blocks[0]['blockName'] ?? null), 'Published markup parses as blocks: ' . $unit['slug']);
    $checked += $checkBlocks($blocks);
}
$assert($checked > 3, 'Published blocks with classes were checked.');

// Writes ship the published bytes the plan describes.
foreach ($published['template_parts'] as $part) foreach ($published['writes'] as $write) {
    if ('parts/' . $part['slug'] . '.html' === $write['target_path']) $assert($write['payload']['data'] === $part['canonical_block_markup'], 'The part write ships the published part.');
}

// Emitted runtimes that recognize engine classes by prefix get the map: each
// matcher the publisher rewrites is what its runtime emits.
foreach (array(InlineSvgImageRuntime::class, RuntimeAttributesRuntime::class, NavigationAnchorRuntime::class) as $runtimeClass) {
    foreach ($runtimeClass::PUBLISHED_CLASS_MATCHERS as $matcher => $_) $assert(str_contains($runtimeClass::source(), $matcher), $runtimeClass . ' emits the matcher ClassPublisher rewrites: ' . $matcher);
}
$publishedBootstrap = $bootstrap($published);
if (str_contains($bootstrap($internal), InlineSvgImageRuntime::PUBLISHED_CLASS_MATCHERS[array_key_first(InlineSvgImageRuntime::PUBLISHED_CLASS_MATCHERS)])) {
    $assert(str_contains($publishedBootstrap, 'function acme_engine_class(') && str_contains($publishedBootstrap, "str_starts_with( acme_engine_class( \$class ), 'be-inline-geometry-' )"), 'The inline SVG runtime reads published carriers back to their engine names.');
}
$lint = tempnam(sys_get_temp_dir(), 'class-publisher-');
file_put_contents($lint, $publishedBootstrap);
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($lint) . ' 2>&1', $output, $status);
unlink($lint);
$assert(0 === $status, 'The published bootstrap is valid PHP: ' . implode("\n", $output));

echo "class-publisher: ok\n";
