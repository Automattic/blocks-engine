<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$region = array(
    'id' => 'runtime-0123456789abcdef',
    'selector' => '[data-blocks-engine-capture-runtime-owner="runtime-0123456789abcdef"]',
    'script' => array('origin' => 'https://forms.example.org', 'path' => '/embed.js', 'data' => array('data-key' => 'account', 'data-form' => '1')),
    'roots' => array(array('tag' => 'form', 'nodeId' => 'root-1', 'controls' => 3)),
);
$evidence = '<script type="application/json" data-blocks-engine-capture-source-evidence="runtime-owned-regions">' . json_encode(array('schema' => 'blocks-engine-capture/runtime-owned-regions/v1', 'regions' => array($region))) . '</script>';

foreach (array(
    'marked' => array(true, $evidence, 1),
    'unmarked' => array(false, $evidence, 0),
    'unmatched' => array(true, '', 0),
    'two copies' => array(true, $evidence, 2),
) as $case => [$marked, $sourceEvidence, $handoffs]) {
    $form = '<form' . ($marked ? ' data-blocks-engine-capture-runtime-owner="' . $region['id'] . '"' : '') . '><label>Name<input type="text"></label><label>Message<textarea></textarea></label><button type="submit">Send</button></form>';
    $html = '<!doctype html><html><head><title>Contact</title>' . $sourceEvidence . '</head><body><main><h1>Contact</h1>' . str_repeat($form, max(1, $handoffs)) . '</main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $markup = $plan['pages'][0]['canonical_block_markup'] ?? '';
    $forms = array_filter($plan['runtime_declarations'] ?? array(), static fn(array $row): bool => 'generic/forms/v1' === ($row['payload']['schema'] ?? null));
    $diagnostics = array_filter($result['diagnostics'] ?? array(), static fn(array $row): bool => 'runtime_owned_region_handoff' === ($row['code'] ?? null));
    $assert($handoffs === count($diagnostics), $case . ': one diagnostic per emitted root: ' . json_encode($result['diagnostics'] ?? array()));
    if (0 === $handoffs) {
        $assert(array() !== $forms, $case . ': ordinary form declaration is unchanged');
        $assert(!str_contains($markup, 'Re-add it'), $case . ': no handoff without matching marker and evidence');
        continue;
    }
    $assert(array() === $forms, $case . ': no generic form entity');
    $unexpected = array_filter($result['diagnostics'] ?? array(), static fn(array $row): bool => in_array($row['code'] ?? null, array('html_form_fallback', 'unsupported_html_fallback', 'runtime_form_declaration_declined'), true));
    $assert(array() === $unexpected, $case . ': no form fallback or declined declaration');
    $assert($handoffs === substr_count($markup, 'forms.example.org'), $case . ': native notice names the service');
    $assert(str_contains($markup, '<!-- wp:paragraph'), $case . ': editable paragraph notice');
    $assert(!str_contains($markup, '<!-- wp:html') && !str_contains($markup, '<input') && !str_contains($markup, '<textarea'), $case . ': no fallback or fabricated controls');
    foreach ($diagnostics as $diagnostic) {
        $assert('omitted_unit' === ($diagnostic['loss_class'] ?? null) && 'accepted_reported_loss' === ($diagnostic['acceptability'] ?? null) && 'blocks-engine' === ($diagnostic['owning_layer'] ?? null), $case . ': accepted reported loss vocabulary');
        $assert($region['script'] === ($diagnostic['script'] ?? null) && $region['roots'] === ($diagnostic['roots'] ?? null) && 3 === ($diagnostic['control_count'] ?? null), $case . ': provenance retained');
    }
}

echo "runtime-owned-region-handoff: ok\n";
