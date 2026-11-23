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
foreach (array('89-acme' => 'theme-89-acme', 'be-acme' => 'theme-be-acme', 'Acme Co' => 'acme-co') as $given => $expected) {
    $assert($expected === ClassPublisher::namespaceSlug($given), 'Class namespace ' . $given . ' publishes as the CSS identifier prefix ' . $expected . '.');
}

// The map is deterministic: same input, same names.
$again = $compile($artifact + array('class_namespace' => 'acme'));
$assert($published['plan_identity'] === $again['plan_identity'], 'Publishing is deterministic.');
$map = $published[ClassPublisher::MAP_KEY] ?? null;
$assert(is_array($map) && array() !== $map, 'The published plan exposes its name map.');
$assert($internal['quality']['metrics']['engine_class_token_share'] > 0, 'The fixture contains generated class tokens.');
$assert($published['quality']['metrics']['engine_class_token_share'] > 0, 'Publishing does not erase generated class provenance in the token share.');
$metricPlan = array('pages' => array(array('canonical_block_markup' => '<p class="acme-paragraph author">Text</p>')), ClassPublisher::MAP_KEY => array('blocks-engine-synthetic-paragraph' => 'acme-paragraph'));
$assert(0.5 === \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\UnreferencedEngineClasses::metrics($metricPlan)['engine_class_token_share'], 'Published generated tokens count, but unrelated author tokens do not.');
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
$assert(isset($carried['hero']) && in_array('acme-paragraph', $map, true), 'Source classes stay and engine classes are named from their evidence.');
preg_match_all('/"className":"([^"]*)"/', $publishedContent, $classLists);
foreach ($classLists[1] as $list) {
    $tokens = explode(' ', $list);
    foreach ($tokens as $token) if (1 === preg_match('/^(acme-.+)-\\d+$/', $token, $base) && in_array($base[1], $tokens, true)) $assert(false, 'Classes sharing evidence on one element read apart by role, not number: ' . $list);
}
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
    $assert(str_contains($publishedBootstrap, 'function acme_has_engine_prefix(') && str_contains($publishedBootstrap, "acme_has_engine_prefix( \$class, 'be-inline-geometry-' )"), 'The inline SVG runtime reads published carriers back to their engine names.');
}
$lint = tempnam(sys_get_temp_dir(), 'class-publisher-');
file_put_contents($lint, $publishedBootstrap);
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($lint) . ' 2>&1', $output, $status);
unlink($lint);
$assert(0 === $status, 'The published bootstrap is valid PHP: ' . implode("\n", $output));

// Explicit co-occurrence fixture, including a descendant selector and an
// unrelated carrier on a second route. The normal compiler still drives it.
$foldArtifact = $artifact;
$foldArtifact['files']['site.css'] .= '.be-fold-a{outline-color:red}.be-fold-b{outline-style:solid}.be-fold-a{outline-width:3px}.be-fold-a .child{opacity:.8}';
foreach (array('index.html', 'about.html') as $route) {
    $foldArtifact['files'][$route] = str_replace('<main>', '<main><div class="fold-role be-fold-a be-fold-b"><span class="child">Fold me</span></div>', $foldArtifact['files'][$route]);
}
$folded = $compile($foldArtifact + array('class_namespace' => 'acme'));
$foldMap = $folded[ClassPublisher::MAP_KEY];
$assert(isset($foldMap['be-fold-a'], $foldMap['be-fold-b']) && $foldMap['be-fold-a'] === $foldMap['be-fold-b'], 'Equal site-wide carrier sets fold.');
$foldName = $foldMap['be-fold-a'];
$foldCss = $stylesheets($folded);
$assert(str_contains($content($folded), 'fold-role ' . $foldName . '"'), 'The carrier has only one published class.');
$assert(str_contains($foldCss, '.' . $foldName . '{outline-color:red}') && str_contains($foldCss, '.' . $foldName . '{outline-style:solid}'), 'Separate declarations still select the folded carrier.');
$assert(str_contains($foldCss, '.' . $foldName . '{outline-width:3px}'), 'The remaining declaration selects the folded carrier.');
$assert($folded === $compile($foldArtifact + array('class_namespace' => 'acme')), 'Folded output is identical across compiles.');

// Direct selector contract keeps opaque selector bytes and repeated authored
// specificity, while changing only the mapped class tokens.
$assert('.x .child,.x:hover{color:red}' === ClassPublisher::renamedSelectors('.be-a .child,.be-a.be-b:hover{color:red}', array('be-a' => 'x', 'be-b' => 'x')), 'Only folded compound tokens deduplicate.');
$assert('[title=".be-a"] .x{content:".be-a"}' === ClassPublisher::renamedSelectors('[title=".be-a"] .be-a{content:".be-a"}', array('be-a' => 'x')), 'Selector strings and declarations are opaque.');

// A competing two-class rule would overtake the folded compound; veto the
// entire group rather than adding specificity shims or changing rule order.
$unsafeArtifact = $foldArtifact;
$unsafeArtifact['files']['site.css'] .= '.be-fold-a.be-fold-b{outline-width:6px}.fold-role.child{outline-width:5px}';
$unsafe = $compile($unsafeArtifact + array('class_namespace' => 'acme'));
$assert($unsafe[ClassPublisher::MAP_KEY]['be-fold-a'] !== $unsafe[ClassPublisher::MAP_KEY]['be-fold-b'], 'A competing compound keeps the delivered hooks separate.');
$guard = new ReflectionMethod(ClassPublisher::class, 'foldPreservesCascade');
$assert(!$guard->invoke(null, '.be-a.be-b{color:red}.source.other{color:blue}', array('be-a' => 'x', 'be-b' => 'x')), 'A specificity-order change vetoes folding.');
$assert($guard->invoke(null, '.be-a.be-b{color:red}', array('be-a' => 'x', 'be-b' => 'x')), 'An uncontested compound can fold.');
$foldGroups = new ReflectionMethod(ClassPublisher::class, 'folds');
$assert(array() === $foldGroups->invoke(null, array('be-a' => array(0, 1), 'be-b' => array(0)), array('be-a' => 0, 'be-b' => 1), '.be-a{color:red}.be-b{color:blue}'), 'One extra occurrence in another document prevents folding.');
$assert(array() === $foldGroups->invoke(null, array(), array('be-a' => 0, 'be-b' => 1), '.be-a{color:red}.be-b{color:blue}'), 'CSS-only hooks have no equal element set to fold.');

// A numeric class attribute predicate (issue #7381) must not break publishing.
$numeric = $compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<style>[class*="1"]{color:red}.box{display:flex;gap:20px;padding:10px}</style><main><div class="box" style="transform:translateX(4px)"><p>Left</p><p>Right</p></div></main>')) + array('class_namespace' => 'acme'));
$assert(is_array($numeric[ClassPublisher::MAP_KEY] ?? null), 'A numeric class attribute selector still compiles a published plan.');

// A class attribute selector carries the engine-free guard
// (`:where(:not([class*="blocks-engine-"]))`) on its authored arm. The guard
// names a prefix, not a class: engine classes still publish, the guard is
// restated in the namespace, and every element the authored arm matched it
// still matches, no other.
$guardCss = '.hero span{color:red}.hero p{margin:0 0 8px}[class*=rich-text]{letter-spacing:1px}[class$=__text]{text-transform:uppercase}';
$guardPage = static fn (string $title): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="site.css"></head><body><main><section id="about" class="hero">'
    . '<h2 class="rich-text__title">About <span class="x">us</span></h2><p class="wixui-rich-text__text">Text</p><p class="rich-text-lede" style="transform:translateX(4px)">Lede</p></section>'
    . '<div class="plain"><p id="note" class="note__text">Story ' . $title . '</p></div></main></body></html>';
$guardArtifact = array('entrypoint' => 'index.html', 'files' => array('site.css' => $guardCss, 'index.html' => $guardPage('Home'), 'about.html' => $guardPage('About')));
$guardInternal = $compile($guardArtifact);
$guardPublished = $compile($guardArtifact + array('class_namespace' => 'acme'));
$guardRule = '/\[class([*^$|]?)=([A-Za-z0-9_-]+)\]:where\(((?::not\(\[class\*="[A-Za-z0-9_-]+"\]\))+)\)/';
$authoredArms = static function (string $css) use ($guardRule): array {
    preg_match_all($guardRule, $css, $rules, PREG_SET_ORDER);
    return array_map(static fn (array $rule): array => array($rule[1], $rule[2], preg_match_all('/\*="([^"]+)"/', $rule[3], $excluded) ? $excluded[1] : array()), $rules);
};
$armMatches = static function (array $arm, string $classes): bool {
    [$operator, $value, $excluded] = $arm;
    foreach ($excluded as $prefix) if (str_contains($classes, $prefix)) return false;
    return match ($operator) { '*' => str_contains($classes, $value), '$' => str_ends_with($classes, $value), '^' => str_starts_with($classes, $value), default => $classes === $value };
};
$internalArms = $authoredArms($stylesheets($guardInternal));
$publishedArms = $authoredArms($stylesheets($guardPublished));
$assert(2 === count($internalArms) && array('blocks-engine-') === $internalArms[0][2], 'The fixture ships guarded class attribute selectors.');
$assert(2 === count($publishedArms) && array('acme-') === $publishedArms[0][2] && array('acme-') === $publishedArms[1][2], 'The guard is restated in the namespace.');
$assert(!str_contains($stylesheets($guardPublished), '[class*="blocks-engine-"]'), 'No published guard names the engine prefix.');
$guardMap = $guardPublished[ClassPublisher::MAP_KEY];
$assert(array() !== array_filter(array_keys($guardMap), static fn (string $class): bool => str_starts_with($class, 'blocks-engine-')), 'The guard does not pin every blocks-engine class.');
$assert(!str_contains($content($guardPublished), 'blocks-engine-subject'), 'Classes nothing names are still removed under the guard.');
foreach (array('pages', 'template_parts') as $group) foreach ($guardInternal[$group] as $index => $unit) {
    preg_match_all('/\sclass="([^"]*)"/', $unit['canonical_block_markup'], $before);
    preg_match_all('/\sclass="([^"]*)"/', $guardPublished[$group][$index]['canonical_block_markup'], $after);
    $assert(count($before[1]) === count($after[1]), 'Publishing keeps every class attribute.');
    foreach ($before[1] as $element => $classes) foreach ($internalArms as $rule => $arm) {
        $assert($armMatches($arm, $classes) === $armMatches($publishedArms[$rule], $after[1][$element]), 'The authored arm matches the same elements once published: "' . $classes . '" -> "' . $after[1][$element] . '".');
    }
}

$bootstrapMethod = new ReflectionMethod(ClassPublisher::class, 'publishedBootstrap');
$runtimeFixture = $bootstrapMethod->invoke(null, "<?php\nfunction folded_runtime_probe( \$class ) { return str_starts_with( \$class, 'be-inline-geometry-' ); }\n", array('be-inline-geometry-example' => 'fold-hook', 'blocks-engine-example' => 'fold-hook'), 'fold-test');
eval(substr($runtimeFixture, 6));
$assert(folded_runtime_probe('fold-hook') && fold_test_has_engine_prefix('fold-hook', 'blocks-engine-'), 'Runtime prefix lookups see every folded hook.');
$assert(fold_test_matches_engine_class('/^be-inline-geometry-/', 'fold-hook'), 'Runtime pattern lookups see every folded hook.');

$maximum = static function (string $markup, array $names): int {
    preg_match_all('/\\sclass="([^"]*)"|"className":"([^"]*)"/', $markup, $lists, PREG_SET_ORDER);
    $max = 0;
    foreach ($lists as $list) $max = max($max, count(array_intersect(preg_split('/\\s+/', ($list[1] ?? '') . ($list[2] ?? '')) ?: array(), $names)));
    return $max;
};
// Baseline is phase 2's one-name-per-hook behavior, using exactly the hooks
// retained in this plan (not unreferenced engine bookkeeping).
$beforeMax = $maximum($content($internal), array_keys($map));
$afterMax = $maximum($publishedContent, array_values(array_unique($map)));
$assert($afterMax <= $beforeMax, 'Folding never increases the maximum published classes per element.');
echo "class-publisher: max published classes per element {$beforeMax} -> {$afterMax}\n";
echo "class-publisher: ok\n";
