<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
// Neutral archive and paginated capture: both declare the same surface and form.
$html = '<main><h1>Updates</h1><img src="mark.png" alt="Mark"><form method="post" action="/updates/"><label for="email">Email</label><input id="email" type="email" name="email"><button>Join</button></form></main>';
$variantHtml = str_replace(array('action="/updates/"', '<label for="email"'), array('action="/updates/page/2/"', '<input type="hidden" name="decoy" value=""><label for="email"'), $html);
$surface = array('schema' => 'blocks-engine/template-surface/v1', 'role' => 'archive', 'slug' => 'archive-updates', 'logical_surface_id' => 'archive:archive-updates', 'responsive_variant_id' => 'default');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => '<main>Home</main>'),
    array('path' => 'updates/index.html', 'content' => $html, 'metadata' => array('template_surface' => $surface)),
    array('path' => 'updates/page/2/index.html', 'content' => $html, 'metadata' => array('template_surface' => $surface)),
    array('path' => 'mark.png', 'kind' => 'image', 'mime_type' => 'image/png', 'content_base64' => base64_encode("\x89PNG\r\n\x1a\n")),
)))->toArray();
// Surface selection requires identical projected blocks. Retain that projected
// surface, but supply the variant's real, pre-projection entity declaration.
$variant = (new ArtifactCompiler())->compile(array('entrypoint' => 'updates/page/2/index.html', 'files' => array(
    array('path' => 'updates/page/2/index.html', 'content' => $variantHtml),
)))->toArray();
$variantDeclarations = RuntimeDeclarations::materialize($variant['source_reports']['compiled_site']['runtime_declarations'], $variant['source_reports']['compiled_site']['runtime_records'] ?? array());
$declarations = RuntimeDeclarations::materialize($result['source_reports']['compiled_site']['runtime_declarations'], $result['source_reports']['compiled_site']['runtime_records'] ?? array());
foreach ($variantDeclarations as $declaration) if ('forms' === ($declaration['type'] ?? null)) $variantForm = $declaration['payload']['entities'][0];
foreach ($declarations as &$declaration) {
    if ('forms' !== ($declaration['type'] ?? null)) continue;
    foreach ($declaration['payload']['entities'] as &$entity) if ('updates/page/2/index.html' === $entity['source_path']) {
        $assert($entity['controls'][0]['selector'] !== $variantForm['controls'][0]['selector'], 'The hidden sibling shifts the visible input selector.');
        // Keep the source fallback identity from the complete capture.
        $variantForm['fallback_identity'] = $entity['fallback_identity'];
        $entity = $variantForm;
    }
    unset($entity, $declaration['payload_hash'], $declaration['content_hash']);
}
unset($declaration);
$result['source_reports']['compiled_site']['runtime_declarations'] = RuntimeDeclarations::normalizeList($declarations);
unset($result['source_reports']['wordpress_site_plan'], $result['source_reports']['conversion_report']);
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$assert(array() !== $plan, 'A site plan is emitted: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array()));
$findings = array_values(array_filter($plan['diagnostics'] ?? array(), static fn(array $row): bool => 'runtime_entity_binding_unanchored' === ($row['code'] ?? null)));
$assert(array() === $findings, 'Template and variant bindings must not be dropped: ' . json_encode($findings));
$formsOf = static function (array $plan): array {
    $forms = array();
    foreach (RuntimeDeclarations::materialize($plan['runtime_declarations'], $plan['runtime_records'] ?? array()) as $declaration) {
        if ('forms' === ($declaration['type'] ?? null)) foreach ($declaration['payload']['entities'] as $entity) if (!empty($entity['bindings'])) $forms[] = $entity;
    }
    return $forms;
};
$forms = $formsOf($plan);
$assert(1 === count($forms), 'Only the canonical surface has an active form binding.');
$template = array_column($plan['templates'], null, 'slug')['archive-updates'];
$binding = $forms[0]['bindings'][0];
$assert('updates/index.html' === $binding['source_path'] && 'templates/archive-updates.html' === ($binding['target_path'] ?? null), 'The selected source binding names the template target.');
$assert(WordPressSitePlan::bindingPosition($binding['position'], $template['canonical_block_markup'], $binding['search_block_markup']), 'The form anchors on canonical template markup.');
$templateWrite = array_column($plan['writes'], null, 'target_path')[$binding['target_path']];
$assert(WordPressSitePlan::bindingPosition($binding['position'], $templateWrite['payload']['data'], $binding['search_block_markup']), 'The consumer can patch the exact fragment in the emitted template write.');
$identities = array_values(array_filter(array_column(array_filter($result['fallbacks'], static fn(array $row): bool => 'html_form_fallback' === ($row['diagnostic_code'] ?? null)), 'fallback_identity')));
sort($identities);
$assert(2 === count($identities) && $identities === ($forms[0]['replaced_fallback_identities'] ?? null), 'The canonical entity accounts for both source fallback identities.');
WordPressSitePlan::assertValid($plan);
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://example.test/theme'));
$resolvedTemplate = array_column($resolved['templates'], null, 'slug')['archive-updates'];
$resolvedBinding = $formsOf($resolved)[0]['bindings'][0];
$assert(WordPressSitePlan::bindingPosition($resolvedBinding['position'], $resolvedTemplate['resolved_block_markup'], $resolvedBinding['search_block_markup']), 'The binding rebases onto resolved template markup.');
// Different visible controls, non-self actions and form selectors fail closed.
foreach (array('controls', 'action', 'selector', 'source_path') as $field) {
    $input = $result;
    unset($input['source_reports']['wordpress_site_plan'], $input['source_reports']['conversion_report']);
    $declarations = RuntimeDeclarations::materialize($input['source_reports']['compiled_site']['runtime_declarations'], $input['source_reports']['compiled_site']['runtime_records'] ?? array());
    foreach ($declarations as &$declaration) {
        if ('forms' !== ($declaration['type'] ?? null)) continue;
        foreach ($declaration['payload']['entities'] as &$entity) foreach ($entity['bindings'] as &$candidate) {
            if ('updates/page/2/index.html' !== $candidate['source_path']) continue;
            if ('controls' === $field) $entity['controls'][] = array('tag' => 'input', 'type' => 'text', 'name' => 'extra');
            elseif ('action' === $field) $entity['form']['action'] = '/somewhere-else/';
            else $candidate[$field] = 'selector' === $field ? 'form.different' : 'unemitted/index.html';
        }
        unset($entity, $candidate, $declaration['payload_hash'], $declaration['content_hash']);
    }
    unset($declaration);
    $input['source_reports']['compiled_site']['runtime_declarations'] = RuntimeDeclarations::normalizeList($declarations);
    $different = (new WordPressSitePlan())->fromCompilerResult($input);
    $unanchored = array_values(array_filter($different['diagnostics'], static fn(array $row): bool => 'runtime_entity_binding_unanchored' === ($row['code'] ?? null)));
    $expectedSource = 'source_path' === $field ? 'unemitted/index.html' : 'updates/page/2/index.html';
    $assert(1 === count($unanchored) && $expectedSource === $unanchored[0]['source_path'] && 'document_not_emitted' === $unanchored[0]['reason_code'], 'A variant with different ' . $field . ' stays unanchored.');
    $assert(!isset($formsOf($different)[0]['replaced_fallback_identities']), 'An unanchored form must not be counted as an absorbed fallback.');
}
echo "Template surface entity binding contracts passed.\n";
