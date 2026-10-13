<?php
declare(strict_types=1);

ini_set('memory_limit', '1G');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

/**
 * Every generated runtime entity collection fits the canonical declaration
 * limits by the same rule: inline, else a manifest of content-addressed
 * records. One entity no declaration can carry is dropped with its bindings
 * (`runtime_entity_dropped`), and a collection whose manifest still exceeds
 * the per-declaration limit is dropped (`runtime_declaration_dropped`).
 * Limits over the whole declaration set stay fail-closed.
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
$grid = $card('Huge Item', '10.00', 'data:image/png;base64,' . str_repeat('A', 1800000)) . $card('Small Item', '11.00', 'https://cdn.example.test/s.jpg') . $card('Tiny Item', '12.00', 'https://cdn.example.test/t.jpg') . $card('Wee Item', '13.00', 'https://cdn.example.test/w.jpg');
$oversized = $compile(array('index.html' => '<main><h1>Shop</h1><div class="grid">' . $grid . '</div></main>'));
$oversizedPlan = $oversized['source_reports']['wordpress_site_plan'] ?? null;
$assert('success_with_warnings' === $oversized['status'] && is_array($oversizedPlan), 'One oversized product entity no longer fails the site: ' . json_encode(array_column($findings($oversized['diagnostics'], 'wordpress_site_plan_not_self_contained'), 'message')));
$dropped = $findings($oversized['diagnostics'], 'runtime_entity_dropped');
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

echo "runtime entity budget contract passed\n";
