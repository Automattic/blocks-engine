<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$files = array();
$css = '';
foreach (array('index', 'about', 'team') as $number => $slug) {
    $id = 'yui_3_17_2_1_179424834737' . $number . '_130';
    $class = 'blocks-engine-responsive-typography-' . substr(hash('sha256', (string) $number), 0, 12);
    $css .= '@media(min-width:700px){.' . $class . '{font-size:24px!important}}';
    $files[$slug . '.html'] = '<html><head><link rel="stylesheet" href="type.css"></head><body><header><p>Studio</p></header><main><h1>' . $slug . '</h1><p>Route body ' . $slug . '</p></main><footer><p>Copyright Studio</p></footer>'
        . '<div class="yui-popup-container-node" id="' . $id . '"><div class="sqs-slide-layer-content' . ($number ? ' scroll' : '') . '"><p class="' . $class . '">Newsletter dispatch</p><form method="post" class="newsletter"><label>Email <input type="email" name="email"></label><button type="submit">Join</button></form></div></div></body></html>';
}
$files['type.css'] = $css;
$input = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
unset($input['source_reports']['conversion_report'], $input['source_reports']['wordpress_site_plan']);
// Structural-path hashes may differ despite equal emitted rules. Force that
// compiler boundary explicitly so the identity contract cannot pass merely
// because the compiler happened to reuse one hash for this small fixture.
$compiled = &$input['source_reports']['compiled_site'];
$oldClass = '';
$generatedRules = '';
foreach ($compiled['pages'] as $number => &$document) {
    preg_match('/blocks-engine-responsive-typography-[a-f0-9]+/', $document['block_markup'], $match);
    $assert(isset($match[0]), 'The fixture emits responsive typography.');
    $oldClass = $match[0];
    $newClass = 'blocks-engine-responsive-typography-' . substr(hash('sha256', 'route-' . $number), 0, 12);
    $document['block_markup'] = str_replace($oldClass, $newClass, $document['block_markup']);
    $generatedRules .= '@media(min-width:700px){:root .' . $newClass . '{font-size:24px !important}}';
}
unset($document);
foreach ($compiled['assets'] as &$asset) {
    if ('css' !== ($asset['kind'] ?? null) || !str_contains($asset['content'] ?? '', ':root .' . $oldClass)) continue;
    $asset['content'] .= $generatedRules;
    $asset['hash'] = hash('sha256', $asset['content']);
    $asset['bytes'] = strlen($asset['content']);
}
unset($asset, $compiled);
$plan = (new WordPressSitePlan())->fromCompilerResult($input);
$footer = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)))[0] ?? array();
$assert(1 === substr_count($footer['canonical_block_markup'] ?? '', '<div id="yui_'), 'The trailing overlay moves once into the footer.');
foreach ($plan['pages'] as $page) $assert(!str_contains($page['canonical_block_markup'], 'yui-popup-container-node'), 'No route retains the shared overlay.');
$forms = array();
foreach ($plan['runtime_declarations'] as $declaration) {
    if ('forms' !== ($declaration['type'] ?? null)) continue;
    $entities = RuntimeEntityManifest::SCHEMA === ($declaration['payload']['schema'] ?? null)
        ? RuntimeEntityManifest::resolve($declaration['payload'], RuntimeEntityManifest::normalizeRecords($plan['runtime_entity_records']))
        : $declaration['payload']['entities'];
    $forms = array_merge($forms, $entities);
}
$assert(1 === count($forms), 'There is one runtime form, not one per route.');
$binding = $forms[0]['bindings'][0];
$assert($footer['source_path'] === $binding['source_path'] && $footer['source_path'] === $forms[0]['source_path'], 'Entity and binding are part-owned.');
$assert(WordPressSitePlan::bindingPosition($binding['position'], new BlockMarkupIndex($footer['canonical_block_markup']), $binding['search_block_markup']), 'The form binding resolves exactly in the footer.');
$assert(3 === count($forms[0]['replaced_fallback_identities'] ?? array()), 'One form reconciles all three source fallbacks.');
WordPressSitePlan::assertValid($plan);
preg_match('/blocks-engine-responsive-typography-[a-f0-9]+/', $footer['canonical_block_markup'], $movedClass);
$emittedCss = implode("\n", array_column($plan['assets'], 'content'));
$assert(isset($movedClass[0]) && (bool) preg_match('/' . preg_quote('.' . $movedClass[0], '/') . '\\{font-size:[^}]*24px[^}]*!important\\}/', $emittedCss), 'The moved copy keeps its conditional typography rule.');

$manifestInput = $input;
foreach ($manifestInput['source_reports']['compiled_site']['runtime_declarations'] as &$declaration) {
    if ('forms' !== ($declaration['type'] ?? null)) continue;
    $manifest = RuntimeEntityManifest::fromEntities('generic/forms/v1', $declaration['payload']['entities']);
    $declaration['payload'] = \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations::canonical($manifest['payload']);
    $manifestInput['source_reports']['compiled_site']['runtime_entity_records'] = $manifest['records'];
}
unset($declaration);
$manifestPlan = (new WordPressSitePlan())->fromCompilerResult($manifestInput);
WordPressSitePlan::assertValid($manifestPlan);
$manifestForms = array();
foreach ($manifestPlan['runtime_declarations'] as $declaration) {
    if ('forms' !== ($declaration['type'] ?? null)) continue;
    $manifestForms = RuntimeEntityManifest::SCHEMA === $declaration['payload']['schema']
        ? RuntimeEntityManifest::resolve($declaration['payload'], $manifestPlan['runtime_entity_records'])
        : $declaration['payload']['entities'];
}
$assert(1 === count($manifestForms) && $footer['source_path'] === $manifestForms[0]['bindings'][0]['source_path'], 'Manifest-backed form records also move and deduplicate into the part.');

// Unequal rules are authored presentation differences, not interchangeable hashes.
$unequalInput = $input;
foreach ($unequalInput['source_reports']['compiled_site']['assets'] as &$asset) {
    if ('css' !== ($asset['kind'] ?? null)) continue;
    $asset['content'] = str_replace('.blocks-engine-responsive-typography-' . substr(hash('sha256', 'route-2'), 0, 12) . '{font-size:24px', '.blocks-engine-responsive-typography-' . substr(hash('sha256', 'route-2'), 0, 12) . '{font-size:30px', $asset['content']);
    $asset['hash'] = hash('sha256', $asset['content']);
    $asset['bytes'] = strlen($asset['content']);
}
unset($asset);
$unequal = (new WordPressSitePlan())->fromCompilerResult($unequalInput);
foreach ($unequal['pages'] as $page) $assert(str_contains($page['canonical_block_markup'], 'yui-popup-container-node'), 'Different typography rules keep the overlay route-owned.');

// Force document-seeded markers at the compiler boundary, independently of
// whether this small source fixture happens to allocate the same context seed.
$markerCases = array(
    'control separate rules' => array('control', 'equal', true),
    'control grouped rule' => array('control', 'grouped', true),
    'control unrelated grouped arm' => array('control', 'unrelated', true),
    'control matching ancestor arm' => array('control', 'ancestor', false),
    'control unevaluable state arm' => array('control', 'state', false),
    'control unsupported arm' => array('control', 'unsupported', false),
    'control unequal declarations' => array('control', 'unequal', false),
    'control missing evidence' => array('control', 'missing', false),
    'control different media' => array('control', 'media', false),
    'control different container' => array('control', 'container', false),
    'semantic equivalent' => array('semantic', 'equal', true),
    'richtext equivalent' => array('richtext', 'equal', true),
    'source kind equivalent' => array('source-button', 'equal', true),
);
foreach ($markerCases as $name => [$kind, $mode, $moves]) {
    $markerInput = $input;
    $markerClasses = array();
    $markerRules = '';
    foreach ($markerInput['source_reports']['compiled_site']['pages'] as $number => &$document) {
        $class = 'blocks-engine-' . $kind . '-' . substr(hash('sha256', 'marker-route-' . $number), 0, 12) . '-' . $number;
        $markerClasses[] = $class;
        $document['block_markup'] = str_replace('sqs-slide-layer-content', 'sqs-slide-layer-content ' . $class, $document['block_markup']);
        if ('missing' === $mode || 'grouped' === $mode) continue;
        $width = 'media' === $mode && 2 === $number ? '900px' : '700px';
        $container = 'container' === $mode && 2 === $number ? '600px' : '500px';
        $display = 'unequal' === $mode && 2 === $number ? 'flex' : 'inline-flex';
        $arm = '.' . $class;
        if ('unrelated' === $mode && 2 === $number) $arm .= ',.header-nav-item :where(.' . $class . ')';
        if (2 === $number) {
            $context = array('ancestor' => '.yui-popup-container-node', 'state' => '.header-nav-item:hover', 'unsupported' => '.header-nav-item::before');
            if (isset($context[$mode])) $markerRules .= $context[$mode] . ' :where(.' . $class . '){display:flex}';
        }
        $markerRules .= '@media(min-width:' . $width . '){@container(min-width:' . $container . '){' . $arm . '{display:' . $display . ';align-items:center}}}';
    }
    unset($document);
    if ('grouped' === $mode) $markerRules = '.' . implode(',.', $markerClasses) . '{display:inline-flex;align-items:center}';
    foreach ($markerInput['source_reports']['compiled_site']['assets'] as &$asset) {
        if ('css' !== ($asset['kind'] ?? null) || !str_contains($asset['content'] ?? '', $generatedRules)) continue;
        $asset['content'] .= $markerRules;
        $asset['hash'] = hash('sha256', $asset['content']);
        $asset['bytes'] = strlen($asset['content']);
    }
    unset($asset);
    $markerPlan = (new WordPressSitePlan())->fromCompilerResult($markerInput);
    WordPressSitePlan::assertValid($markerPlan);
    $markerFooters = implode('', array_column(array_filter($markerPlan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)), 'canonical_block_markup'));
    $assert(($moves ? 1 : 0) === substr_count($markerFooters, '<div id="yui_'), $name . ': footer owns exactly one overlay only with equivalent evidence.');
    foreach ($markerPlan['pages'] as $page) {
        $assert($moves !== str_contains($page['canonical_block_markup'], 'yui-popup-container-node'), $name . ': route ownership follows rule equivalence.');
    }
    if ($moves) {
        $assert(str_contains($markerFooters, $markerClasses[0]), $name . ': the moved copy keeps its original marker.');
        $assert(str_contains(implode("\n", array_column($markerPlan['assets'], 'content')), '.' . $markerClasses[0]), $name . ': emitted CSS still addresses the moved copy.');
    }
}

$files['fourth.html'] = str_replace('Newsletter dispatch', 'Different authored newsletter', $files['team.html']);
$varying = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
foreach ($varying['pages'] as $page) $assert(str_contains($page['canonical_block_markup'], 'yui-popup-container-node'), 'Authored differences keep trailing overlays route-owned.');

foreach (array('href="#%s"', 'for="%s"', 'aria-labelledby="%s"') as $reference) {
    $a = '<div id="yui_123_a"></div>';
    $b = '<div id="yui_456_b"></div>';
    $assert(ShellExtraction::documentChromeIdentity($a) === ShellExtraction::documentChromeIdentity($b), 'Unreferenced runtime ids do not define authored identity.');
    $assert(ShellExtraction::documentChromeIdentity($a, $a . '<a ' . sprintf($reference, 'yui_123_a') . '></a>') !== ShellExtraction::documentChromeIdentity($b), 'Document references preserve runtime ids.');
}
$assert(ShellExtraction::documentChromeIdentity("<div id='yui_123_a'></div>") === ShellExtraction::documentChromeIdentity("<div id='yui_456_b'></div>"), 'Single-quoted runtime ids also normalize.');
$assert(ShellExtraction::documentChromeIdentity('<div data-id="yui_123_a"></div>') !== ShellExtraction::documentChromeIdentity('<div data-id="yui_456_b"></div>'), 'Authored data attributes remain significant.');
fwrite(STDOUT, "shared trailing newsletter overlay: ok\n");
