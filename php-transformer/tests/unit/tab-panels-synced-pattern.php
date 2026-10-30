<?php
declare(strict_types=1);

require_once __DIR__ . '/../fixtures/captured-components.php';

/**
 * Content every tab panel repeats is emitted once, as a synced pattern
 * (Automattic/blocks-engine#3132).
 *
 * A captured selectable set that switches only part of its region writes the
 * unchanged rest into every core/tab-panel. The site plan declares that shared
 * subtree once in `synced_patterns`, and each panel references it with a
 * core/block whose ref is the entity token. The switching part stays per panel.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\SyncedPatternProjection;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ($ok) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . substr($detail, 0, 800) : '') . PHP_EOL);
};

$labels = array('Alpha', 'Beta', 'Gamma');
$aside = '<aside class="shared-aside"><h3>Opening hours</h3><ul><li>Monday to Friday</li><li>Saturday mornings</li></ul></aside>';
$region = static fn(string $label, string $shared): string => '<div class="card"><div class="description"><p>' . $label . ' description text</p></div>' . $shared . '</div>';
$plan = static function (callable $shared, string $css = '') use ($labels, $region): array {
    $states = array();
    foreach ($labels as $index => $label) {
        $html = $region($label, $shared($label));
        $states[] = array(
            'status' => 'captured',
            'kind' => 'selectable-set',
            'trigger' => array('selector' => 'body > main > div > button:nth-of-type(' . ($index + 1) . ')', 'tag' => 'button', 'label' => $label, 'ariaHaspopup' => '', 'dataBindings' => array()),
            'dialog' => array('selector' => 'body > main > div:nth-of-type(2)', 'tag' => 'div', 'html' => $html, 'htmlBytes' => strlen($html), 'htmlTruncated' => false),
            'set' => array('selector' => 'body > main > div:nth-of-type(1)', 'size' => count($labels), 'index' => $index),
        );
    }
    $source = '<html><head><title>Tabs</title><style>.card{display:grid;grid-template-columns:2fr 1fr}.card>.shared-aside{background:#eee;padding:12px}' . $css . '</style></head><body><main><div>'
        . implode('', array_map(static fn(string $label): string => '<button type="button">' . $label . '</button>', $labels))
        . '</div>' . $region($labels[0], $shared($labels[0])) . '</main></body></html>';
    return (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'website/index.html',
        'files' => array(
            array('path' => 'website/index.html', 'content' => $source),
            array('path' => 'capture-receipt.json', 'content' => (string) json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))), JSON_UNESCAPED_SLASHES)),
            array('path' => 'interaction-states.json', 'content' => (string) json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => captured_components($states)))), JSON_UNESCAPED_SLASHES)),
        ),
        'reports' => array('capture-receipt.json', 'interaction-states.json'),
    ))->toWordPressSitePlanView()['wordpress_site_plan'] ?? array();
};
$panels = static function (string $markup): array {
    preg_match_all('/<!-- wp:tab-panel .*?<!-- \/wp:tab-panel -->/s', $markup, $matches);
    return $matches[0];
};

$shared = $plan(static fn(string $label): string => $aside);
$markup = (string) ($shared['pages'][0]['canonical_block_markup'] ?? '');
$patterns = $shared['synced_patterns'] ?? array();
$assert(1 === count($patterns), 'the aside every panel repeats becomes one synced_patterns entry', (string) json_encode($patterns));
$pattern = $patterns[0] ?? array();
$token = SyncedPatternProjection::token((string) ($pattern['identity'] ?? ''));
$reference = '<!-- wp:block {"ref":"' . WordPressSitePlan::SYNCED_PATTERN_TOKEN_PREFIX . $token . '}}"} /-->';
$assert(array('identity', 'title', 'canonical_block_markup', 'source_path', 'provenance') === array_keys($pattern), 'the entity declares identity, title, markup, source and provenance');
$assert(1 === preg_match('/^pattern-[a-f0-9]{16}$/', $token), 'the token names the entity identity');
$assert('Opening hours' === ($pattern['title'] ?? null), 'the title is the shared subtree\'s first heading');
$assert(SyncedPatternProjection::SOURCE_PATH_PREFIX . $token === ($pattern['source_path'] ?? null), 'the entity is its own source document');
$assert('website/index.html' === ($pattern['provenance']['occurrences'][0]['source_path'] ?? null), 'the provenance names the page the panels belong to');
$assert(str_contains((string) ($pattern['canonical_block_markup'] ?? ''), 'shared-aside') && str_contains((string) ($pattern['canonical_block_markup'] ?? ''), 'Saturday mornings') && !str_contains((string) ($pattern['canonical_block_markup'] ?? ''), 'description text'), 'the pattern holds the aside and nothing that switches');
$assert('explicit_refs/v1' === ($shared['reference_semantics']['synced_patterns'] ?? null), 'the plan declares the synced pattern reference contract');
$panelMarkup = $panels($markup);
$assert(3 === count($panelMarkup), 'the set keeps three tab panels', $markup);
foreach ($panelMarkup as $index => $panel) {
    $assert(1 === substr_count($panel, $reference), 'panel ' . $index . ' references the shared pattern where the aside was', $panel);
    $assert(!str_contains($panel, 'Opening hours') && !str_contains($panel, 'shared-aside'), 'panel ' . $index . ' no longer holds its own aside copy', $panel);
    $assert(str_contains($panel, $labels[$index] . ' description text'), 'panel ' . $index . ' keeps its own description', $panel);
    $assert(strpos($panel, 'description text') < strpos($panel, $reference), 'panel ' . $index . ' keeps the reference at the aside position', $panel);
}
$css = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($shared['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null))));
$assert(str_contains($css, '.card>.shared-aside{'), 'the authored panel-ancestor rule still reaches the in-place pattern content on the front end');
$assert(!str_contains($css, '&>:where('), 'shell selectors need no editor layer restatement', $css);
$bootstrap = implode('', array_map(static fn(array $write): string => (string) ($write['payload']['data'] ?? ''), array_filter($shared['writes'] ?? array(), static fn(array $write): bool => 'functions.php' === ($write['target_path'] ?? null))));
$assert(str_contains($bootstrap, SyncedPatternProjection::EDITOR_CONTAINER_CSS), 'the editor canvas lets the core/block wrapper take no box');

$resolved = (new WordPressSitePlanResolver())->resolve($shared, array('theme_uri' => 'https://destination.example/themes/tabs'));
$assert(($resolved['synced_patterns'][0]['resolved_block_markup'] ?? null) === ($pattern['canonical_block_markup'] ?? false), 'the resolver projects the pattern content');
$assert(str_contains((string) ($resolved['pages'][0]['resolved_block_markup'] ?? ''), $reference), 'pattern refs survive asset resolution for the WordPress materializer');
foreach (array('unreferenced', 'undeclared', 'contract') as $case) {
    $bad = $shared;
    if ('unreferenced' === $case) $bad['pages'][0]['canonical_block_markup'] = str_replace($reference, '', $markup);
    elseif ('undeclared' === $case) $bad['synced_patterns'][0]['identity'] = str_repeat('a', 64);
    else unset($bad['reference_semantics']['synced_patterns']);
    try {
        SyncedPatternProjection::assertReferences($bad);
        $assert(false, 'an invalid synced pattern contract is rejected: ' . $case);
    } catch (InvalidArgumentException) {
        $assert(true, 'an invalid synced pattern contract is rejected: ' . $case);
    }
}

$distinct = $plan(static fn(string $label): string => '<aside class="shared-aside"><h3>' . $label . ' hours</h3></aside>');
$assert(!array_key_exists('synced_patterns', $distinct) && !isset($distinct['reference_semantics']['synced_patterns']), 'panels that share nothing produce no pattern');
$assert(!str_contains((string) ($distinct['pages'][0]['canonical_block_markup'] ?? ''), WordPressSitePlan::SYNCED_PATTERN_TOKEN_PREFIX), 'panels that share nothing keep their content inline');

// A shared aside holding a form: the form's runtime binding moves into the
// pattern document once, instead of the aside staying duplicated per panel.
$bindings = static function (array $plan): array {
    $found = array();
    foreach ($plan['runtime_declarations'] ?? array() as $declaration) {
        $payload = $declaration['payload'] ?? array();
        $entities = 'blocks-engine/runtime-entity-manifest/v1' === ($payload['schema'] ?? null)
            ? Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest::resolve($payload, $plan['runtime_entity_records'] ?? array()) : ($payload['entities'] ?? array());
        foreach ($entities as $entity) foreach ($entity['bindings'] ?? array() as $binding) if ('form' === ($binding['role'] ?? null)) $found[] = $binding + array('_entity_source_path' => $entity['source_path'] ?? null);
    }
    return $found;
};
$formAside = '<aside class="shared-aside"><h3>Get Connected</h3><form id="signup" class="signup-form"><label>Your name<input type="text" name="name" required></label><label>Email<input type="email" name="email" required></label><button type="submit">Send Message</button></form></aside>';
$form = $plan(static fn(string $label): string => $formAside);
$formMarkup = (string) ($form['pages'][0]['canonical_block_markup'] ?? '');
$formPatterns = $form['synced_patterns'] ?? array();
$assert(1 === count($formPatterns), 'a shared aside holding a bound form becomes one synced pattern', (string) json_encode(array_column($formPatterns, 'title')));
$formPattern = $formPatterns[0] ?? array();
$formToken = SyncedPatternProjection::token((string) ($formPattern['identity'] ?? ''));
$formSource = SyncedPatternProjection::SOURCE_PATH_PREFIX . $formToken;
$assert('Get Connected' === ($formPattern['title'] ?? null), 'the form pattern is titled by its heading');
$assert($formSource === ($formPattern['source_path'] ?? null), 'the pattern document has its own source path namespace');
$assert(str_contains((string) ($formPattern['canonical_block_markup'] ?? ''), '<form'), 'the pattern holds the form');
foreach ($panels($formMarkup) as $index => $panel) {
    $assert(!str_contains($panel, '<form') && str_contains($panel, WordPressSitePlan::SYNCED_PATTERN_TOKEN_PREFIX . $formToken), 'panel ' . $index . ' references the form pattern instead of its own form copy', $panel);
}
$formBindings = $bindings($form);
$assert(1 === count($formBindings), 'the per-panel form bindings collapse to one', (string) json_encode(array_column($formBindings, 'source_path')));
$formBinding = $formBindings[0] ?? array();
$assert($formSource === ($formBinding['source_path'] ?? null) && $formSource === ($formBinding['_entity_source_path'] ?? null), 'the remaining binding and its entity address the pattern document');
$offset = strpos((string) ($formPattern['canonical_block_markup'] ?? ''), (string) ($formBinding['search_block_markup'] ?? "\0"));
$assert(false !== $offset && 1 === ($formBinding['occurrence'] ?? null) && $offset === ($formBinding['position']['offset'] ?? null), 'the binding occurrence and position are recomputed on the pattern markup', (string) json_encode($formBinding['position'] ?? null));
$assert(array() === array_filter($formBindings, static fn(array $binding): bool => 'website/index.html' === ($binding['source_path'] ?? null)), 'no binding occurrence is left in the panels');
$resolvedForm = (new WordPressSitePlanResolver())->resolve($form, array('theme_uri' => 'https://destination.example/themes/tabs'));
$assert(array($formSource) === array_column($bindings($resolvedForm), 'source_path'), 'the resolver projects the binding on the resolved pattern document');

// Shared single text blocks are not patterns; nor is content a panel hides.
$text = $plan(static fn(string $label): string => '<p class="note">Free for everyone</p><h4>Questions?</h4>');
$assert(!array_key_exists('synced_patterns', $text) && !str_contains((string) ($text['pages'][0]['canonical_block_markup'] ?? ''), WordPressSitePlan::SYNCED_PATTERN_TOKEN_PREFIX), 'panels that share only short text leaves produce no pattern', (string) json_encode(array_column($text['synced_patterns'] ?? array(), 'title')));
$hidden = $plan(static fn(string $label): string => str_replace('class="shared-aside"', 'class="shared-aside is-collapsed"', $aside), '.is-collapsed{display:none}');
$assert(!array_key_exists('synced_patterns', $hidden), 'content every panel hides (display:none) produces no pattern', (string) json_encode(array_column($hidden['synced_patterns'] ?? array(), 'title')));

fwrite(STDOUT, "Tab panels synced pattern: {$passes} passed, {$failures} failed\n");
exit($failures ? 1 : 0);
