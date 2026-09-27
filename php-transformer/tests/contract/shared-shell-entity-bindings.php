<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
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

echo "Shared shell entity bindings contract passed.\n";
