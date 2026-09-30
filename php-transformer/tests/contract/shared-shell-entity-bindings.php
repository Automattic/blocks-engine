<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$entities = static function (array $plan): array {
    $out = array();
    foreach ($plan['runtime_declarations'] as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) $out[] = $entity;
    return $out;
};
$page = static fn (string $title, string $footer): string => '<!doctype html><html><head><style>[data-slot=signup]{display:grid;gap:8px}</style></head><body>'
    . '<header><nav><a href="index.html">Home</a><a href="about.html">About</a><a href="team.html">Team</a></nav></header>'
    . '<main><h1>' . $title . '</h1><p>Body for ' . $title . '</p></main>' . $footer . '</body></html>';
$signup = '<footer id="site-foot"><img src="logo.png" alt="Studio mark" width="40" height="40"><p>Keep in touch</p><form method="post" class="newsletter" data-slot="signup"><label for="email">Email</label><input id="email" type="email" name="email" required><button type="submit">Join</button></form><p>© Studio</p></footer>';
$logo = array('path' => 'logo.png', 'kind' => 'image', 'mime_type' => 'image/png', 'content_base64' => base64_encode("\x89PNG\r\n\x1a\n"));
$compile = static fn (array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files + array('logo.png' => $logo)))->toWordPressSitePlanView()['wordpress_site_plan'];

// The same footer, newsletter form included, on every page: one shared part
// that owns one form entity, bound to the part rather than to any page.
$plan = $compile(array('index.html' => $page('Home', $signup), 'about.html' => $page('About', $signup), 'team.html' => $page('Team', $signup)));
$footer = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'footer' === ($part['area'] ?? null)))[0] ?? null;
$assert(is_array($footer) && 'shared_shell' === ($footer['placement']['kind'] ?? null), 'A footer containing the same form on every page extracts as one shared part: ' . json_encode(array_column($plan['diagnostics'], 'code')));
$forms = array_values(array_filter($entities($plan), static fn (array $entity): bool => 'form' === ($entity['bindings'][0]['role'] ?? null)));
$assert(1 === count($forms), 'The shared footer carries one form entity, not a copy per page: ' . count($forms));
$binding = $forms[0]['bindings'][0];
$assert($footer['source_path'] === $binding['source_path'] && $footer['source_path'] === $forms[0]['source_path'], 'The form entity and its binding belong to the shared footer part.');
$assert(3 === count($forms[0]['replaced_fallback_identities'] ?? array()) && ($forms[0]['replaced_fallback_identities'] ?? null) === array_values(array_unique($forms[0]['replaced_fallback_identities'])) && in_array($forms[0]['fallback_identity'], $forms[0]['replaced_fallback_identities'], true), 'The shared form lists the source fallback of every page it replaces: ' . json_encode($forms[0]['replaced_fallback_identities'] ?? null));
$assert(1 === $binding['occurrence'] && $binding['search_block_markup'] === substr($footer['canonical_block_markup'], $binding['position']['offset'], $binding['position']['length']), 'The binding anchors one exact block of the part markup.');
foreach ($plan['pages'] as $row) $assert(!str_contains($row['canonical_block_markup'], 'Keep in touch'), $row['source_path'] . ' no longer renders its own footer.');

// Resolution projects the part like any page: the bound block is found in the
// part's resolved markup, at the position the consumer will replace.
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://example.test/wp-content/themes/captured'));
$resolvedFooter = array_values(array_filter($resolved['template_parts'], static fn (array $part): bool => 'footer' === ($part['area'] ?? null)))[0];
$resolvedBinding = array_values(array_filter($entities($resolved), static fn (array $entity): bool => 'form' === ($entity['bindings'][0]['role'] ?? null)))[0]['bindings'][0];
$assert(str_contains($footer['canonical_block_markup'], '{{wordpress-site-plan:asset:') && !str_contains($resolvedFooter['resolved_block_markup'], '{{wordpress-site-plan:asset:'), 'The part carries an asset token ahead of the form, so resolution moves the form.');
$assert($resolvedBinding['search_block_markup'] === substr($resolvedFooter['resolved_block_markup'], $resolvedBinding['position']['offset'], $resolvedBinding['position']['length']), 'The resolved binding anchors one exact block of the resolved part markup.');

// A page whose footer form differs is not part of the shared chrome: the two
// matching pages share the part and its one form, while that page keeps its
// own footer and its own form entity.
$other = str_replace('Join', 'Subscribe', $signup);
$divergent = $compile(array('index.html' => $page('Home', $signup), 'about.html' => $page('About', $other), 'team.html' => $page('Team', $signup)));
$divergentForms = array_values(array_filter($entities($divergent), static fn (array $entity): bool => 'form' === ($entity['bindings'][0]['role'] ?? null)));
$sources = array_map(static fn (array $entity): string => (string) $entity['bindings'][0]['source_path'], $divergentForms);
sort($sources);
$assert(array('about.html', 'wordpress-site-plan/shared/footer#footer') === $sources, 'The matching pages share one form in the part; the differing page keeps its own: ' . json_encode($sources));
$aboutPage = array_values(array_filter($divergent['pages'], static fn (array $row): bool => 'about.html' === $row['source_path']))[0];
$assert(str_contains($aboutPage['canonical_block_markup'], 'Subscribe'), 'The differing page still renders its own footer form.');

// Forms over the declaration budget are stored as runtime entity manifest
// records. Their bindings are not rewritten by shell extraction, so the shared
// footer holding one stays page-owned, and every binding still resolves on the
// page that renders its form.
$twoPage = static fn (string $title): string => str_replace('<a href="team.html">Team</a>', '', $page($title, $signup));
$manifestInput = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $twoPage('Home'), 'about.html' => $twoPage('About'), 'logo.png' => $logo)))->toArray();
unset($manifestInput['source_reports']['conversion_report'], $manifestInput['source_reports']['wordpress_site_plan']);
$manifestDeclarations = $manifestInput['source_reports']['compiled_site']['runtime_declarations'];
foreach ($manifestDeclarations as &$declaration) {
    if ('forms' !== ($declaration['type'] ?? null)) continue;
    $manifest = RuntimeEntityManifest::fromEntities('generic/forms/v1', $declaration['payload']['entities']);
    $declaration = array('kind' => $declaration['kind'], 'type' => $declaration['type'], 'source_path' => $declaration['source_path'], 'payload' => $manifest['payload']);
}
unset($declaration);
$assert(isset($manifest) && 2 === count($manifest['records']), 'Both pages declare a form entity to store as a manifest record.');
$manifestInput['source_reports']['compiled_site']['runtime_declarations'] = RuntimeDeclarations::normalizeList($manifestDeclarations);
$manifestInput['source_reports']['compiled_site']['runtime_entity_records'] = $manifest['records'];
$manifestPlan = (new WordPressSitePlan())->fromCompilerResult($manifestInput);
$manifestResolved = (new WordPressSitePlanResolver())->resolve($manifestPlan, array('theme_uri' => 'https://example.test/wp-content/themes/captured'));
$assert(array() === array_filter($manifestPlan['template_parts'], static fn (array $part): bool => 'footer' === ($part['area'] ?? null)) && in_array('wordpress_site_plan_shell_retained_runtime_binding', array_column($manifestPlan['diagnostics'], 'code'), true), 'A footer whose form lives in a manifest record stays page-owned: ' . json_encode(array_column($manifestPlan['diagnostics'], 'code')));
foreach ($manifestPlan['pages'] as $row) $assert(1 === substr_count($row['canonical_block_markup'], 'Keep in touch') && str_contains($row['canonical_block_markup'], '<form'), $row['source_path'] . ' keeps its own footer and form.');
$manifestPages = array_column($manifestResolved['pages'], null, 'source_path');
$manifestBindings = array();
foreach ($manifestResolved['runtime_entity_resolution'] as $declaration) foreach ($declaration['entities'] as $entity) foreach ($entity['bindings'] as $binding) $manifestBindings[$binding['source_path']] = $binding;
ksort($manifestBindings);
$assert(array('about.html', 'index.html') === array_keys($manifestBindings), 'Each page keeps the binding for its own form: ' . json_encode(array_keys($manifestBindings)));
foreach ($manifestBindings as $source => $binding) $assert($binding['search_block_markup'] === substr($manifestPages[$source]['resolved_block_markup'], $binding['position']['offset'], $binding['position']['length']), $source . ' resolves its manifest binding to one exact block of its page.');

// A form fallback diagnostic carries its fallback row's producer identity, so a
// consumer can match every page's source finding to the entity that replaced it.
$findings = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $page('Home', $signup), 'about.html' => $page('About', $signup)) + array('logo.png' => $logo)))->toArray();
$fallbackIdentities = array_values(array_filter(array_column(array_filter($findings['fallbacks'] ?? array(), static fn (array $row): bool => 'html_form_fallback' === ($row['diagnostic_code'] ?? null)), 'fallback_identity')));
$diagnosticIdentities = array_values(array_filter(array_column(array_filter($findings['diagnostics'] ?? array(), static fn (array $row): bool => 'html_form_fallback' === ($row['code'] ?? null)), 'fallback_identity')));
sort($fallbackIdentities);
sort($diagnosticIdentities);
$assert(2 === count($fallbackIdentities) && $fallbackIdentities === array_values(array_unique($diagnosticIdentities)), 'Form fallback diagnostics carry their fallback identity: ' . json_encode(array($fallbackIdentities, $diagnosticIdentities)));

echo "Shared shell entity bindings contract passed.\n";
