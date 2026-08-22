<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityPolicy;

$blocks = array(array(
    'blockName' => 'core/group',
    'attrs' => array('className' => 'blocks-engine-source-div-a be-inline-geometry-' . str_repeat('b', 64)),
    'innerBlocks' => array(
        array('blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => ''),
        array('blockName' => 'core/paragraph', 'attrs' => array('content' => 'Editable <strong>copy</strong>'), 'innerBlocks' => array(), 'innerHTML' => '<p>Editable <strong>copy</strong></p>'),
        array('blockName' => 'core/table', 'attrs' => array('body' => array(array('cells' => array(array('content' => '<div><img src="image.jpg"></div>'))))), 'innerBlocks' => array(), 'innerHTML' => '<figure></figure>'),
    ),
    'innerHTML' => '<div></div>',
));

$report = (new EditabilityReport())->fromBlocks($blocks, 'website/index.html', str_repeat('x', 120));
$metrics = $report['metrics'];
if ('blocks-engine/php-transformer/editability-report/v2' !== EditabilityReport::SCHEMA || EditabilityReport::SCHEMA !== $report['schema'] || isset($report['enforcement'], $report['status'])) throw new RuntimeException('The unreleased v2 editability report measures facts while policy enforcement uses its own contract.');
if ('passed' !== (new EditabilityPolicy())->evaluate($report)['status']) throw new RuntimeException('The versioned editability policy must accept ordinary editable output independently of parity.');
if (4 !== $metrics['block_count'] || 2 !== $metrics['wrapper_block_count'] || 1 !== $metrics['empty_wrapper_count'] || 2 !== $metrics['max_nesting_depth']) throw new RuntimeException('Editability report must measure block-tree complexity deterministically.');
if (1 !== $metrics['html_bearing_table_cell_count'] || 1 !== $metrics['source_marker_class_count'] || 1 !== $metrics['generated_geometry_class_count'] || 120 !== $metrics['serialized_bytes']) throw new RuntimeException('Editability report must expose opaque HTML and generated-class signals.');
if (1 !== $metrics['html_bearing_attribute_count']) throw new RuntimeException('Supported RichText inline markup must not be classified as opaque structural HTML.');
if (2 !== count($report['signals']) || 'empty_wrapper' !== $report['signals'][0]['kind'] || 'html_bearing_table_cell' !== $report['signals'][1]['kind']) throw new RuntimeException('Editability signals must retain deterministic source and block attribution.');

$deepReport = (new EditabilityReport())->fromBlocks(array(array('blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(array('blockName' => 'core/image', 'attrs' => array(), 'innerBlocks' => array())))), 'website/index.html', '', '', array(), array(array('block_path' => 'blocks.0.innerBlocks.0', 'selector' => '.quote-image', 'source_fragment' => '<img src="quote.png">', 'source_path' => 'website/index.html')));
if (array('depth' => 2, 'block_path' => '0.0', 'block_name' => 'core/image', 'selector' => '.quote-image', 'source_fragment' => '<img src="quote.png">', 'source_path' => 'website/index.html') !== $deepReport['deepest_block']) throw new RuntimeException('Deepest nesting evidence resolves the exact block path and source provenance.');

$aggregate = (new EditabilityReport())->fromDocuments(array('b.html' => array('blocks' => $blocks, 'serialized_blocks' => 'bb', 'template_surface' => array('role' => 'single'), 'provenance' => array('source_path' => 'b.html')), 'a.html' => array('blocks' => array(), 'serialized_blocks' => 'a')));
if (2 !== $aggregate['metrics']['document_count'] || 3 !== $aggregate['metrics']['serialized_bytes'] || 'a.html' !== $aggregate['documents'][0]['source_path']) throw new RuntimeException('Artifact editability reports must aggregate documents in stable source order.');
if ('single' !== ($aggregate['documents'][1]['template_surface_declaration']['role'] ?? null) || 'b.html' !== ($aggregate['documents'][1]['provenance']['source_path'] ?? null)) throw new RuntimeException('Artifact editability reports retain declared template role and source provenance.');
$selected = (new EditabilityReport())->withTemplateSurfaceSelection($aggregate, array(array('source_path' => 'b.html', 'template_surface' => array('role' => 'single', 'slug' => 'single', 'source_variants' => array(array('source_path' => 'b.html', 'source_hash' => str_repeat('a', 64), 'source_provenance' => array('source_path' => 'b.html', 'source' => 'files', 'hash' => str_repeat('a', 64)))), 'declaration_provenance' => array('kind' => 'artifact_metadata'), 'source_provenance' => array('hash' => str_repeat('a', 64))))));
if ('single' !== ($selected['documents'][1]['template_surface_selection']['role'] ?? null) || 'b.html' !== ($selected['documents'][1]['template_surface_selection']['selected_source_path'] ?? null)) throw new RuntimeException('Editability evidence retains selected template role and provenance.');

$emptyGroups = array_fill(0, 101, array('blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => ''));
$bounded = (new EditabilityReport())->fromDocuments(array('large.html' => array('blocks' => $emptyGroups)));
if (101 !== $bounded['signal_totals']['observed'] || 100 !== $bounded['signal_totals']['reported'] || 1 !== $bounded['signal_totals']['omitted'] || !$bounded['signal_totals']['truncated'] || 100 !== count($bounded['signals'])) throw new RuntimeException('Editability reports must bound evidence without losing aggregate signal totals.');

$cleanDocumentBlocks = array_merge(array_fill(0, 6, array('blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '')), array(
    array('blockName' => 'core/paragraph', 'attrs' => array('content' => 'One'), 'innerBlocks' => array(), 'innerHTML' => '<p>One</p>'),
    array('blockName' => 'core/paragraph', 'attrs' => array('content' => 'Two'), 'innerBlocks' => array(), 'innerHTML' => '<p>Two</p>'),
));
$multiDocumentReport = (new EditabilityReport())->fromDocuments(array('a.html' => array('blocks' => $cleanDocumentBlocks), 'b.html' => array('blocks' => $cleanDocumentBlocks)));
$multiDocumentPolicy = (new EditabilityPolicy())->evaluate($multiDocumentReport);
if ('passed' !== $multiDocumentPolicy['status'] || 10 !== $multiDocumentPolicy['thresholds']['empty_wrapper_count'] || 12 !== ($multiDocumentReport['metrics']['empty_wrapper_count'] ?? null)) throw new RuntimeException('Each clean document passes independently even when aggregate empty wrappers exceed the per-document limit.');
$pathologicalDocumentPolicy = (new EditabilityPolicy())->evaluate((new EditabilityReport())->fromDocuments(array('clean.html' => array('blocks' => $cleanDocumentBlocks), 'bad.html' => array('blocks' => array_fill(0, 11, array('blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => ''))))));
if ('failed' !== $pathologicalDocumentPolicy['status'] || 'bad.html' !== ($pathologicalDocumentPolicy['failures'][0]['source_path'] ?? null) || 'empty_wrapper_count' !== ($pathologicalDocumentPolicy['failures'][0]['metric'] ?? null)) throw new RuntimeException('A pathological document fails with deterministic source-path attribution.');
$manyFailedDocuments = array();
for ($i = 0; $i < 101; $i++) $manyFailedDocuments[] = array('source_path' => sprintf('page-%03d.html', $i), 'metrics' => array('empty_wrapper_count' => 11));
$boundedPolicy = (new EditabilityPolicy())->evaluate(array('documents' => $manyFailedDocuments));
if ('failed' !== $boundedPolicy['status'] || 101 !== ($boundedPolicy['failure_totals']['observed'] ?? null) || 100 !== ($boundedPolicy['failure_totals']['reported'] ?? null) || 1 !== ($boundedPolicy['failure_totals']['omitted'] ?? null) || true !== ($boundedPolicy['failure_totals']['truncated'] ?? null) || 100 !== count($boundedPolicy['failures']) || 'page-099.html' !== ($boundedPolicy['failures'][99]['source_path'] ?? null)) throw new RuntimeException('Policy failure evidence is bounded with deterministic totals and source ordering.');

$structuralRichText = '<div><h3>Card title</h3><p>Card copy</p></div>';
$richTextReport = (new EditabilityReport())->fromBlocks(array(array(
    'blockName' => 'core/list-item',
    'attrs' => array('content' => $structuralRichText),
    'innerBlocks' => array(),
    'innerHTML' => '<li>' . $structuralRichText . '</li>',
)), 'standalone.html');
if (1 !== $richTextReport['metrics']['structural_rich_text_attribute_count'] || strlen($structuralRichText) !== $richTextReport['metrics']['structural_rich_text_attribute_bytes']) throw new RuntimeException('Editability reports must quantify structural HTML stored in RichText attributes.');
if ('structural_rich_text_attribute' !== ($richTextReport['signals'][0]['kind'] ?? null) || 'core/list-item' !== ($richTextReport['signals'][0]['block_name'] ?? null)) throw new RuntimeException('Structural RichText evidence must retain block attribution.');
$richTextPolicy = (new EditabilityPolicy())->evaluate($richTextReport);
if ('failed' !== $richTextPolicy['status'] || 'required' !== $richTextPolicy['enforcement'] || 'structural_rich_text_attribute_count' !== ($richTextPolicy['failures'][0]['metric'] ?? null) || 'standalone.html' !== ($richTextPolicy['failures'][0]['source_path'] ?? null)) throw new RuntimeException('Standalone reports fail the bounded meaningful-editability policy with source-path attribution.');

$intentionalEmpties = (new EditabilityReport())->fromBlocks(array(
    array('blockName' => 'core/group', 'attrs' => array('className' => 'be-inline-geometry-deadbeef'), 'innerBlocks' => array(), 'innerHTML' => ''),
    array('blockName' => 'core/group', 'attrs' => array('style' => array('color' => array('text' => '#123456'))), 'innerBlocks' => array(), 'innerHTML' => ''),
    array('blockName' => 'core/group', 'attrs' => array('style' => array('color' => array('background' => '#123456'))), 'innerBlocks' => array(), 'innerHTML' => ''),
    array('blockName' => 'core/group', 'attrs' => array('style' => array('shadow' => '0 1px 2px #000')), 'innerBlocks' => array(), 'innerHTML' => ''),
    array('blockName' => 'core/group', 'attrs' => array('className' => 'be-inline-geometry-' . str_repeat('a', 64)), 'innerBlocks' => array(), 'innerHTML' => ''),
    array('blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => ''),
    array('blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => ''),
), '', '', '.be-inline-geometry-' . str_repeat('a', 64) . '{height:1px}', array('blocks.5'));
if (3 !== $intentionalEmpties['metrics']['empty_visual_group_count'] || 1 !== $intentionalEmpties['metrics']['empty_runtime_group_count'] || 3 !== $intentionalEmpties['metrics']['empty_wrapper_count'] || 2 !== $intentionalEmpties['metrics']['generated_geometry_class_count'] || array('empty_wrapper', 'empty_wrapper', 'empty_visual_group', 'empty_visual_group', 'empty_visual_group', 'empty_runtime_group', 'empty_wrapper') !== array_column($intentionalEmpties['signals'], 'kind')) throw new RuntimeException('Text-only color, spoofed tokens, visual styles, verified carriers, and explicit runtime ownership remain distinct while geometry-prefix metrics remain compatible.');

$textOnlyPolicy = (new EditabilityPolicy())->evaluate((new EditabilityReport())->fromBlocks(array_fill(0, 11, array('blockName' => 'core/group', 'attrs' => array('style' => array('color' => array('text' => '#123456'))), 'innerBlocks' => array(), 'innerHTML' => ''))));
if ('failed' !== $textOnlyPolicy['status'] || 11 !== ($textOnlyPolicy['failures'][0]['actual'] ?? null)) throw new RuntimeException('Eleven text-only empty Groups remain policy-counted neutral wrappers.');

fwrite(STDOUT, "editability report contract passed\n");
