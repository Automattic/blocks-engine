<?php
declare(strict_types=1);

/**
 * A head element or attribute the head context cannot carry is a page-local
 * limit. Event handlers (`<link onload>`) and invalid, reserved or oversized
 * attributes are stripped; an element over its attribute budget, an oversized
 * data URL or inline data block, and elements past the element budget are
 * dropped. Each is a `document_head_*_dropped` finding instead of an uncaught
 * compile failure, and the strict head validator still rejects them.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentHeadContext;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
// The described meta carries an id, so the page has a head context.
$artifact = static fn (string $head): array => array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><meta charset="utf-8"><title>Home</title><meta name="description" id="description" content="Kept description"><link rel="stylesheet" id="site-css" href="site.css">' . $head . '</head><body><main><h1>Kept heading</h1></main></body></html>',
    'site.css' => 'h1{color:#123456}',
));
$findings = static fn (array $plan): array => array_values(array_filter($plan['diagnostics'], static fn (array $row): bool => str_starts_with((string) ($row['code'] ?? ''), 'document_head_') && str_ends_with((string) $row['code'], '_dropped')));
$compiler = new ArtifactCompiler();
$attributes = '';
for ( $index = 0; $index < 33; ++$index ) $attributes .= ' data-attribute-' . $index . '="x"';

foreach (array(
    'link onload' => array('<link rel="preload" as="style" id="async-css" href="site.css" onload="this.rel=\'stylesheet\'">', 'document_head_attribute_dropped', 'event_handler', 'link', 'onload', 1),
    'non-standard attribute name' => array('<meta name="theme-color" content="#fff" x-on:click="go()">', 'document_head_attribute_dropped', 'invalid_name', 'meta', 'x-on:click', 1),
    'reserved attribute' => array('<meta name="theme-color" content="#fff" src="theme.png">', 'document_head_attribute_dropped', 'reserved_name', 'meta', 'src', 1),
    'oversized attribute value' => array('<meta name="keywords" content="' . str_repeat('k', 8193) . '">', 'document_head_attribute_dropped', 'oversized_value', 'meta', 'content', 1),
    'attribute budget' => array('<meta name="crowded"' . $attributes . '>', 'document_head_element_dropped', 'attribute_budget', 'meta', null, 1),
    'oversized data URL' => array('<link rel="icon" href="data:image/png;base64,' . str_repeat('A', 1048577) . '">', 'document_head_element_dropped', 'oversized_data_url', 'link', null, 1),
    'oversized inline data' => array('<script type="application/ld+json">"' . str_repeat('j', 1048577) . '"</script>', 'document_head_element_dropped', 'oversized_inline_data', 'script', null, 1),
    'element budget' => array(str_repeat('<meta name="repeated" content="x">', DocumentHeadContext::MAX_ELEMENTS), 'document_head_element_dropped', 'element_budget', 'meta', null, 3),
) as $label => list($head, $code, $reason, $tag, $attribute, $count)) {
    $result = $compiler->compile($artifact($head))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? null;
    $assert(is_array($plan) && 'failed' !== $result['status'], "A head {$label} no longer fails the compile.");
    WordPressSitePlan::assertValid($plan);
    $elements = $plan['pages'][0]['document_metadata']['head']['elements'];
    DocumentHeadContext::assertValid($plan['pages'][0]['document_metadata']['head']);
    $assert(str_contains((string) json_encode($elements), 'Kept description') && str_contains($plan['pages'][0]['canonical_block_markup'], 'Kept heading'), "The rest of the head and the page survive a head {$label}.");
    $found = $findings($plan);
    $assert(1 === count($found) && $code === $found[0]['code'] && 'warning' === $found[0]['severity'] && 'item' === $found[0]['unit'] && $reason === $found[0]['reason_code'] && $tag === $found[0]['tag'] && $attribute === ($found[0]['attribute'] ?? null) && $count === $found[0]['omitted_count'] && 'index.html' === $found[0]['source_path'], "The head {$label} is reported once: " . json_encode($found));
    $assert(in_array($code, $plan['reporting']['diagnostic_codes'], true), "The head {$label} finding is linked to plan reporting.");
}

// The handler is gone from the head the theme renders; the element stays.
$onload = $compiler->compile($artifact('<link rel="preload" as="style" id="async-css" href="site.css" onload="this.rel=\'stylesheet\'">'))->toArray()['source_reports']['wordpress_site_plan'];
$rendered = DocumentHeadContext::fromPlan($onload, 'index.html');
$assert(str_contains($rendered, 'id="async-css"') && !str_contains($rendered, 'onload'), 'The rendered head keeps the preload and drops its event handler.');

// The validator is still the backstop for a head that carries such items.
foreach (array(
    'event handler' => array('tag' => 'meta', 'attributes' => array('name' => 'x', 'onclick' => 'go()')),
    'oversized attribute' => array('tag' => 'meta', 'attributes' => array('name' => 'x', 'content' => str_repeat('k', 8193))),
) as $label => $row) {
    $rejected = false;
    try {
        DocumentHeadContext::assertValid(array('schema' => DocumentHeadContext::SCHEMA, 'elements' => array($row)));
    } catch ( InvalidArgumentException ) {
        $rejected = true;
    }
    $assert($rejected, "The strict head validator still rejects an {$label}.");
}

echo "document-head-unrepresentable-items contract passed\n";
