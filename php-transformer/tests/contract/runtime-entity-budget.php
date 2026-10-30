<?php
declare(strict_types=1);

ini_set('memory_limit', '1G');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

/**
 * Every generated runtime entity collection fits the canonical declaration
 * limits by the same rule: inline, else a manifest of content-addressed
 * records. One entity no declaration can carry is dropped with its bindings
 * (`runtime_entity_dropped`), and a collection whose manifest still exceeds
 * the per-declaration limit is dropped (`runtime_declaration_dropped`).
 * At intake, limits over the whole declaration set stay fail-closed. Site-plan
 * composition drops what still exceeds a limit (`runtime_declaration_dropped`)
 * unless integrity depends on it.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$compile = static fn (array $files, array $extra = array()): array => (new ArtifactCompiler())->compile(array_merge(array('entrypoint' => 'index.html', 'files' => $files), $extra))->toArray();
$card = static fn (string $name, string $price, string $src): string => '<div class="card"><img src="' . $src . '" alt="' . $name . '"><h3>' . $name . '</h3><span class="price">$' . $price . '</span></div>';
$findings = static fn (array $diagnostics, string $code): array => array_values(array_filter($diagnostics, static fn (mixed $row): bool => is_array($row) && $code === ($row['code'] ?? null)));
$declaration = static function (array $plan, string $type): ?array {
    foreach ($plan['runtime_declarations'] ?? array() as $row) if ('entity_collection' === ($row['kind'] ?? null) && $type === ($row['type'] ?? null)) return $row;
    return null;
};
$entities = static function (array $plan, array $declaration): array {
    if (RuntimeEntityManifest::SCHEMA !== ($declaration['payload']['schema'] ?? null)) return $declaration['payload']['entities'] ?? array();
    return RuntimeEntityManifest::resolve($declaration['payload'], $plan['runtime_entity_records']);
};

// 1. A product collection over one inline payload: each product fits a
// record, but the grid's products together exceed one declaration. Products
// take the same record-manifest fallback forms already had.
$grid = '';
for ($item = 0; $item < 4; ++$item) $grid .= $card('Item ' . $item, (10 + $item) . '.00', 'data:image/png;base64,' . str_repeat(chr(65 + $item), 350000));
$files = array('index.html' => '<main><h1>Shop</h1><div class="grid">' . $grid . '</div></main>');
$catalog = $compile($files);
$catalogPlan = $catalog['source_reports']['wordpress_site_plan'] ?? null;
$assert('failed' !== $catalog['status'] && is_array($catalogPlan), 'A product collection over one inline payload no longer fails the site: ' . json_encode(array_column($findings($catalog['diagnostics'], 'wordpress_site_plan_not_self_contained'), 'message')));
$products = $declaration($catalogPlan, 'products');
$assert(is_array($products) && RuntimeEntityManifest::SCHEMA === $products['payload']['schema'], 'An oversized product collection is declared as a manifest of records.');
$assert(4 === count($entities($catalogPlan, $products)), 'Every product survives the manifest fallback.');
$assert(array() === $findings($catalog['diagnostics'], 'runtime_entity_dropped'), 'Products that each fit a record are not dropped.');
WordPressSitePlan::assertValid($catalogPlan);

// 2. One product whose site-plan record exceeds the payload limit is dropped
// with its bindings. Its block stays static and the other products keep theirs.
// The record carries the image in its fields and its binding's block markup,
// so an image over half the limit is over it, while the page stays a file the
// compiler accepts.
$grid = $card('Huge Item', '10.00', 'data:image/png;base64,' . str_repeat('A', intdiv(RuntimeDeclarations::MAX_PAYLOAD_BYTES, 2) + 100000)) . $card('Small Item', '11.00', 'https://cdn.example.test/s.jpg') . $card('Tiny Item', '12.00', 'https://cdn.example.test/t.jpg') . $card('Wee Item', '13.00', 'https://cdn.example.test/w.jpg');
$oversized = $compile(array('index.html' => '<main><h1>Shop</h1><div class="grid">' . $grid . '</div></main>'));
$oversizedPlan = $oversized['source_reports']['wordpress_site_plan'] ?? null;
$assert('success_with_warnings' === $oversized['status'] && is_array($oversizedPlan), 'One oversized product entity no longer fails the site: ' . json_encode(array_column($findings($oversized['diagnostics'], 'wordpress_site_plan_not_self_contained'), 'message')));
$allDropped = $findings($oversized['diagnostics'], 'runtime_entity_dropped');
// The grid that shows the oversized product carries that product's card in
// its item template and anchor, so the grid entity is over the limit too.
$droppedGrids = array_values(array_filter($allDropped, static fn (array $row): bool => 'generic/product-grids/v1' === ($row['entity_schema'] ?? null)));
$assert(1 === count($droppedGrids) && 'runtime_entity_exceeds_byte_limit' === $droppedGrids[0]['reason_code'], 'The grid around the oversized product is reported as dropped: ' . json_encode($droppedGrids));
$dropped = array_values(array_filter($allDropped, static fn (array $row): bool => 'generic/product-grids/v1' !== ($row['entity_schema'] ?? null)));
$assert(1 === count($dropped), 'Exactly the oversized product is reported as dropped.');
$assert('warning' === $dropped[0]['severity'] && 'item' === $dropped[0]['unit'] && 'index.html' === $dropped[0]['source_path'] && 'generic/products/v1' === $dropped[0]['entity_schema'] && 'runtime_entity_exceeds_byte_limit' === $dropped[0]['reason_code'], 'The dropped entity finding names its unit, source, schema and reason: ' . json_encode($dropped[0]));
$assert(is_int($dropped[0]['metrics']['bytes'] ?? null) && $dropped[0]['metrics']['bytes'] > RuntimeDeclarations::MAX_PAYLOAD_BYTES && RuntimeDeclarations::MAX_PAYLOAD_BYTES === $dropped[0]['metrics']['limit_bytes'], 'The finding measures the entity against the payload limit.');
$kept = array_column($entities($oversizedPlan, $declaration($oversizedPlan, 'products') ?? array()), 'name');
sort($kept);
$assert(array('Small Item', 'Tiny Item', 'Wee Item') === $kept, 'The other products keep their entities: ' . json_encode($kept));
$page = array_values(array_filter($oversizedPlan['pages'], static fn (array $row): bool => 'index.html' === $row['source_path']))[0];
$assert(str_contains($page['canonical_block_markup'], 'Huge Item'), 'The dropped product still renders as static block content.');
WordPressSitePlan::assertValid($oversizedPlan);

// 3. A form whose choice-group record carries nested values deeper than a site
// plan can hash. The builder keeps the group to its flat contract, so neither
// the fallback evidence nor the form entity carries the nesting.
$nested = array('leaf' => 'value');
for ($level = 0; $level < 40; ++$level) $nested = array('nested' => $nested);
$config = json_encode(array('group' => array('selector' => '#choices', 'label' => 'Pick one', 'nested' => $nested), 'choices' => array(array('index' => 0, 'label' => 'A', 'source_value' => array('nested' => $nested)), array('index' => 1, 'label' => 'B')), 'states' => array()));
$formHtml = '<main><h1>Contact</h1><form id="contact" action="https://example.test/submit" method="post"><label for="email">Email</label><input id="email" name="email" type="email">'
    . '<div id="choices" data-blocks-engine-choice-group="true" data-blocks-engine-choice-config="' . htmlspecialchars((string) $config, ENT_QUOTES) . '"><button type="button">A</button><button type="button">B</button></div>'
    . '<button type="submit">Send</button></form></main>';
$deep = $compile(array('index.html' => $formHtml));
$deepPlan = $deep['source_reports']['wordpress_site_plan'] ?? null;
$assert('failed' !== $deep['status'] && is_array($deepPlan), 'A deeply nested choice-group record no longer fails the site: ' . json_encode(array_column($findings($deep['diagnostics'], 'wordpress_site_plan_not_self_contained'), 'message')));
$form = $entities($deepPlan, $declaration($deepPlan, 'forms') ?? array())[0] ?? array();
$assert(array('label' => 'Pick one', 'selector' => '#choices') === ($form['choice_groups'][0]['group'] ?? null), 'The form entity keeps the flat choice-group facts: ' . json_encode($form['choice_groups'][0]['group'] ?? null));
$assert(array_key_exists('source_value', $form['choice_groups'][0]['choices'][0] ?? array()) && null === $form['choice_groups'][0]['choices'][0]['source_value'], 'A non-scalar choice value is unknown, not nested data.');
WordPressSitePlan::assertValid($deepPlan);

// 4. Events are declared by the plan, one per detail page. Each Event field is
// bounded, but a site's Events together can exceed one inline payload.
$eventPages = 90;
$files = array('index.html' => '<main><h1>Events</h1><ul>' . implode('', array_map(static fn (int $event): string => '<li><a href="event-' . $event . '.html">Event ' . $event . '</a></li>', range(0, $eventPages - 1))) . '</ul></main>');
for ($event = 0; $event < $eventPages; ++$event) {
    // Control characters escape to six bytes each in canonical JSON.
    $ld = array('@context' => 'https://schema.org', '@type' => 'Event', 'name' => 'Event ' . $event . str_repeat("\x01", 500), 'startDate' => '2026-05-01T19:00:00Z', 'endDate' => '2026-05-01T22:00:00Z', 'description' => 'Event ' . $event . str_repeat("\x01", 8000), 'location' => array('name' => 'Hall ' . $event . str_repeat("\x01", 500), 'address' => array('streetAddress' => 'Street' . str_repeat("\x01", 500), 'addressLocality' => 'City' . str_repeat("\x01", 500))));
    $files['event-' . $event . '.html'] = '<html><head><title>Event ' . $event . '</title><script type="application/ld+json">' . json_encode($ld) . '</script></head><body><main><h1>Event ' . $event . '</h1><p>Details.</p></main></body></html>';
}
$calendar = $compile($files);
$calendarPlan = $calendar['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($calendarPlan), 'An Event collection over one inline payload no longer fails the plan: ' . json_encode(array_column($findings($calendar['diagnostics'], 'wordpress_site_plan_not_self_contained'), 'message')));
$events = $declaration($calendarPlan, 'events');
$assert(is_array($events) && RuntimeEntityManifest::SCHEMA === $events['payload']['schema'] && $eventPages === count($entities($calendarPlan, $events)), 'Every Event survives as a record in the manifest fallback.');
WordPressSitePlan::assertValid($calendarPlan);

// 5. The collection rule directly. An entity nested deeper than a site plan
// can hash is dropped; its siblings are declared.
$deepEntity = array('id' => 'deep', 'value' => $nested);
$collection = RuntimeEntityManifest::declareCollection(array(), 'widgets', 'example/widgets/v1', array($deepEntity, array('id' => 'flat')), 'index.html', 'widget');
$assert(array(array('id' => 'flat')) === ($collection['payload']['entities'] ?? null), 'A too-deep entity is dropped and its sibling declared inline.');
$assert(1 === count($collection['diagnostics']) && 'runtime_entity_dropped' === $collection['diagnostics'][0]['code'] && 'runtime_entity_unsupported_value' === $collection['diagnostics'][0]['reason_code'] && 'item' === $collection['diagnostics'][0]['unit'], 'The too-deep entity is named: ' . json_encode($collection['diagnostics']));

// A collection whose record manifest is itself over the per-declaration
// payload limit is dropped as one declaration, without its dependency.
$many = array();
for ($index = 0; $index < 65000; ++$index) $many[] = array('id' => sprintf('widget-%06d', $index), 'label' => str_repeat('w', 64));
$unbounded = RuntimeEntityManifest::declareCollection(array(), 'widgets', 'example/widgets/v1', $many, 'index.html', 'widget');
$assert(null === $unbounded['payload'] && array() === $unbounded['records'], 'A collection over its manifest limit is not declared.');
$assert(1 === count($unbounded['diagnostics']) && 'runtime_declaration_dropped' === $unbounded['diagnostics'][0]['code'] && 'declaration' === $unbounded['diagnostics'][0]['unit'] && 'entity_collection:widgets' === $unbounded['diagnostics'][0]['declaration_key'] && 65000 === $unbounded['diagnostics'][0]['metrics']['entity_count'], 'The dropped declaration is named: ' . json_encode($unbounded['diagnostics']));

// The aggregate bound over every declaration stays fail-closed.
$probe = RuntimeDeclarations::normalizeList(array(array('kind' => 'entity_collection', 'type' => 'notes', 'source_path' => 'index.html', 'payload' => array('schema' => 'example/notes/v1', 'entities' => array(array('text' => ''))))))[0];
unset($probe['payload_hash'], $probe['content_hash']);
$filler = RuntimeDeclarations::MAX_TOTAL_DECLARATION_BYTES - strlen(RuntimeDeclarations::canonicalJson($probe)) - 16;
$full = array(array('kind' => 'entity_collection', 'type' => 'notes', 'source_path' => 'index.html', 'payload' => array('schema' => 'example/notes/v1', 'entities' => array(array('text' => str_repeat('n', $filler))))));
RuntimeDeclarations::normalizeList($full);
try {
    RuntimeEntityManifest::declareCollection($full, 'widgets', 'example/widgets/v1', array(array('id' => 'one')), 'index.html', null);
    $assert(false, 'A collection over the aggregate declaration bound fails closed.');
} catch (InvalidArgumentException $error) {
    $assert(str_contains($error->getMessage(), 'aggregate canonical byte limit'), 'The aggregate declaration bound fails closed: ' . $error->getMessage());
}

// 6. Site-plan composition degrades declarations still over a byte limit
// after entity lists are sharded into records. Each dropped declaration is a
// `runtime_declaration_dropped` finding and its blocks stay static. A
// declaration that integrity depends on is never dropped and fails closed.
$siteFixture = $compile(array('index.html' => '<main><h1>Catalog home</h1><p>Static catalog copy.</p></main>', 'gathering.html' => '<main><h1>Community gathering</h1></main>'));
$siteInput = $siteFixture;
unset($siteInput['source_reports']['conversion_report'], $siteInput['source_reports']['wordpress_site_plan']);
$gathering = array_column($siteFixture['source_reports']['wordpress_site_plan']['pages'], null, 'source_path')['gathering.html'];
$staticMarkup = array_column($siteFixture['source_reports']['wordpress_site_plan']['pages'], 'canonical_block_markup', 'source_path');
$eventRow = array('id' => 'event-1', 'source_path' => 'gathering.html', 'source_route' => $gathering['route']['path'], 'whole_page_candidate' => 'blocks-engine/whole-page-candidate/v1');
$events = array('kind' => 'entity_collection', 'type' => 'events', 'source_path' => 'data/events.json', 'payload' => array('schema' => 'generic/events/v1', 'entities' => array($eventRow)));
$blob = static fn (string $type, int $bytes): array => array('kind' => 'configuration', 'type' => $type, 'source_path' => 'data/' . $type . '.json', 'payload' => array('schema' => 'example/configuration/v1', 'blob' => str_repeat('c', $bytes)));
$composePlan = static function (array $declarations) use ($siteInput): array {
    $input = $siteInput;
    $input['source_reports']['compiled_site']['runtime_declarations'] = RuntimeDeclarations::normalizeForComposition($declarations);
    return (new WordPressSitePlan())->fromCompilerResult($input);
};
$keys = static fn (array $plan): array => array_map(static fn (array $row): string => $row['kind'] . ':' . ($row['type'] ?? $row['capability']), $plan['runtime_declarations']);
$assertStatic = static function (array $plan) use ($assert, $staticMarkup): void {
    foreach ($plan['pages'] as $page) $assert(($staticMarkup[$page['source_path']] ?? null) === $page['canonical_block_markup'], 'A dropped declaration leaves its page static: ' . $page['source_path']);
};

// (a) One non-entity declaration over the per-declaration payload limit is
// dropped with the dependency that exists only for it. The event collection
// keeps its whole-page candidate.
$oversizedBytes = RuntimeDeclarations::MAX_PAYLOAD_BYTES + 4096;
$catalogDependency = array('kind' => 'dependency', 'capability' => 'catalog', 'source_path' => 'runtime/catalog.json', 'required_for' => array('configuration:catalog'));
$oversizedPlan = $composePlan(array($events, $blob('catalog', $oversizedBytes), $catalogDependency));
WordPressSitePlan::assertValid($oversizedPlan);
$assert(array('entity_collection:events') === $keys($oversizedPlan), 'The oversized declaration and its dependency leave the plan: ' . json_encode($keys($oversizedPlan)));
$dropped = $findings($oversizedPlan['diagnostics'], 'runtime_declaration_dropped');
$assert(2 === count($dropped), 'Each dropped declaration is one finding: ' . json_encode($dropped));
$byKey = array_column($dropped, null, 'declaration_key');
$catalogFinding = $byKey['configuration:catalog'] ?? array();
$assert('warning' === ($catalogFinding['severity'] ?? null) && 'declaration' === $catalogFinding['unit'] && 'data/catalog.json' === $catalogFinding['source_path'] && 'runtime_declaration_exceeds_byte_limit' === $catalogFinding['reason_code'] && $catalogFinding['metrics']['bytes'] > RuntimeDeclarations::MAX_PAYLOAD_BYTES && RuntimeDeclarations::MAX_PAYLOAD_BYTES === $catalogFinding['metrics']['limit_bytes'], 'The oversized declaration finding names its key, source, bytes and limit: ' . json_encode($catalogFinding));
$assert('runtime_declaration_requirement_dropped' === ($byKey['dependency:catalog']['reason_code'] ?? null) && 'runtime/catalog.json' === $byKey['dependency:catalog']['source_path'], 'The dependency required only for it is dropped with it: ' . json_encode($byKey['dependency:catalog'] ?? null));
$assert('success_with_warnings' === $oversizedPlan['quality']['status'] && in_array('runtime_declaration_dropped', $oversizedPlan['reporting']['diagnostic_codes'] ?? array(), true), 'The drops are recovery findings on the plan.');
$assert('event-1' === (array_column($oversizedPlan['pages'], null, 'source_path')['gathering.html']['whole_page_candidates'][0]['entity_id'] ?? null), 'The kept collection keeps its whole-page candidate.');
$assertStatic($oversizedPlan);
// A collection that requires the oversized declaration is dropped with it, so
// its whole-page candidate leaves the page and the page stays static.
$requiredCatalog = array_merge($blob('catalog', $oversizedBytes), array('required_for' => array('entity_collection:events')));
$cascadePlan = $composePlan(array($events, $requiredCatalog));
WordPressSitePlan::assertValid($cascadePlan);
$cascadeDropped = array_column($findings($cascadePlan['diagnostics'], 'runtime_declaration_dropped'), 'reason_code', 'declaration_key');
$assert(array() === $cascadePlan['runtime_declarations'] && array('configuration:catalog' => 'runtime_declaration_exceeds_byte_limit', 'entity_collection:events' => 'runtime_declaration_requirement_dropped') == $cascadeDropped, 'The collection that requires the oversized declaration is dropped with it: ' . json_encode($cascadeDropped));
$assert(!isset(array_column($cascadePlan['pages'], null, 'source_path')['gathering.html']['whole_page_candidates']), 'The dropped collection leaves no whole-page candidate behind.');
$assertStatic($cascadePlan);

// (b) Declarations that each fit but together exceed the aggregate limit:
// the largest is dropped until the set fits, one finding each.
$third = intdiv(RuntimeDeclarations::MAX_TOTAL_DECLARATION_BYTES, 3);
$aggregatePlan = $composePlan(array($events, $blob('alpha', $third), $blob('beta', $third + 2048), $blob('gamma', $third + 4096), $blob('delta', $third + 1024)));
WordPressSitePlan::assertValid($aggregatePlan);
$kept = $keys($aggregatePlan); sort($kept);
$assert(array('configuration:alpha', 'configuration:delta', 'entity_collection:events') === $kept, 'The largest declarations are dropped until the set fits: ' . json_encode($kept));
$aggregateDropped = $findings($aggregatePlan['diagnostics'], 'runtime_declaration_dropped');
$assert(array('configuration:gamma', 'configuration:beta') === array_column($aggregateDropped, 'declaration_key') || array('configuration:beta', 'configuration:gamma') === array_column($aggregateDropped, 'declaration_key'), 'One finding per declaration dropped for the aggregate limit: ' . json_encode($aggregateDropped));
foreach ($aggregateDropped as $finding) $assert('runtime_declaration_exceeds_aggregate_byte_limit' === $finding['reason_code'] && RuntimeDeclarations::MAX_TOTAL_DECLARATION_BYTES === $finding['metrics']['limit_bytes'] && $finding['metrics']['bytes'] > $third, 'The aggregate finding measures the dropped declaration: ' . json_encode($finding));
$assertStatic($aggregatePlan);

// (c) A dependency that a form's script supersession proof requires is
// integrity-bearing: dropping it would drop that form, so its oversized
// payload keeps failing closed with the existing error.
$supersedingForms = array('kind' => 'entity_collection', 'type' => 'forms', 'source_path' => 'index.html', 'payload' => array('schema' => 'generic/forms/v1', 'entities' => array(array('id' => 'contact', 'superseded_scripts' => array(array('source_path' => 'index.html', 'selector' => 'script:nth-of-type(1)'))))));
$formDependency = array_merge($blob('unused', 0), array('kind' => 'dependency', 'capability' => 'form', 'source_path' => 'runtime/form.json', 'required_for' => array('entity_collection:forms'), 'payload' => array('schema' => 'example/form-runtime/v1', 'blob' => str_repeat('f', $oversizedBytes))));
unset($formDependency['type']);
try {
    RuntimeDeclarations::factor(RuntimeDeclarations::normalizeForComposition(array($supersedingForms, $formDependency)));
    $assert(false, 'An integrity-bearing declaration over the payload limit fails closed.');
} catch (InvalidArgumentException $error) {
    $assert(str_contains($error->getMessage(), 'payload exceeds the byte limit'), 'The integrity-bearing declaration fails closed with the existing error: ' . $error->getMessage());
}

// (d) An entity list that factoring moves out of its declaration keeps
// bindings the resolved plan can anchor. Resolution rewrites asset tokens, so
// it re-projects anchors onto the resolved documents only for the entity forms
// it expands: inline lists and entity manifests. The listing's block carries
// an asset token, and another precedes it on the page.
$png = base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\0", 32));
$copy = rtrim(str_repeat('Listing copy. ', 150));
$listingBlock = '<!-- wp:group {"className":"listing"} --><div class="wp-block-group listing"><!-- wp:image {"className":"blocks-engine-synthetic-image-figure"} --><figure class="wp-block-image blocks-engine-synthetic-image-figure"><img src="item.png" alt="Item"/></figure><!-- /wp:image --><!-- wp:paragraph --><p>' . $copy . '</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
$listingSite = $compile(
    array(
        'index.html' => '<main><img src="logo.png" alt="Logo"><h1>Shop</h1><div class="listing"><img src="item.png" alt="Item"><p>' . $copy . '</p></div></main>',
        'logo.png' => array('path' => 'logo.png', 'content_base64' => $png, 'mime_type' => 'image/png'),
        'item.png' => array('path' => 'item.png', 'content_base64' => $png, 'mime_type' => 'image/png'),
    ),
    array('runtime_declarations' => array(array('kind' => 'entity_collection', 'type' => 'products', 'source_path' => 'data/products.json', 'payload' => array('schema' => 'generic/products/v1', 'entities' => array(array('id' => 'listing', 'bindings' => array(array('schema' => 'generic/block-binding/v1', 'role' => 'listing', 'source_path' => 'index.html', 'search_block_markup' => $listingBlock, 'occurrence' => 1))))))))
);
$listingInput = $listingSite;
unset($listingInput['source_reports']['conversion_report'], $listingInput['source_reports']['wordpress_site_plan']);
// A configuration that fits alone, beside which the inline list exceeds the
// aggregate limit but its entity manifest does not.
$catalogProbe = RuntimeDeclarations::normalizeForComposition(array($blob('catalog', 0)))[0];
unset($catalogProbe['reconciliation_identity'], $catalogProbe['payload_hash'], $catalogProbe['content_hash']);
$listingDeclarations = $listingInput['source_reports']['compiled_site']['runtime_declarations'];
$listingDeclarations[] = $blob('catalog', RuntimeDeclarations::MAX_TOTAL_DECLARATION_BYTES - strlen(RuntimeDeclarations::canonicalJson($catalogProbe)) - 1024);
$listingInput['source_reports']['compiled_site']['runtime_declarations'] = RuntimeDeclarations::normalizeForComposition($listingDeclarations);
$listingPlan = (new WordPressSitePlan())->fromCompilerResult($listingInput);
$listing = $declaration($listingPlan, 'products');
try {
    $resolvedListing = (new WordPressSitePlanResolver())->resolve($listingPlan, array('theme_uri' => 'https://example.test/wp-content/themes/imported-theme'));
} catch (InvalidArgumentException $error) {
    $assert(false, 'A plan whose entity list was factored resolves with its bindings anchored: ' . $error->getMessage());
}
$assert(array() === $findings($listingPlan['diagnostics'], 'runtime_declaration_dropped') && is_array($listing) && RuntimeEntityManifest::SCHEMA === $listing['payload']['schema'] && array() === ($listingPlan['runtime_records'] ?? array()), 'An entity list over the aggregate limit is factored into an entity manifest, not dropped: ' . json_encode($listing['payload']['schema'] ?? null));
$listingBinding = $entities($listingPlan, $listing)[0]['bindings'][0] ?? array();
$assert(str_contains($listingBinding['search_block_markup'] ?? '', WordPressSitePlan::TOKEN_PREFIX), 'The factored binding anchors a token-bearing canonical block.');
$resolution = array_column($resolvedListing['runtime_entity_resolution'], null, 'reconciliation_identity')[$listing['reconciliation_identity']] ?? array();
$resolvedBinding = $resolution['entities'][0]['bindings'][0] ?? array();
$resolvedPage = $resolvedListing['pages'][0]['resolved_block_markup'];
$assert(is_string($resolvedBinding['search_block_markup'] ?? null) && !str_contains($resolvedBinding['search_block_markup'], WordPressSitePlan::TOKEN_PREFIX) && WordPressSitePlan::bindingPosition($resolvedBinding['position'] ?? null, $resolvedPage, $resolvedBinding['search_block_markup']), 'Resolution anchors the factored binding on the resolved page: ' . json_encode($resolvedBinding['position'] ?? null));

echo "runtime entity budget contract passed\n";
