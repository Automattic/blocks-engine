<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\BindingAnchors;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Repeated header forms promote padding inside the runtime-bound region.
// Deferred font presets also rewrite this region before shell extraction;
// extraction must see bindings anchored on those final bytes.
$files = array();
foreach (range(1, 6) as $index) {
    $files["contact-{$index}.html"] = '<!doctype html><html><head><title>Contact ' . $index . '</title><style>body { font-family: Arial, sans-serif; } h2 { font-family: Arial, sans-serif; }</style></head><body><header>'
        . '<form style="padding:15px;font-family:Georgia,serif"><h2 style="font-family:Arial,sans-serif">Write to our team</h2>'
        . '<div style="padding:15px"><input id="email" type="text" name="email"><label for="email">Email</label></div><div style="padding:15px"><textarea name="message" aria-label="Message"></textarea></div>'
        . '<p>' . str_repeat('Contact our team. ', 2) . '</p><button type="submit">Send</button></form></header><main><p>Page ' . $index . '</p></main></body></html>';
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'contact-1.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($plan), 'The multi-page artifact produces a site plan.');
WordPressSitePlan::assertValid($plan);
$assert(6 === count($plan['pages']), 'All contact pages are emitted.');
$pages = array_column(array_merge($plan['pages'], $plan['template_parts']), 'canonical_block_markup', 'source_path');
$assert(str_contains(implode('', $pages), 'var:preset|spacing|'), 'Repeated padding is actually promoted and rewritten in the emitted markup.');
$bindings = array();
foreach (RuntimeDeclarations::materialize($plan['runtime_declarations'], $plan['runtime_records'] ?? array()) as $declaration) {
    if ('forms' !== ($declaration['type'] ?? null)) continue;
    foreach ($declaration['payload']['entities'] as $entity) {
        foreach ($entity['bindings'] as $binding) $bindings[] = $binding;
    }
}
$assert(1 === count($bindings), 'The shared header form binding survives preset projection and shell extraction.');
foreach ($bindings as $binding) {
    $search = $binding['search_block_markup'];
    $assert(str_contains($search, 'var:preset|spacing|'), 'The runtime-bound form itself contains the promoted padding.');
    $assert(str_contains($search, 'has-georgia-font-family'), 'The deferred font preset rewrite actually happened inside the binding.');
    $assert(BindingAnchors::positionMatches($binding['position'] ?? null, new BlockMarkupIndex($pages[$binding['source_path']]), $search), 'The binding still anchors on its exact form block.');
}
foreach ($plan['diagnostics'] as $diagnostic) {
    $assert('runtime_entity_binding_unanchored' !== ($diagnostic['code'] ?? null), 'Preset binding must not report an unanchored runtime entity.');
}

echo "Site-plan preset runtime binding passed.\n";
