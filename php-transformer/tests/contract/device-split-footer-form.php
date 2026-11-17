<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/device-split-footer-form.html');
$failures = array();
foreach (array('control' => preg_replace('/<form\b.*?<\/form>/s', '', $html), 'form' => $html) as $variant => $source) {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => $source,
        'about/index.html' => str_replace('<title>Home</title>', '<title>About</title>', $source),
    )))->toArray()['source_reports']['wordpress_site_plan'];
    $footers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === ($part['slug'] ?? null)));
    $references = array();
    foreach ($plan['templates'] as $template) {
        if (str_contains($template['canonical_block_markup'], '"slug":"footer"')) $references[] = $template['slug'];
    }
    echo $variant . ': footer parts=' . count($footers) . ', templates referencing footer=' . implode(',', $references) . "\n";
    if (1 !== count($footers) || count($plan['templates']) !== count($references)) $failures[] = $variant . ' must extract one footer referenced by every template.';
    if (1 !== count($footers)) continue;
    foreach ($plan['pages'] as $page) {
        if (str_contains($page['canonical_block_markup'], 'Example Co.') || str_contains($page['canonical_block_markup'], '<form')) $failures[] = $variant . ' leaves footer content in a page.';
    }
    if ('form' !== $variant) continue;
    $markup = $footers[0]['canonical_block_markup'];
    if (2 !== substr_count($markup, '<form action="/subscribe" method="post">') || !str_contains($markup, 'for="ed"') || !str_contains($markup, 'for="em"')) $failures[] = 'Both device forms retain their action and control IDs.';
    $bindings = 0;
    foreach ($plan['runtime_declarations'] as $declaration) {
        if ('forms' !== ($declaration['type'] ?? null)) continue;
        foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach ($entity['bindings'] ?? array() as $binding) {
            ++$bindings;
            $position = $binding['position'];
            if ($footers[0]['source_path'] !== $binding['source_path'] || $binding['search_block_markup'] !== substr($markup, $position['offset'], $position['length'])) $failures[] = 'Form bindings must address their exact block in the footer part.';
        }
    }
    if (2 !== $bindings) $failures[] = 'One form binding per device remains after route deduplication.';
}
if (array() !== $failures) throw new RuntimeException(implode("\n", $failures));
echo "device-split-footer-form: passed\n";
